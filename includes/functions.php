<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $settings = getReportSettings();
    $dueAt = getInitialDueAt(date('Y-m-d H:i:s'), $settings);

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description, due_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description, $dueAt]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量（仅未完成处置的举报）
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/* ===================== 协同指派与超时升级 ===================== */

/**
 * 获取全部管理员（指派下拉用）
 */
function getAdminList() {
    $db = getDB();
    return $db->query("SELECT id, username FROM admins ORDER BY id ASC")->fetchAll();
}

/**
 * 获取举报功能设置（阈值、升级对象），进程内缓存
 */
function getReportSettings() {
    static $settings = null;
    if ($settings !== null) return $settings;
    $db = getDB();
    $stmt = $db->query("SELECT * FROM report_settings WHERE id = 1");
    $settings = $stmt->fetch();
    if (!$settings) {
        $db->exec("INSERT IGNORE INTO report_settings (id) VALUES (1)");
        $stmt = $db->query("SELECT * FROM report_settings WHERE id = 1");
        $settings = $stmt->fetch();
    }
    return $settings;
}

/**
 * 保存举报设置
 */
function saveReportSettings($esc1Hours, $esc2Hours, $esc1AdminId, $esc2AdminId) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE report_settings
        SET escalation1_hours = ?, escalation2_hours = ?,
            escalation1_admin_id = ?, escalation2_admin_id = ?
        WHERE id = 1");
    $stmt->execute([
        max(0, intval($esc1Hours)),
        max(0, intval($esc2Hours)),
        $esc1AdminId ? intval($esc1AdminId) : null,
        $esc2AdminId ? intval($esc2AdminId) : null,
    ]);
}

/**
 * 举报操作留痕
 */
function addReportLog($reportId, $adminId, $action, $detail = '') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO report_logs (report_id, admin_id, action, detail) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        intval($reportId),
        $adminId ? intval($adminId) : null,
        $action,
        mb_substr((string)$detail, 0, 500),
    ]);
}

/**
 * 升级级别文字
 */
function getEscalationLevelLabel($level) {
    $map = [0 => '普通', 1 => '一级升级', 2 => '二级升级'];
    return $map[$level] ?? '普通';
}

/**
 * 升级级别样式类
 */
function getEscalationLevelClass($level) {
    $map = [0 => 'normal', 1 => 'esc-level1', 2 => 'esc-level2'];
    return $map[$level] ?? 'normal';
}

/**
 * 根据创建时间与设置计算初始截止时间（一级阈值）
 */
function getInitialDueAt($createdAt, $settings = null) {
    $settings = $settings ?: getReportSettings();
    $h1 = intval($settings['escalation1_hours']);
    if ($h1 <= 0) return null;
    return date('Y-m-d H:i:s', strtotime($createdAt) + $h1 * 3600);
}

/**
 * 超时升级扫描：按阈值对待处理举报逐级升级，重新指派并记录日志
 * 利用 MySQL 命名锁串行化，避免多个入口（定时任务/后台触发）重复升级
 * 返回本次升级的条数
 */
function runReportEscalations() {
    $db = getDB();
    // MySQL 命名锁串行化，避免多个入口（定时任务/后台触发）重复升级；
    // 不支持 GET_LOCK 的数据库（如 SQLite 测试环境）直接跳过锁
    $lock = null;
    try {
        $lock = $db->query("SELECT GET_LOCK('report_escalation', 2)")->fetchColumn();
    } catch (Exception $e) {
        $lock = 1;
    }
    if (!$lock) return 0;

    $count = 0;
    try {
        $settings = getReportSettings();
        $h1 = intval($settings['escalation1_hours']);
        $h2 = intval($settings['escalation2_hours']);

        $stmt = $db->query("SELECT id, created_at, escalation_level, due_at
            FROM reports
            WHERE status = 0 AND due_at IS NOT NULL
            ORDER BY due_at ASC
            LIMIT 200");
        $overdue = array_filter($stmt->fetchAll(), function ($r) {
            return strtotime($r['due_at']) <= time();
        });

        foreach ($overdue as $r) {
            $ageHours = (time() - strtotime($r['created_at'])) / 3600;
            $newLevel = intval($r['escalation_level']);
            $newDue = null;
            $newAssignee = null;

            // 逐级匹配：达到二级阈值直接升到二级，否则升到一级
            if ($h2 > 0 && $newLevel < 2 && $ageHours >= $h2) {
                $newLevel = 2;
                $newAssignee = $settings['escalation2_admin_id'];
            } elseif ($h1 > 0 && $newLevel < 1 && $ageHours >= $h1) {
                $newLevel = 1;
                $newAssignee = $settings['escalation1_admin_id'];
            } else {
                // 已到当前级别截止时间但没有更高级别阈值，停止继续升级
                continue;
            }

            if ($newLevel === 1 && $h2 > 0) {
                $newDue = date('Y-m-d H:i:s', strtotime($r['created_at']) + $h2 * 3600);
            }

            $up = $db->prepare("UPDATE reports
                SET escalation_level = ?, escalated_at = ?, due_at = ?, assigned_to = ?
                WHERE id = ? AND status = 0");
            $up->execute([$newLevel, date('Y-m-d H:i:s'), $newDue, $newAssignee, $r['id']]);

            if ($up->rowCount() > 0) {
                addReportLog($r['id'], null, 'escalate',
                    '超过处理时限，自动升级为' . getEscalationLevelLabel($newLevel) .
                    ($newAssignee ? '，已重新指派处理人' : ''));
                $count++;
            }
        }
    } finally {
        try { $db->query("SELECT RELEASE_LOCK('report_escalation')"); } catch (Exception $e) {}
    }
    return $count;
}

/**
 * 获取当前访客对某条留言的举报记录（含处理状态），无则 null
 */
function getVisitorReport($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    $report = $stmt->fetch();
    if ($report) {
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['escalation_label'] = getEscalationLevelLabel($report['escalation_level']);
    }
    return $report ?: null;
}

/**
 * 获取当前访客的全部举报（我的举报页用）
 */
function getVisitorReports() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT r.*, m.title AS message_title, m.type AS message_type
        FROM reports r
        LEFT JOIN messages m ON r.message_id = m.id
        WHERE r.visitor_id = ?
        ORDER BY r.created_at DESC");
    $stmt->execute([$visitorId]);
    return $stmt->fetchAll();
}
