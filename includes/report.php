<?php
/**
 * 举报协同处置核心逻辑
 * 处理备注、删除留言、举报状态在同一事务内提交，保证三者一致
 */

function reportConfig() {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/report.php';
    }
    return $config;
}

/**
 * 举报操作日志
 */
function logReportAction($db, $reportId, $adminId, $action, $detail = '') {
    $stmt = $db->prepare(
        "INSERT INTO report_logs (report_id, admin_id, action, detail) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$reportId, $adminId, $action, mb_substr((string)$detail, 0, 500)]);
}

/**
 * 按当前优先级计算处理截止时间
 */
function reportDeadline($baseDatetime, $priority) {
    $config = reportConfig();
    $hours = $config['escalation_thresholds'][$priority] ?? null;
    if ($hours === null) return null; // 已是最高级别，不再升级
    $base = $baseDatetime instanceof DateTime ? $baseDatetime : new DateTime($baseDatetime);
    $base->modify("+{$hours} hours");
    return $base->format('Y-m-d H:i:s');
}

/**
 * 超时升级扫描（懒触发或 CLI 调用）
 * 超过 deadline 的待处理举报逐级 +1，重算下一级截止时间
 * 返回本次升级的条数
 */
function runReportEscalations($force = false) {
    $db = getDB();
    $config = reportConfig();

    // 懒触发节流：用文件标记记录上次扫描时间，避免每次请求都扫表
    if (!$force) {
        $marker = sys_get_temp_dir() . '/community_board_escalation_scan.lock';
        if (is_file($marker) && (time() - (int)file_get_contents($marker)) < $config['lazy_scan_interval']) {
            return 0;
        }
        @file_put_contents($marker, (string)time());
    }

    $maxPriority = max(array_keys($config['escalation_thresholds']));

    // 取出所有已超时、仍可升级的待处理举报
    $stmt = $db->prepare(
        "SELECT id, priority, deadline FROM reports
         WHERE status = 0 AND deadline IS NOT NULL AND deadline < NOW() AND priority < ?
         ORDER BY deadline ASC LIMIT 500"
    );
    $stmt->execute([$maxPriority]);
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    $count = 0;
    foreach ($rows as $row) {
        $newPriority = $row['priority'] + 1;
        $now = new DateTime();
        $newDeadline = reportDeadline($now->format('Y-m-d H:i:s'), $newPriority);

        $u = $db->prepare(
            "UPDATE reports SET priority = ?, escalated_at = NOW(), deadline = ?
             WHERE id = ? AND status = 0 AND priority = ?"
        );
        $u->execute([$newPriority, $newDeadline, $row['id'], $row['priority']]);
        if ($u->rowCount() > 0) {
            logReportAction(
                $db, $row['id'], null, 'escalate',
                "超时自动升级至 {$newPriority} 级（原 {$row['priority']} 级），新截止时间：" . ($newDeadline ?? '无')
            );
            $count++;
        }
    }
    return $count;
}

/**
 * 判断锁是否仍有效
 */
function isReportLockValid($report) {
    if (empty($report['locked_by']) || empty($report['locked_at'])) return false;
    $ttl = reportConfig()['lock_ttl'];
    return (time() - strtotime($report['locked_at'])) < $ttl;
}

/**
 * 获取处理锁。锁被他人持有时抛出异常
 */
function acquireReportLock($db, $reportId, $adminId) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? FOR UPDATE");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        if (!$report) {
            $db->rollBack();
            throw new Exception('举报不存在');
        }
        if ($report['status'] != 0) {
            $db->rollBack();
            throw new Exception('举报已被处理');
        }
        if (isReportLockValid($report) && $report['locked_by'] != $adminId) {
            $db->rollBack();
            $holder = getAdminName($db, $report['locked_by']);
            throw new Exception("举报正由 {$holder} 处理中，请稍后刷新查看");
        }

        $db->prepare("UPDATE reports SET locked_by = ?, locked_at = NOW() WHERE id = ?")
            ->execute([$adminId, $reportId]);
        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * 释放自己的处理锁
 */
function releaseReportLock($db, $reportId, $adminId) {
    $db->prepare("UPDATE reports SET locked_by = NULL, locked_at = NULL WHERE id = ? AND locked_by = ?")
        ->execute([$reportId, $adminId]);
}

