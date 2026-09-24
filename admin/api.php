<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

// 认领锁有效期（秒）：超过该时间的认领视为失效，其他人可接管
define('REPORT_CLAIM_TTL', 300);

/**
 * 行锁后缀：MySQL 使用 FOR UPDATE 串行化并发处置；
 * 其他驱动（如测试用 SQLite）不支持，依赖事务内的状态条件更新保证一致
 */
function rowLockSuffix($db) {
    return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

// 后台页面访问/轮询时顺带执行超时升级扫描，保证无 cron 也能按阈值升级
if (in_array($action, ['report_list_tick', 'report_poll'])) {
    try { runReportEscalations(); } catch (Exception $e) {}
}

/**
 * 读取一组举报的最新状态快照（供前端轮询对比）
 */
function fetchReportSnapshots($db, $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) return [];
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT r.id, r.status, r.assigned_to, r.claim_by, r.claim_at,
            r.escalation_level, r.escalated_at, r.due_at, r.processed_by,
            aa.username AS assignee_name, ac.username AS claim_name,
            ap.username AS processor_name
        FROM reports r
        LEFT JOIN admins aa ON r.assigned_to = aa.id
        LEFT JOIN admins ac ON r.claim_by = ac.id
        LEFT JOIN admins ap ON r.processed_by = ap.id
        WHERE r.id IN ($place)");
    $stmt->execute($ids);
    $now = time();
    $list = $stmt->fetchAll();
    foreach ($list as &$row) {
        $row['id'] = (int)$row['id'];
        $row['status'] = (int)$row['status'];
        $row['assigned_to'] = $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null;
        $row['claim_by'] = $row['claim_by'] !== null ? (int)$row['claim_by'] : null;
        $row['escalation_level'] = (int)$row['escalation_level'];
        // 锁是否仍有效、是否超时均由 PHP 按服务器时间判定，避免数据库函数差异
        $row['claim_active'] = !empty($row['claim_by']) && $row['claim_at']
            && ($now - strtotime($row['claim_at'])) < REPORT_CLAIM_TTL;
        $row['is_overdue'] = (int)$row['status'] === 0 && !empty($row['due_at'])
            && strtotime($row['due_at']) <= $now;
    }
    unset($row);
    return $list;
}

/**
 * 举报列表全局统计（轮询返回，驱动多选待处理数与徽标实时更新）
 */
