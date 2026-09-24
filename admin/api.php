<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
$adminId = (int)$_SESSION['admin_id'];

// 任何后台请求都顺带做一次超时升级扫描（带节流），也可由 CLI 定时执行
// 迁移未执行时静默跳过，不影响原有删除/忽略/驳回之外的基础动作
try {
    $colCheck = $db->query("SHOW COLUMNS FROM reports LIKE 'assignee_id'")->fetch();
    if ($colCheck) {
        runReportEscalations(false);
    }
} catch (Exception $e) {
    // 升级扫描失败不影响正常操作
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

    case 'admin_list':
        jsonResponse(0, 'ok', getAdmins($db));
        break;

    case 'report_stats':
        jsonResponse(0, 'ok', getReportStats($db));
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $report = fetchReportDetail($db, $id);
        if (!$report) jsonResponse(1, '举报不存在');
        jsonResponse(0, 'ok', formatReportDetail($db, $report, $adminId));
        break;

    case 'report_logs':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare(
            "SELECT l.*, a.username as admin_name FROM report_logs l
             LEFT JOIN admins a ON l.admin_id = a.id
             WHERE l.report_id = ? ORDER BY l.id DESC LIMIT 30"
        );
        $stmt->execute([$id]);
        $logs = $stmt->fetchAll();
        foreach ($logs as &$l) {
            $l['admin_name'] = $l['admin_name'] ? cleanInput($l['admin_name']) : '系统';
            $l['detail'] = cleanInput($l['detail']);
            $l['action_label'] = [
                'assign' => '协同指派', 'escalate' => '超时升级',
                'process' => '处置', 'lock' => '加锁', 'unlock' => '释放锁'
            ][$l['action']] ?? $l['action'];
        }
        jsonResponse(0, 'ok', $logs);
        break;

    case 'acquire_lock':
        $id = intval($_POST['id'] ?? 0);
        try {
            acquireReportLock($db, $id, $adminId);
            logReportAction($db, $id, $adminId, 'lock', '开始处理');
            $row = $db->prepare(
                "SELECT r.id, r.status, r.locked_by, r.locked_at, r.priority,
                        r.processed_by, r.processed_at, r.assignee_id,
                        p.username as processed_name, l.username as locked_name,
                        aa.username as assignee_name
                 FROM reports r
                 LEFT JOIN admins p ON r.processed_by = p.id
                 LEFT JOIN admins l ON r.locked_by = l.id
                 LEFT JOIN admins aa ON r.assignee_id = aa.id
                 WHERE r.id = ?"
            );
            $row->execute([$id]);
            $r = $row->fetch();
            $state = [
                'id' => (int)$r['id'],
                'status' => (int)$r['status'],
                'status_label' => getReportStatusLabel($r['status']),
                'priority' => (int)$r['priority'],
                'priority_label' => getReportPriorityLabel($r['priority']),
                'lock_valid' => true,
                'locked_by' => (int)$r['locked_by'],
                'locked_by_me' => (int)$r['locked_by'] === $adminId,
                'locked_by_name' => cleanInput($r['locked_name']),
                'processed_name' => $r['processed_name'] ? cleanInput($r['processed_name']) : '',
                'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : 0,
                'assignee_name' => $r['assignee_name'] ? cleanInput($r['assignee_name']) : '未指派',
                'processed_at' => $r['processed_at'],
            ];
            jsonResponse(0, '已占用处理', ['state' => $state]);
        } catch (Exception $e) {
            jsonResponse(1, $e->getMessage());
        }
        break;

    case 'release_lock':
        $id = intval($_POST['id'] ?? 0);
        releaseReportLock($db, $id, $adminId);
        jsonResponse(0, 'ok');
        break;

    /**
     * 单条处置：走统一的 processReport()
     * 行锁 + status=0 条件保证两人同时处理只有一人生效
     */
    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理备注不能超过500字');

        try {
            processReport($db, $id, $status, $note, $adminId);
            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功', ['stats' => getReportStats($db)]);
        } catch (Exception $e) {
            jsonResponse(1, $e->getMessage());
        }
        break;

    /**
     * 批量指派：每条可以指定不同处理人
     * POST items: JSON [{"report_id":1,"admin_id":2}, ...]
     */
    case 'assign_reports':
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || empty($items)) jsonResponse(1, '请选择要指派的举报');
        if (count($items) > 100) jsonResponse(1, '单次最多指派100条');

        try {
            $res = assignReports($db, $items, $adminId);
            $msg = "成功指派 {$res['assigned']} 条";
            if ($res['skipped'] > 0) $msg .= "，{$res['skipped']} 条因已处理跳过";
            jsonResponse(0, $msg, ['stats' => getReportStats($db)]);
        } catch (Exception $e) {
            jsonResponse(1, $e->getMessage());
        }
        break;

    /**
     * 批量处置前逐条预览：返回选中举报的完整信息
     * POST ids: JSON [1,2,3]
     */
    case 'batch_preview':
        $ids = parseIdList($_POST['ids'] ?? '[]');
        if (empty($ids)) jsonResponse(1, '请选择举报');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT r.*, m.id as message_id_found, m.title as message_title, m.nickname as message_nickname,
                    m.type as message_type, m.content as message_content,
                    m.image as message_image,
                    aa.username as assignee_name
             FROM reports r
             LEFT JOIN messages m ON r.message_id = m.id
             LEFT JOIN admins aa ON r.assignee_id = aa.id
             WHERE r.id IN ($placeholders) ORDER BY r.priority DESC, r.created_at ASC"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $list = array_map(function ($r) use ($adminId, $db) {
            return [
                'id' => (int)$r['id'],
                'status' => (int)$r['status'],
                'status_label' => getReportStatusLabel($r['status']),
                'priority' => (int)$r['priority'],
                'priority_label' => getReportPriorityLabel($r['priority']),
                'report_type' => $r['report_type'],
                'report_type_label' => getReportTypeLabel($r['report_type']),
                'description' => $r['description'] ? cleanInput($r['description']) : '',
                'created_at' => $r['created_at'],
                'deadline' => $r['deadline'],
                'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : 0,
                'assignee_name' => $r['assignee_name'] ? cleanInput($r['assignee_name']) : '未指派',
                'message_exists' => !empty($r['message_id_found']),
                'message_title' => $r['message_title'] ? cleanInput($r['message_title']) : '',
                'message_nickname' => $r['message_nickname'] ? cleanInput($r['message_nickname']) : '',
                'message_type_label' => $r['message_type'] ? getTypeLabel($r['message_type']) : '',
                'message_content' => $r['message_content'] ? nl2br(cleanInput($r['message_content'])) : '',
                'message_image' => $r['message_image'],
                'locked_by_me' => (int)$r['locked_by'] === $adminId,
                'lock_valid' => isReportLockValid($r),
                'locked_by_name' => $r['locked_by'] ? cleanInput(getAdminName($db, $r['locked_by'])) : '',
            ];
        }, $rows);

        jsonResponse(0, 'ok', ['list' => $list]);
        break;

    /**
     * 整组处置：逐条走统一处理入口，独立事务
     * POST items: JSON [{"id":1,"action":1,"note":"..."}, ...]
     * 返回每条结果，前端逐条更新
     */
    case 'batch_process':
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!is_array($items) || empty($items)) jsonResponse(1, '没有可处置的条目');
        if (count($items) > 100) jsonResponse(1, '单次最多处置100条');

        $results = [];
        $success = 0;
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $st = (int)($item['action'] ?? 0);
            $note = mb_substr(trim($item['note'] ?? ''), 0, 500);

            if ($id <= 0) continue;
            if (!in_array($st, [1, 2, 3], true)) {
                $results[] = ['id' => $id, 'ok' => false, 'msg' => '未选择处置动作'];
                continue;
            }

            // 尊重单人编辑锁：他人持有的有效锁不允许整组处置，避免互相覆盖
            $lockStmt = $db->prepare("SELECT locked_by, locked_at FROM reports WHERE id = ?");
            $lockStmt->execute([$id]);
            $lockRow = $lockStmt->fetch();
            if ($lockRow && isReportLockValid($lockRow) && (int)$lockRow['locked_by'] !== $adminId) {
                $holder = cleanInput(getAdminName($db, $lockRow['locked_by']));
                $results[] = ['id' => $id, 'ok' => false, 'msg' => "已跳过：{$holder} 正在处理"];
                continue;
            }

            try {
                processReport($db, $id, $st, $note, $adminId);
                $results[] = ['id' => $id, 'ok' => true, 'status' => $st,
                              'msg' => [1 => '已删除', 2 => '已忽略', 3 => '已驳回'][$st]];
                $success++;
            } catch (Exception $e) {
                $results[] = ['id' => $id, 'ok' => false, 'msg' => $e->getMessage()];
            }
        }

        jsonResponse(0, "处置完成，成功 {$success} / " . count($items) . ' 条', [
            'results' => $results,
            'stats' => getReportStats($db),
        ]);
        break;

    /**
     * 列表页轮询：返回给定 ID 集合的最新状态，协同处理时其他人能即时看到变化
     * GET ids=1,2,3
     */
    case 'report_states':
        $ids = parseIdList($_GET['ids'] ?? '');
        if (empty($ids)) jsonResponse(0, 'ok', ['list' => [], 'stats' => getReportStats($db)]);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT r.id, r.status, r.locked_by, r.locked_at, r.priority,
                    r.processed_by, r.processed_at, r.assignee_id,
                    p.username as processed_name, l.username as locked_name,
                    aa.username as assignee_name
             FROM reports r
             LEFT JOIN admins p ON r.processed_by = p.id
             LEFT JOIN admins l ON r.locked_by = l.id
             LEFT JOIN admins aa ON r.assignee_id = aa.id
             WHERE r.id IN ($placeholders)"
        );
        $stmt->execute($ids);
        $list = array_map(function ($r) use ($adminId) {
            return [
                'id' => (int)$r['id'],
                'status' => (int)$r['status'],
                'status_label' => getReportStatusLabel($r['status']),
                'priority' => (int)$r['priority'],
                'priority_label' => getReportPriorityLabel($r['priority']),
                'lock_valid' => isReportLockValid($r),
                'locked_by' => $r['locked_by'] ? (int)$r['locked_by'] : 0,
                'locked_by_me' => isReportLockValid($r) && (int)$r['locked_by'] === $adminId,
                'locked_by_name' => $r['locked_name'] ? cleanInput($r['locked_name']) : '',
                'processed_name' => $r['processed_name'] ? cleanInput($r['processed_name']) : '',
                'assignee_id' => $r['assignee_id'] ? (int)$r['assignee_id'] : 0,
                'assignee_name' => $r['assignee_name'] ? cleanInput($r['assignee_name']) : '未指派',
                'processed_at' => $r['processed_at'],
            ];
        }, $stmt->fetchAll());

        jsonResponse(0, 'ok', ['list' => $list, 'stats' => getReportStats($db)]);
        break;

    default:
        jsonResponse(1, '未知操作');
}

