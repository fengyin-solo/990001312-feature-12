<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$action = $_POST['action'] ?? 'submit';
$messageId = intval($_POST['message_id'] ?? 0);

if ($messageId <= 0) {
    jsonResponse(1, '无效的留言ID');
}

$db = getDB();

/**
 * 前台看到的举报结果与后台处置保持同一口径：
 * 状态1（删除）-> 已采纳，留言已删除；状态2 -> 已忽略；状态3 -> 已驳回
 */
function getVisitorReportView($db, $messageId) {
    $visitorId = getVisitorId();
    $stmt = $db->prepare(
        "SELECT status, process_note, processed_at FROM reports
         WHERE visitor_id = ? AND message_id = ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$visitorId, $messageId]);
    $report = $stmt->fetch();
    if (!$report) {
        return ['reported' => false, 'status' => null, 'status_text' => '', 'note' => ''];
    }

    $textMap = [
        0 => '已举报，等待处理',
        1 => '举报已采纳，留言已删除',
        2 => '举报已忽略',
        3 => '举报已驳回',
    ];
    return [
        'reported' => true,
        'status' => (int)$report['status'],
        'status_text' => $textMap[(int)$report['status']] ?? '已举报',
        'note' => $report['process_note'] ?? '',
        'processed_at' => $report['processed_at'],
    ];
}

try {
    if ($action === 'check') {
        jsonResponse(0, '查询成功', getVisitorReportView($db, $messageId));
    } elseif ($action === 'submit') {
        $reportType = cleanInput($_POST['report_type'] ?? '');
        $description = cleanInput($_POST['description'] ?? '');

        if (empty($reportType)) {
            jsonResponse(1, '请选择举报类型');
        }

        if (mb_strlen($description) > 500) {
            jsonResponse(1, '补充说明不能超过500字');
        }

        $reportId = submitReport($messageId, $reportType, $description);
        jsonResponse(0, '举报提交成功，我们会尽快处理', ['report_id' => $reportId]);
    } else {
        jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}