function fetchReportCounters($db) {
    return [
        'pending'    => (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn(),
        'deleted'    => (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 1")->fetchColumn(),
        'ignored'    => (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 2")->fetchColumn(),
        'rejected'   => (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 3")->fetchColumn(),
        'total'      => (int)$db->query("SELECT COUNT(*) FROM reports")->fetchColumn(),
        'escalated'  => (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 0 AND escalation_level > 0")->fetchColumn(),
    ];
}

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if ($msg && $msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (file_exists($imgFile)) unlink($imgFile);
        }
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image,
            a.username as admin_name, aa.username as assignee_name, ab.username as assigner_name, ac.username as claim_name
            FROM reports r
            LEFT JOIN messages m ON r.message_id = m.id
            LEFT JOIN admins a ON r.processed_by = a.id
            LEFT JOIN admins aa ON r.assigned_to = aa.id
            LEFT JOIN admins ab ON r.assigned_by = ab.id
            LEFT JOIN admins ac ON r.claim_by = ac.id
            WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        // 备注按原始内容存储，输出时统一转义，保证后台与前台展示一致
        $report['process_note_raw'] = (string)($report['process_note'] ?? '');
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';
        $report['assignee_name'] = $report['assignee_name'] ? cleanInput($report['assignee_name']) : '';
        $report['assigner_name'] = $report['assigner_name'] ? cleanInput($report['assigner_name']) : '';
        $report['claim_name'] = $report['claim_name'] ? cleanInput($report['claim_name']) : '';
        $report['escalation_label'] = getEscalationLevelLabel($report['escalation_level']);

        // 认领是否仍有效（未超时）
        $report['claim_active'] = false;
        if ($report['claim_by'] && $report['claim_at']) {
            $report['claim_active'] = (time() - strtotime($report['claim_at'])) < REPORT_CLAIM_TTL;
        }
        $report['claim_by_me'] = $report['claim_active'] && $report['claim_by'] == $_SESSION['admin_id'];
        $report['settings'] = getReportSettings();

        // 操作日志时间线
        $logStmt = $db->prepare("SELECT l.*, a.username AS admin_name
            FROM report_logs l LEFT JOIN admins a ON l.admin_id = a.id
            WHERE l.report_id = ? ORDER BY l.id ASC");
        $logStmt->execute([$id]);
        $report['logs'] = $logStmt->fetchAll();

        jsonResponse(0, 'ok', $report);
        break;

    /**
     * 认领（加锁）：开始处置前调用，防止两人同时处理
     */
    case 'report_claim':
        $id = intval($_POST['id'] ?? 0);
        $adminId = $_SESSION['admin_id'];
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?" . rowLockSuffix($db));
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) { $db->rollBack(); jsonResponse(1, '举报不存在'); }
            if ($report['status'] != 0) {
                $db->rollBack();
                jsonResponse(2, '该举报已被处理（' . getReportStatusLabel($report['status']) . '）', ['status' => (int)$report['status']]);
            }
            $claimValid = $report['claim_by'] && $report['claim_at']
                && (time() - strtotime($report['claim_at'])) < REPORT_CLAIM_TTL;
            if ($claimValid && $report['claim_by'] != $adminId) {
                $name = $db->prepare("SELECT username FROM admins WHERE id = ?");
                $name->execute([$report['claim_by']]);
                $who = $name->fetchColumn();
                $db->rollBack();
                jsonResponse(3, '举报正由「' . $who . '」处理中，请稍候或等待其锁定超时', [
                    'claim_by' => (int)$report['claim_by'],
                    'claim_name' => $who,
                ]);
            }
            // 本人重复认领或接管过期锁
            $db->prepare("UPDATE reports SET claim_by = ?, claim_at = ? WHERE id = ?")
                ->execute([$adminId, date('Y-m-d H:i:s'), $id]);
            addReportLog($id, $adminId, 'claim', '开始处理（锁定）');
            $db->commit();
            jsonResponse(0, 'ok', ['claim_by' => (int)$adminId, 'claim_name' => $_SESSION['admin_name']]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    /**
     * 释放认领（取消处置）
     */
    case 'report_release':
        $id = intval($_POST['id'] ?? 0);
        $db->prepare("UPDATE reports SET claim_by = NULL, claim_at = NULL
            WHERE id = ? AND status = 0 AND claim_by = ?")
            ->execute([$id, $_SESSION['admin_id']]);
        addReportLog($id, $_SESSION['admin_id'], 'release', '取消处理（解锁）');
        jsonResponse(0, 'ok');
        break;

    /**
     * 协同指派：支持一次把不同举报指派给不同处理人
     * 参数: assignments[id] = adminId（0/空表示取消指派）
     */
    case 'report_assign':
        $assignments = $_POST['assignments'] ?? [];
        $note = trim($_POST['note'] ?? '');
        if (!is_array($assignments) || empty($assignments)) jsonResponse(1, '未选择举报');

        $validAdmins = array_column(getAdminList(), 'id');
        $success = 0;
        $skipped = 0;
        $db->beginTransaction();
        try {
            $sel = $db->prepare("SELECT id, status FROM reports WHERE id = ?" . rowLockSuffix($db));
            $upd = $db->prepare("UPDATE reports
                SET assigned_to = ?, assigned_by = ?, assigned_at = ?
                WHERE id = ? AND status = 0");
            foreach ($assignments as $rid => $adminId) {
                $rid = intval($rid);
                $adminId = intval($adminId);
                if ($rid <= 0) continue;
                $sel->execute([$rid]);
                $report = $sel->fetch();
                if (!$report || $report['status'] != 0) { $skipped++; continue; }
                if ($adminId > 0 && !in_array($adminId, $validAdmins)) { $skipped++; continue; }
                $upd->execute([$adminId > 0 ? $adminId : null, $_SESSION['admin_id'], date('Y-m-d H:i:s'), $rid]);
                if ($adminId > 0) {
                    $n = $db->prepare("SELECT username FROM admins WHERE id = ?");
                    $n->execute([$adminId]);
                    $targetName = $n->fetchColumn();
                    addReportLog($rid, $_SESSION['admin_id'], 'assign',
                        '指派给「' . $targetName . '」' . ($note !== '' ? '：' . mb_substr($note, 0, 200) : ''));
                } else {
                    addReportLog($rid, $_SESSION['admin_id'], 'assign', '取消指派');
                }
                $success++;
            }
            $db->commit();
            jsonResponse(0, "指派完成：成功 {$success} 条" . ($skipped ? "，跳过 {$skipped} 条（已处理）" : ''), [
                'success' => $success, 'skipped' => $skipped, 'counters' => fetchReportCounters($db),
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '指派失败: ' . $e->getMessage());
        }
        break;

    /**
     * 批量处置：整组预览后逐条提交
     * 参数: items[id] = ['status'=>1|2|3, 'note'=>'...']
     * 每条仍以 FOR UPDATE + status=0 判定，保证并发下只有一次生效
     */
    case 'process_report_batch':
        $items = $_POST['items'] ?? [];
        if (!is_array($items) || empty($items)) jsonResponse(1, '没有待处置的举报');

        $results = [];
        $messageIdsToDelete = [];

        $db->beginTransaction();
        try {
            $sel = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0" . rowLockSuffix($db));
            $upd = $db->prepare("UPDATE reports
                SET status = ?, processed_by = ?, processed_at = ?, process_note = ?,
                    claim_by = NULL, claim_at = NULL
                WHERE id = ?");

            foreach ($items as $rid => $item) {
                $rid = intval($rid);
                $newStatus = intval($item['status'] ?? 0);
                $note = mb_substr(trim($item['note'] ?? ''), 0, 500);
                if ($rid <= 0 || !in_array($newStatus, [1, 2, 3])) {
                    $results[$rid] = ['ok' => false, 'msg' => '参数无效'];
                    continue;
                }
                $sel->execute([$rid]);
                $report = $sel->fetch();
                if (!$report) {
                    $results[$rid] = ['ok' => false, 'msg' => '不存在或已被处理', 'code' => 2];
                    continue;
                }
                // 其他人持有效锁时跳过（本人锁可正常提交）
                $claimValid = $report['claim_by'] && $report['claim_at']
                    && (time() - strtotime($report['claim_at'])) < REPORT_CLAIM_TTL;
                if ($claimValid && $report['claim_by'] != $_SESSION['admin_id']) {
                    $n = $db->prepare("SELECT username FROM admins WHERE id = ?");
                    $n->execute([$report['claim_by']]);
                    $results[$rid] = ['ok' => false, 'code' => 3, 'msg' => '正由「' . $n->fetchColumn() . '」处理中'];
                    continue;
                }

                if ($newStatus === 1 && $report['message_id']) {
                    $messageIdsToDelete[] = (int)$report['message_id'];
                }
                $upd->execute([$newStatus, $_SESSION['admin_id'], date('Y-m-d H:i:s'), $note, $rid]);
                $statusText = [1 => '删除留言', 2 => '忽略举报', 3 => '驳回举报'][$newStatus];
                addReportLog($rid, $_SESSION['admin_id'], 'process',
                    '批量处置：' . $statusText . ($note !== '' ? '，备注：' . mb_substr($note, 0, 200) : ''));
                $results[$rid] = ['ok' => true, 'status' => $newStatus];
            }

            // 删除留言（外键为 SET NULL，举报记录及处理备注保留）；合并去重避免重复删图
            foreach (array_unique($messageIdsToDelete) as $mid) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$mid]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) @unlink($imgFile);
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$mid]);
            }

            $db->commit();
            $okCount = count(array_filter($results, function ($r) { return !empty($r['ok']); }));
            $failCount = count($results) - $okCount;
            jsonResponse(0, "处置完成：成功 {$okCount} 条" . ($failCount ? "，失败 {$failCount} 条" : ''), [
                'results' => $results,
                'counters' => fetchReportCounters($db),
            ]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '批量处置失败: ' . $e->getMessage());
        }
        break;

    /**
     * 轮询：返回关注举报的最新状态与全局计数，协同人可实时看到状态变化
     * 参数: ids=1,2,3
     */
    case 'report_poll':
        $ids = array_filter(explode(',', $_GET['ids'] ?? ''), function ($v) {
            return $v !== '' && intval($v) > 0;
        });
        jsonResponse(0, 'ok', [
            'items' => fetchReportSnapshots($db, $ids),
            'counters' => fetchReportCounters($db),
            'now' => date('Y-m-d H:i:s'),
            'claim_ttl' => REPORT_CLAIM_TTL,
        ]);
        break;

    /**
     * 举报设置：读取/保存升级阈值与升级对象
     */
    case 'report_settings_get':
        $settings = getReportSettings();
        jsonResponse(0, 'ok', ['settings' => $settings, 'admins' => getAdminList()]);
        break;

    case 'report_settings_save':
        $h1 = intval($_POST['escalation1_hours'] ?? 24);
        $h2 = intval($_POST['escalation2_hours'] ?? 72);
        $a1 = intval($_POST['escalation1_admin_id'] ?? 0);
        $a2 = intval($_POST['escalation2_admin_id'] ?? 0);
        if ($h1 < 0 || $h2 < 0) jsonResponse(1, '阈值必须为非负整数（0 表示不启用）');
        if ($h1 > 0 && $h2 > 0 && $h2 <= $h1) jsonResponse(1, '二级阈值必须大于一级阈值');
        saveReportSettings($h1, $h2, $a1, $a2);
        jsonResponse(0, '设置已保存');
        break;

    /**
     * 手动触发超时升级扫描（定时任务未配置时可用）
     */
    case 'report_run_escalation':
        $n = runReportEscalations();
        jsonResponse(0, "升级扫描完成，本次升级 {$n} 条", ['escalated' => $n]);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        // 备注存原始内容，输出时统一转义，保证处理备注前后台一致
        $note = mb_substr(trim($_POST['note'] ?? ''), 0, 500);

        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            // FOR UPDATE + status=0 判定：两人同时提交时只有一人能生效
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0" . rowLockSuffix($db));
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) {
                $cur = $db->prepare("SELECT status, processed_by FROM reports WHERE id = ?");
                $cur->execute([$id]);
                $row = $cur->fetch();
                if ($row) {
                    $db->rollBack();
                    jsonResponse(2, '该举报已被其他管理员处理（' . getReportStatusLabel($row['status']) . '），列表状态已更新', [
                        'status' => (int)$row['status'],
                    ]);
                }
                $db->rollBack();
                jsonResponse(1, '举报不存在或已处理');
            }

            // 其他人持有效认领锁时拒绝
            $claimValid = $report['claim_by'] && $report['claim_at']
                && (time() - strtotime($report['claim_at'])) < REPORT_CLAIM_TTL;
            if ($claimValid && $report['claim_by'] != $_SESSION['admin_id']) {
                $n = $db->prepare("SELECT username FROM admins WHERE id = ?");
                $n->execute([$report['claim_by']]);
                $db->rollBack();
                jsonResponse(3, '举报正由「' . $n->fetchColumn() . '」处理中，请稍后再试');
            }

            if ($status === 1 && $report['message_id']) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) @unlink($imgFile);
                }
                // SET NULL 外键：留言删除后举报记录、删除结果与备注仍保留
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = ?,
                process_note = ?, claim_by = NULL, claim_at = NULL WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], date('Y-m-d H:i:s'), $note, $id]);

            $statusText = [1 => '删除留言', 2 => '忽略举报', 3 => '驳回举报'][$status];
            addReportLog($id, $_SESSION['admin_id'], 'process',
                $statusText . ($note !== '' ? '，备注：' . mb_substr($note, 0, 200) : ''));

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功', ['counters' => fetchReportCounters($db)]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    default:
        jsonResponse(1, '未知操作');
}