/**
 * 解析 JSON 数组或逗号分隔的 ID 列表
 */
function parseIdList($raw) {
    $ids = [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $ids = $decoded;
    } else {
        foreach (explode(',', $raw) as $v) {
            if (trim($v) !== '') $ids[] = trim($v);
        }
    }
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function ($v) { return $v > 0; });
    $ids = array_values(array_unique($ids));
    return array_slice($ids, 0, 100);
}

function fetchReportDetail($db, $id) {
    $stmt = $db->prepare(
        "SELECT r.*, m.id as message_id_found, m.title as message_title, m.nickname as message_nickname,
                m.type as message_type, m.content as message_content, m.image as message_image,
                a.username as admin_name, aa.username as assignee_name,
                l.username as locked_name
         FROM reports r
         LEFT JOIN messages m ON r.message_id = m.id
         LEFT JOIN admins a ON r.processed_by = a.id
         LEFT JOIN admins aa ON r.assignee_id = aa.id
         LEFT JOIN admins l ON r.locked_by = l.id
         WHERE r.id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function formatReportDetail($db, $report, $currentAdminId) {
    $report['report_type_label'] = getReportTypeLabel($report['report_type']);
    $report['status_label'] = getReportStatusLabel($report['status']);
    $report['status_class'] = getReportStatusClass($report['status']);
    $report['priority_label'] = getReportPriorityLabel($report['priority']);
    $report['priority_class'] = getReportPriorityClass($report['priority']);
    $report['message_exists'] = !empty($report['message_id_found']);
    $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
    $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
    $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
    $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
    $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
    $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
    $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';
    $report['assignee_name'] = $report['assignee_name'] ? cleanInput($report['assignee_name']) : '未指派';
    $report['locked_by_name'] = $report['locked_name'] ? cleanInput($report['locked_name']) : '';
    $report['lock_valid'] = isReportLockValid($report);
    $report['locked_by_me'] = isReportLockValid($report) && (int)$report['locked_by'] === (int)$currentAdminId;
    return $report;
}