function getAdminName($db, $adminId) {
    if (!$adminId) return '';
    $stmt = $db->prepare("SELECT username FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    return $stmt->fetchColumn() ?: '';
}

/**
 * 单条举报处置（删除/忽略/驳回共用的唯一入口）
 *
 * 一致性保证：留言删除（仅 status=1）、举报状态、处理人、处理时间、备注
 * 全部在同一个事务内提交；任何一步失败全部回滚。
 * 并发保证：SELECT ... FOR UPDATE 且要求 status=0，两人同时提交只有一人成功。
 *
 * @return array 处置后的最新行
 */
function processReport($db, $reportId, $status, $note, $adminId) {
    if (!in_array($status, [1, 2, 3], true)) {
        throw new Exception('无效的处理状态');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        if (!$report) {
            // 区分“不存在”和“已被他人处理”，方便协同方看到状态变化
            $cur = $db->prepare("SELECT status FROM reports WHERE id = ?");
            $cur->execute([$reportId]);
            $row = $cur->fetch();
            if ($row) {
                throw new Exception('举报已被其他管理员处理（当前状态：' . getReportStatusLabel($row['status']) . '），列表已自动刷新');
            }
            throw new Exception('举报不存在');
        }

        if ($status === 1) {
            // 先删图片文件，再删留言；文件删除失败不阻断，数据库为准
            $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
            $stmt->execute([$report['message_id']]);
            $msg = $stmt->fetch();
            if ($msg && $msg['image']) {
                $imgFile = __DIR__ . '/../' . $msg['image'];
                if (file_exists($imgFile)) @unlink($imgFile);
            }
            $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
        }

        // 举报状态 + 备注 + 处理结果一次写入，处理人即实际生效的人
        $db->prepare(
            "UPDATE reports
             SET status = ?, processed_by = ?, processed_at = NOW(),
                 process_note = ?, locked_by = NULL, locked_at = NULL
             WHERE id = ?"
        )->execute([$status, $adminId, $note, $reportId]);

        $actionText = [1 => '删除留言', 2 => '忽略举报', 3 => '驳回举报'][$status];
        logReportAction(
            $db, $reportId, $adminId, 'process',
            $actionText . ($note !== '' ? "；备注：{$note}" : '')
        );

        $db->commit();

        $report['status'] = $status;
        $report['process_note'] = $note;
        return $report;
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * 批量指派：同一批可以分给不同处理人
 * $assignments = [['report_id' => int, 'admin_id' => int], ...]
 * 只允许指派待处理举报；deadline 以指派时间重新起算
 */
function assignReports($db, array $assignments, $operatorId) {
    $validAdminIds = array_column(getAdmins($db), 'id');
    $ok = 0;
    $skip = 0;

    foreach ($assignments as $a) {
        $reportId = (int)($a['report_id'] ?? 0);
        $adminId = (int)($a['admin_id'] ?? 0);
        if ($reportId <= 0) { $skip++; continue; }
        if ($adminId > 0 && !in_array($adminId, $validAdminIds, true)) {
            throw new Exception("举报 #{$reportId} 的指派人不存在");
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT status, priority FROM reports WHERE id = ? FOR UPDATE");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();
            if (!$report) { $db->rollBack(); $skip++; continue; }
            if ($report['status'] != 0) { $db->rollBack(); $skip++; continue; }

            if ($adminId > 0) {
                // 指派后 SLA 以指派时间重新起算，优先级保持不变
                $deadline = reportDeadline(date('Y-m-d H:i:s'), (int)$report['priority']);
                $db->prepare(
                    "UPDATE reports SET assignee_id = ?, assigned_at = NOW(), deadline = ? WHERE id = ?"
                )->execute([$adminId, $deadline, $reportId]);
            } else {
                $db->prepare(
                    "UPDATE reports SET assignee_id = NULL WHERE id = ?"
                )->execute([$reportId]);
            }

            $name = $adminId > 0 ? getAdminName($db, $adminId) : '';
            logReportAction(
                $db, $reportId, $operatorId, 'assign',
                $adminId > 0 ? "指派给：{$name}" : '取消指派'
            );
            $db->commit();
            $ok++;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $skip++;
        }
    }
    return ['assigned' => $ok, 'skipped' => $skip];
}

/**
 * 管理员列表（指派下拉用）
 */
function getAdmins($db) {
    return $db->query("SELECT id, username FROM admins ORDER BY id ASC")->fetchAll();
}

/**
 * 举报统计卡片 + 侧栏待处理数量统一口径
 */
function getReportStats($db) {
    $row = $db->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS deleted,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS ignored,
            SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN status = 0 AND priority > 0 THEN 1 ELSE 0 END) AS escalated
         FROM reports"
    )->fetch();
    return [
        'total' => (int)$row['total'],
        'pending' => (int)$row['pending'],
        'deleted' => (int)$row['deleted'],
        'ignored' => (int)$row['ignored'],
        'rejected' => (int)$row['rejected'],
        'escalated' => (int)$row['escalated'],
    ];
}

/**
 * 优先级文字 / 样式
 */
function getReportPriorityLabel($priority) {
    $map = [0 => '普通', 1 => '一级升级', 2 => '二级升级', 3 => '三级升级'];
    return $map[$priority] ?? '普通';
}

function getReportPriorityClass($priority) {
    $map = [0 => 'normal', 1 => 'level1', 2 => 'level2', 3 => 'level3'];
    return $map[$priority] ?? 'normal';
}
