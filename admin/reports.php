<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '举报管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 超时升级依赖协同字段；未执行迁移时给出明确提示，而不是 500
$hasCollabCols = false;
try {
    $col = $db->query("SHOW COLUMNS FROM reports LIKE 'assignee_id'")->fetch();
    $hasCollabCols = (bool)$col;
} catch (Exception $e) {}
if (!$hasCollabCols) {
    include __DIR__ . '/header.php';
    echo '<div class="admin-container"><div class="admin-main" style="padding:40px;">'
       . '<div class="alert alert-warning">请先执行数据库迁移：<code>database/migration_report_collaboration.sql</code>，新增协同指派与超时升级字段后再使用本页面。</div>'
       . '<a class="btn btn-primary btn-sm" href="index.php">返回留言管理</a></div></div>'
       . '</body></html>';
    exit;
}

// 页面访问时触发一次超时升级扫描（内部有节流，也可由 cli/escalate_reports.php 定时执行）
runReportEscalations(false);

$status = $_GET['status'] ?? '';
$reportType = $_GET['report_type'] ?? '';
$assignee = $_GET['assignee'] ?? '';
$priority = $_GET['priority'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2', '3'])) {
    $where .= " AND r.status = ?";
    $params[] = intval($status);
}
if ($reportType && in_array($reportType, ['spam', 'abuse', 'illegal', 'porn', 'other'])) {
    $where .= " AND r.report_type = ?";
    $params[] = $reportType;
}
if ($assignee === 'none') {
    $where .= " AND r.assignee_id IS NULL";
} elseif ($assignee === 'me') {
    $where .= " AND r.assignee_id = ?";
    $params[] = (int)$_SESSION['admin_id'];
} elseif (preg_match('/^u(\d+)$/', $assignee, $m)) {
    $where .= " AND r.assignee_id = ?";
    $params[] = (int)$m[1];
}
if ($priority !== '' && in_array($priority, ['0', '1', '2', '3'])) {
    $where .= " AND r.priority = ?";
    $params[] = intval($priority);
}
if ($keyword) {
    $where .= " AND (m.title LIKE ? OR m.content LIKE ? OR r.description LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM reports r LEFT JOIN messages m ON r.message_id = m.id $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT r.*, m.id as message_id_found, m.title as message_title, m.nickname as message_nickname, m.type as message_type,
               a.username as admin_name, aa.username as assignee_name, l.username as locked_name
        FROM reports r
        LEFT JOIN messages m ON r.message_id = m.id
        LEFT JOIN admins a ON r.processed_by = a.id
        LEFT JOIN admins aa ON r.assignee_id = aa.id
        LEFT JOIN admins l ON r.locked_by = l.id
        $where
        ORDER BY r.status ASC, r.priority DESC, r.deadline ASC, r.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$stats = getReportStats($db);
$pendingMsgCount = (int)$db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
$admins = getAdmins($db);
$pollInterval = reportConfig()['poll_interval'];
$lockTtl = reportConfig()['lock_ttl'];

$currentAdminId = (int)$_SESSION['admin_id'];

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingMsgCount > 0 ? "($pendingMsgCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link active">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理 (<span id="sidebarPendingReport"><?= $stats['pending'] ?></span>)</a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>举报管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-number" id="statTotal"><?= $stats['total'] ?></div>
                <div class="stat-label">总举报数</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number" id="statPending"><?= $stats['pending'] ?></div>
                <div class="stat-label">待处理</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number" id="statEscalated"><?= $stats['escalated'] ?></div>
                <div class="stat-label">已超时升级</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number" id="statDeleted"><?= $stats['deleted'] ?></div>
                <div class="stat-label">已删除</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="statIgnored"><?= $stats['ignored'] + $stats['rejected'] ?></div>
                <div class="stat-label">已忽略/驳回</div>
            </div>
        </div>

        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待处理</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已处理-已删除</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已处理-已忽略</option>
                    <option value="3" <?= $status === '3' ? 'selected' : '' ?>>已驳回</option>
                </select>
                <select name="report_type">
                    <option value="">全部类型</option>
                    <option value="spam" <?= $reportType === 'spam' ? 'selected' : '' ?>>垃圾信息</option>
                    <option value="abuse" <?= $reportType === 'abuse' ? 'selected' : '' ?>>辱骂攻击</option>
                    <option value="illegal" <?= $reportType === 'illegal' ? 'selected' : '' ?>>违法违规</option>
                    <option value="porn" <?= $reportType === 'porn' ? 'selected' : '' ?>>色情低俗</option>
                    <option value="other" <?= $reportType === 'other' ? 'selected' : '' ?>>其他</option>
                </select>
                <select name="assignee">
                    <option value="">全部指派人</option>
                    <option value="me" <?= $assignee === 'me' ? 'selected' : '' ?>>指派给我</option>
                    <option value="none" <?= $assignee === 'none' ? 'selected' : '' ?>>未指派</option>
                    <?php foreach ($admins as $ad): ?>
                    <option value="u<?= $ad['id'] ?>" <?= $assignee === 'u' . $ad['id'] ? 'selected' : '' ?>><?= cleanInput($ad['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="priority">
                    <option value="">全部优先级</option>
                    <option value="0" <?= $priority === '0' ? 'selected' : '' ?>>普通</option>
                    <option value="1" <?= $priority === '1' ? 'selected' : '' ?>>一级升级</option>
                    <option value="2" <?= $priority === '2' ? 'selected' : '' ?>>二级升级</option>
                    <option value="3" <?= $priority === '3' ? 'selected' : '' ?>>三级升级</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="reports.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 批量操作栏 -->
        <div class="batch-bar" id="batchBar" style="display:none;">
            <span>已选 <strong id="batchSelectedCount">0</strong> 条，其中待处理 <strong id="batchPendingCount">0</strong> 条</span>
            <div class="batch-actions">
                <button type="button" class="btn btn-sm btn-info" onclick="openAssignModal()">👥 协同指派</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="openBatchProcessModal()">📋 逐条预览并处置</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="clearSelection()">取消选择</button>
            </div>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table" id="reportsTable">
                <thead>
                    <tr>
                        <th style="width:36px;"><input type="checkbox" id="checkAll" title="全选本页待处理"></th>
                        <th>ID</th>
                        <th>举报类型</th>
                        <th>被举报留言</th>
                        <th>状态</th>
                        <th>优先级/时限</th>
                        <th>指派人</th>
                        <th>举报时间</th>
                        <th>处理人</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                    <tr><td colspan="10" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($reports as $r):
                        $lockValid = isReportLockValid($r);
                    ?>
                    <tr data-report-id="<?= $r['id'] ?>" data-status="<?= $r['status'] ?>"
                        class="report-row <?= $r['status'] != 0 ? 'row-processed' : '' ?>">
                        <td>
                            <?php if ($r['status'] == 0): ?>
                            <input type="checkbox" class="report-check" value="<?= $r['id'] ?>">
                            <?php endif; ?>
                        </td>
                        <td><?= $r['id'] ?></td>
                        <td><span class="badge badge-<?= $r['report_type'] ?>"><?= getReportTypeLabel($r['report_type']) ?></span></td>
                        <td class="td-title" title="<?= !empty($r['message_id_found']) ? cleanInput($r['message_title']) : '留言已删除' ?>">
                            <?php if (!empty($r['message_id_found'])): ?>
                                <a href="../detail.php?id=<?= $r['message_id'] ?>" target="_blank"><?= cleanInput(mb_substr($r['message_title'], 0, 15)) ?></a>
                            <?php else: ?>
                                <span class="text-muted">留言已删除</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge report-status-<?= getReportStatusClass($r['status']) ?>" data-role="status-badge">
                                <?= getReportStatusLabel($r['status']) ?>
                            </span>
                            <span class="lock-tip" data-role="lock-tip" <?= $lockValid ? '' : 'style="display:none;"' ?>>
                                🔒 <span data-role="lock-name"><?= cleanInput($r['locked_name'] ?? '') ?></span>处理中
                            </span>
                        </td>
                        <td>
                            <?php if ($r['status'] == 0): ?>
                                <span class="priority-badge priority-<?= getReportPriorityClass($r['priority']) ?>" data-role="priority-badge">
                                    <?= getReportPriorityLabel($r['priority']) ?>
                                </span>
                                <?php if ($r['deadline']): ?>
                                <div class="deadline-text <?= strtotime($r['deadline']) < time() ? 'overdue' : '' ?>" data-role="deadline">
                                    <?= strtotime($r['deadline']) < time() ? '已超时' : '截止' ?> <?= date('m-d H:i', strtotime($r['deadline'])) ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-role="assignee"><?= $r['assignee_name'] ? cleanInput($r['assignee_name']) : '<span class="text-muted">未指派</span>' ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
                        <td data-role="processed-by"><?= $r['admin_name'] ? cleanInput($r['admin_name']) : '-' ?></td>
                        <td class="td-actions" data-role="actions">
                            <button class="btn btn-xs btn-info" onclick="viewReport(<?= $r['id'] ?>)">查看</button>
                            <?php if ($r['status'] == 0): ?>
                                <button class="btn btn-xs btn-danger" data-act="1" onclick="processReport(<?= $r['id'] ?>, 1)">删除留言</button>
                                <button class="btn btn-xs btn-success" data-act="2" onclick="processReport(<?= $r['id'] ?>, 2)">忽略</button>
                                <button class="btn btn-xs btn-warning" data-act="3" onclick="processReport(<?= $r['id'] ?>, 3)">驳回</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $qs = function ($p) use ($status, $reportType, $assignee, $priority, $keyword) {
            return http_build_query($p + [
                'status' => $status, 'report_type' => $reportType,
                'assignee' => $assignee, 'priority' => $priority, 'keyword' => $keyword,
            ]);
        };
        ?>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="reports.php?<?= $qs(['page' => $page - 1]) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="reports.php?<?= $qs(['page' => $i]) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="reports.php?<?= $qs(['page' => $page + 1]) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 举报详情 -->
<div class="modal" id="reportViewModal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>举报详情 <span id="reportViewLiveTag" class="live-tag" style="display:none;">🔄 实时</span></h3>
            <button class="modal-close" onclick="closeReportViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="reportViewBody">加载中...</div>
    </div>
</div>

<!-- 单条处理备注 -->
<div class="modal" id="processNoteModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>处理备注 <span id="processNoteTitle"></span></h3>
            <button class="modal-close" onclick="closeProcessNoteModal(true)">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="processNote">处理备注（可选，最多500字）</label>
                <textarea id="processNote" rows="3" maxlength="500" placeholder="请输入处理备注..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeProcessNoteModal(true)">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmProcess()">确认处理</button>
            </div>
        </div>
    </div>
</div>

<!-- 协同指派 -->
<div class="modal" id="assignModal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>👥 协同指派</h3>
            <button class="modal-close" onclick="closeAssignModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted" style="margin-bottom:12px;">为每条举报分别指定处理人，也可使用下方“统一指派”批量设置。</p>
            <div class="batch-quick">
                <label>统一指派给：</label>
                <select id="assignAllSelect"></select>
                <button type="button" class="btn btn-sm btn-secondary" onclick="applyAssignAll()">应用到全部</button>
            </div>
            <div id="assignList" class="assign-list"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmAssign()">确认指派</button>
            </div>
        </div>
    </div>
</div>

<!-- 逐条预览并处置 -->
<div class="modal" id="batchProcessModal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>📋 整组处置预览（逐条确认）</h3>
            <button class="modal-close" onclick="closeBatchProcessModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted" style="margin-bottom:12px;">请逐条核对并为每条选择处置动作；已处理或被他人锁定的条目将自动跳过。</p>
            <div id="batchPreviewList" class="batch-preview-list">加载中...</div>
            <div class="batch-note-box">
                <label for="batchCommonNote">统一备注（可选，追加到每条；每条备注也可单独修改）</label>
                <textarea id="batchCommonNote" rows="2" maxlength="500" placeholder="例如：已核实，属于同类违规..."></textarea>
                <button type="button" class="btn btn-xs btn-secondary" onclick="applyCommonNote()">填入全部条目</button>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBatchProcessModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmBatchProcess()">确认整组处置</button>
            </div>
        </div>
    </div>
</div>

<script>
const CURRENT_ADMIN_ID = <?= $currentAdminId ?>;
const POLL_INTERVAL = <?= (int)$pollInterval ?> * 1000;
const LOCK_TTL = <?= (int)$lockTtl ?>;
</script>
<script>
/* ========== 多选 ========== */
const checkAll = document.getElementById('checkAll');

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.report-check:checked')).map(c => parseInt(c.value));
}

function refreshBatchBar() {
    const ids = getSelectedIds();
    const bar = document.getElementById('batchBar');
    bar.style.display = ids.length ? 'flex' : 'none';
    document.getElementById('batchSelectedCount').textContent = ids.length;
    // 多选列表中的待处理数量（勾选后即统计，提交后随状态更新）
    let pending = 0;
    ids.forEach(id => {
        const row = document.querySelector('tr[data-report-id="' + id + '"]');
        if (row && parseInt(row.dataset.status) === 0) pending++;
    });
    document.getElementById('batchPendingCount').textContent = pending;
}

if (checkAll) {
    checkAll.addEventListener('change', function() {
        document.querySelectorAll('.report-check').forEach(c => { c.checked = this.checked; });
        refreshBatchBar();
    });
}
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('report-check')) refreshBatchBar();
});

function clearSelection() {
    document.querySelectorAll('.report-check:checked').forEach(c => c.checked = false);
    if (checkAll) checkAll.checked = false;
    refreshBatchBar();
}

/* ========== 单条查看（带轮询，他人处理后即时看到状态变化） ========== */
let viewPollTimer = null;
let viewingReportId = null;
let lastViewSnapshot = '';

function viewReport(id) {
    viewingReportId = id;
    document.getElementById('reportViewModal').style.display = 'flex';
    document.getElementById('reportViewBody').innerHTML = '加载中...';
    loadReportDetail(id);
    if (viewPollTimer) clearInterval(viewPollTimer);
    viewPollTimer = setInterval(() => { if (viewingReportId) pollViewedReport(viewingReportId); }, POLL_INTERVAL);
}

function reportDetailHtml(d) {
    let html = '<div class="detail-view">';
    const lockBanner = d.lock_valid && !d.locked_by_me
        ? '<div class="alert alert-warning">🔒 该举报正由 <strong>' + d.locked_by_name + '</strong> 处理中，你暂时无法处置</div>'
        : (d.locked_by_me ? '<div class="alert alert-info">🔒 你已占用该举报的处理权（' + LOCK_TTL + ' 秒有效）</div>' : '');
    html += lockBanner;
    html += '<p><strong>举报ID：</strong>' + d.id + '</p>';
    html += '<p><strong>举报类型：</strong><span class="badge badge-' + d.report_type + '">' + d.report_type_label + '</span></p>';
    html += '<p><strong>优先级：</strong><span class="priority-badge priority-' + d.priority_class + '">' + d.priority_label + '</span>';
    if (d.status == 0 && d.deadline) html += ' <span class="text-muted">（截止 ' + d.deadline + '）</span>';
    html += '</p>';
    html += '<p><strong>指派人：</strong>' + d.assignee_name + '</p>';
    html += '<p><strong>举报时间：</strong>' + d.created_at + '</p>';
    html += '<p><strong>举报状态：</strong><span class="status-badge report-status-' + d.status_class + '">' + d.status_label + '</span></p>';
    if (d.description) html += '<p><strong>举报说明：</strong></p><div class="detail-text">' + d.description + '</div>';
    html += '<hr style="margin:16px 0;border:none;border-top:1px solid #e5e7eb;">';
    html += '<h4 style="margin-bottom:12px;">被举报留言信息</h4>';
    if (d.message_exists) {
        html += '<p><strong>留言标题：</strong>' + d.message_title + '</p>';
        html += '<p><strong>留言作者：</strong>' + d.message_nickname + '</p>';
        html += '<p><strong>留言类型：</strong>' + d.message_type_label + '</p>';
        html += '<p><strong>留言内容：</strong></p><div class="detail-text">' + d.message_content + '</div>';
        if (d.message_image) html += '<p><strong>留言图片：</strong><br><img src="../' + d.message_image + '" style="max-width:100%;margin-top:8px;"></p>';
    } else {
        html += '<p class="text-muted">该留言已被删除（处置结果与举报状态一致）</p>';
    }
    if (d.status > 0) {
        html += '<hr style="margin:16px 0;border:none;border-top:1px solid #e5e7eb;">';
        html += '<h4 style="margin-bottom:12px;">处理信息</h4>';
        html += '<p><strong>处理人：</strong>' + (d.admin_name || '-') + '</p>';
        html += '<p><strong>处理时间：</strong>' + (d.processed_at || '-') + '</p>';
        if (d.process_note) html += '<p><strong>处理备注：</strong></p><div class="detail-text">' + d.process_note + '</div>';
    }
    html += '<div id="reportLogsBlock" style="margin-top:16px;"></div>';
    html += '</div>';
    return html;
}

function loadReportDetail(id) {
    fetch('api.php?action=report_detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            // 快照只包含会被他人协同操作改变的字段（锁、状态、优先级、指派人）
            lastViewSnapshot = JSON.stringify([d.status, d.lock_valid, d.locked_by, d.priority, d.assignee_id]);
            document.getElementById('reportViewBody').innerHTML = reportDetailHtml(d);
            loadReportLogs(id);
        } else {
            document.getElementById('reportViewBody').innerHTML = data.msg;
        }
    });
}

function loadReportLogs(id) {
    fetch('api.php?action=report_logs&id=' + id)
    .then(r => r.json())
    .then(data => {
        const block = document.getElementById('reportLogsBlock');
        if (!block || data.code !== 0 || !data.data.length) return;
        let html = '<h4 style="margin-bottom:8px;">操作记录</h4><ul class="report-log-list">';
        data.data.forEach(l => {
            html += '<li><span class="log-time">' + l.created_at + '</span> <strong>' + l.action_label + '</strong> · '
                  + l.admin_name + (l.detail ? ' · ' + l.detail : '') + '</li>';
        });
        html += '</ul>';
        block.innerHTML = html;
    });
}

// 轮询当前查看的举报：状态或锁变化时自动刷新
function pollViewedReport(id) {
    fetch('api.php?action=report_states&ids=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0) return;
        updateStats(data.data.stats);
        const item = data.data.list.find(x => x.id === id);
        if (!item) return;
        updateRowState(item);
        const snap = JSON.stringify([item.status, item.lock_valid, item.locked_by, item.priority, item.assignee_id]);
        if (snap !== lastViewSnapshot && document.getElementById('reportViewModal').style.display !== 'none') {
            loadReportDetail(id);
            flashLiveTag();
        }
    });
}

function flashLiveTag() {
    const tag = document.getElementById('reportLiveTag');
    tag.style.display = 'inline-block';
    setTimeout(() => { tag.style.display = 'none'; }, 2000);
}

function closeReportViewModal() {
    document.getElementById('reportViewModal').style.display = 'none';
    viewingReportId = null;
    if (viewPollTimer) { clearInterval(viewPollTimer); viewPollTimer = null; }
}

/* ========== 单条处理（先抢锁，提交时行级互斥） ========== */
let pendingProcessId = null;
let pendingProcessStatus = null;
let lockHeartbeat = null;

function processReport(id, status) {
    let actionText = {1: '删除留言并标记为已处理', 2: '忽略此举报', 3: '驳回此举报'}[status];
    if (!confirm('确定要' + actionText + '吗？')) return;

    // 先抢占编辑锁，防止两人同时填写备注
    const formData = new FormData();
    formData.append('action', 'acquire_lock');
    formData.append('id', id);
    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0) {
            alert(data.msg);
            refreshAllRows();
            return;
        }
        // 立即在自己与他人列表上反映处理锁
        if (data.data && data.data.state) updateRowState(data.data.state);
        pendingProcessId = id;
        pendingProcessStatus = status;
        document.getElementById('processNoteTitle').textContent =
            {1: '（删除留言）', 2: '（忽略举报）', 3: '（驳回举报）'}[status];
        document.getElementById('processNote').value = '';
        document.getElementById('processNoteModal').style.display = 'flex';

        // 锁续租心跳（TTL 一半时续一次）
        if (lockHeartbeat) clearInterval(lockHeartbeat);
        lockHeartbeat = setInterval(() => renewLock(id), Math.max(20, LOCK_TTL * 500));
    });
}

function renewLock(id) {
    const fd = new FormData();
    fd.append('action', 'acquire_lock');
    fd.append('id', id);
    fetch('api.php', { method: 'POST', body: fd }).catch(() => {});
}

function closeProcessNoteModal(release) {
    document.getElementById('processNoteModal').style.display = 'none';
    if (release && pendingProcessId) {
        const fd = new FormData();
        fd.append('action', 'release_lock');
        fd.append('id', pendingProcessId);
        fetch('api.php', { method: 'POST', body: fd }).then(refreshAllRows);
    }
    pendingProcessId = null;
    pendingProcessStatus = null;
    if (lockHeartbeat) { clearInterval(lockHeartbeat); lockHeartbeat = null; }
}

function confirmProcess() {
    if (!pendingProcessId || !pendingProcessStatus) return;
    const note = document.getElementById('processNote').value;
    const formData = new FormData();
    formData.append('action', 'process_report');
    formData.append('id', pendingProcessId);
    formData.append('status', pendingProcessStatus);
    formData.append('note', note);

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const doneId = pendingProcessId;
            const doneStatus = pendingProcessStatus;
            if (lockHeartbeat) { clearInterval(lockHeartbeat); lockHeartbeat = null; }
            document.getElementById('processNoteModal').style.display = 'none';
            pendingProcessId = null;
            pendingProcessStatus = null;
            closeReportViewModal();
            // 不整页刷新：局部更新行与待处理数量
            markRowProcessed(doneId, doneStatus);
            if (data.data && data.data.stats) updateStats(data.data.stats);
            clearSelection();
            alert(data.msg);
        } else {
            // 另一人已先生效：提示并刷新该行状态
            alert(data.msg);
            closeProcessNoteModal(false);
            refreshAllRows();
        }
    });
}

function markRowProcessed(id, status) {
    const row = document.querySelector('tr[data-report-id="' + id + '"]');
    if (!row) return;
    row.dataset.status = status;
    row.classList.add('row-processed');
    const badge = row.querySelector('[data-role="status-badge"]');
    if (badge) {
        badge.textContent = {1: '已处理-已删除', 2: '已处理-已忽略', 3: '已驳回'}[status];
        badge.className = 'status-badge report-status-' + {1: 'resolved-deleted', 2: 'resolved-ignored', 3: 'rejected'}[status];
    }
    row.querySelector('[data-role="processed-by"]').textContent = '<?= cleanInput($_SESSION['admin_name'], ENT_QUOTES) ?>';
    row.querySelector('[data-role="actions"]').innerHTML =
        '<button class="btn btn-xs btn-info" onclick="viewReport(' + id + ')">查看</button>';
    const check = row.querySelector('.report-check');
    if (check) { check.checked = false; check.disabled = true; }
    refreshBatchBar();
}

/* ========== 列表轮询：他人处理/加锁即时可见 ========== */
function currentPageIds() {
    return Array.from(document.querySelectorAll('tr[data-report-id]')).map(r => parseInt(r.dataset.reportId));
}

function updateRowState(item) {
    const row = document.querySelector('tr[data-report-id="' + item.id + '"]');
    if (!row) return;
    const prevStatus = parseInt(row.dataset.status);
    row.dataset.status = item.status;

    const badge = row.querySelector('[data-role="status-badge"]');
    if (badge && badge.textContent !== item.status_label) {
        badge.textContent = item.status_label;
        badge.className = 'status-badge report-status-' +
            ({0: 'pending', 1: 'resolved-deleted', 2: 'resolved-ignored', 3: 'rejected'}[item.status] || 'pending');
        row.classList.add('row-changed');
        setTimeout(() => row.classList.remove('row-changed'), 2500);
    }

    // 处理锁提示
    const lockTip = row.querySelector('[data-role="lock-tip"]');
    if (lockTip) {
        lockTip.style.display = item.lock_valid && item.status === 0 ? 'inline' : 'none';
        const name = lockTip.querySelector('[data-role="lock-name"]');
        if (name) name.textContent = item.locked_by_name;
    }

    // 优先级徽标
    const pBadge = row.querySelector('[data-role="priority-badge"]');
    if (pBadge) {
        pBadge.textContent = item.priority_label;
        pBadge.className = 'priority-badge priority-' + ({0:'normal',1:'level1',2:'level2',3:'level3'}[item.priority]);
    }

    const assigneeCell = row.querySelector('[data-role="assignee"]');
    if (assigneeCell && item.assignee_name) assigneeCell.textContent = item.assignee_name;

    const procCell = row.querySelector('[data-role="processed-by"]');
    if (item.status > 0 && item.processed_name && procCell) procCell.textContent = item.processed_name;

    // 他人已处理完成：禁用操作按钮与勾选
    if (item.status > 0 && prevStatus === 0) {
        row.classList.add('row-processed');
        const actions = row.querySelector('[data-role="actions"]');
        if (actions) actions.innerHTML = '<button class="btn btn-xs btn-info" onclick="viewReport(' + item.id + ')">查看</button>';
        const check = row.querySelector('.report-check');
        if (check) { check.checked = false; check.disabled = true; }
        refreshBatchBar();
    }
}

function updateStats(s) {
    if (!s) return;
    document.getElementById('statTotal').textContent = s.total;
    document.getElementById('statPending').textContent = s.pending;
    document.getElementById('statEscalated').textContent = s.escalated;
    document.getElementById('statDeleted').textContent = s.deleted;
    document.getElementById('statIgnored').textContent = s.ignored + s.rejected;
    document.getElementById('sidebarPendingReport').textContent = s.pending;
}

function refreshAllRows() {
    const ids = currentPageIds();
    if (!ids.length) return;
    fetch('api.php?action=report_states&ids=' + ids.join(','))
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0) return;
        data.data.list.forEach(updateRowState);
        updateStats(data.data.stats);
    });
}

setInterval(refreshAllRows, POLL_INTERVAL);

/* ========== 协同指派 ========== */
let assignCandidates = [];

function openAssignModal() {
    const ids = getSelectedIds();
    if (!ids.length) { alert('请先勾选举报'); return; }

    fetch('api.php?action=admin_list')
    .then(r => r.json())
    .then(data => {
        assignCandidates = data.code === 0 ? data.data : [];
        const sel = document.getElementById('assignAllSelect');
        sel.innerHTML = '<option value="">未指派</option>' +
            assignCandidates.map(a => '<option value="' + a.id + '">' + a.username + '</option>').join('');

        const list = document.getElementById('assignList');
        list.innerHTML = '加载中...';
        const fd = new FormData();
        fd.append('action', 'batch_preview');
        fd.append('ids', JSON.stringify(ids));
        return fetch('api.php', { method: 'POST', body: fd }).then(r => r.json());
    })
    .then(data => {
        if (data.code !== 0) { alert(data.msg); return; }
        const rows = data.data.list;
        if (!rows.length) { alert('没有可指派的条目'); return; }
        document.getElementById('assignList').innerHTML = rows.map(r => renderAssignRow(r)).join('');
        document.getElementById('assignModal').style.display = 'flex';
    });
}

function renderAssignRow(r) {
    const options = '<option value="">未指派</option>' +
        assignCandidates.map(a =>
            '<option value="' + a.id + '"' + (r.assignee_id === a.id ? ' selected' : '') + '>' + a.username + '</option>'
        ).join('');
    const stateTag = r.status !== 0
        ? '<span class="text-muted">（' + r.status_label + '，将跳过）</span>'
        : (r.priority > 0 ? '<span class="priority-badge priority-' + ({1:'level1',2:'level2',3:'level3'}[r.priority]) + '">' + r.priority_label + '</span>' : '');
    return '<div class="assign-row" data-report-id="' + r.id + '" data-status="' + r.status + '">'
        + '<div class="assign-row-head">#' + r.id + ' · <span class="badge badge-' + r.report_type + '">' + r.report_type_label + '</span> '
        + (r.message_title ? r.message_title : '留言已删除') + ' ' + stateTag + '</div>'
        + '<div class="assign-row-select">指派给：<select class="assign-target">' + options + '</select></div>'
        + '</div>';
}

function applyAssignAll() {
    const v = document.getElementById('assignAllSelect').value;
    document.querySelectorAll('.assign-target').forEach(s => { s.value = v; });
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
}

function confirmAssign() {
    const items = [];
    document.querySelectorAll('.assign-row').forEach(row => {
        if (parseInt(row.dataset.status) !== 0) return;
        items.push({
            report_id: parseInt(row.dataset.reportId),
            admin_id: parseInt(row.querySelector('.assign-target').value) || 0
        });
    });
    if (!items.length) { alert('所选举报均已处理，无需指派'); return; }

    const fd = new FormData();
    fd.append('action', 'assign_reports');
    fd.append('items', JSON.stringify(items));
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert(data.msg);
            closeAssignModal();
            if (data.data && data.data.stats) updateStats(data.data.stats);
            refreshAllRows();
        } else {
            alert(data.msg);
        }
    });
}

/* ========== 整组处置：逐条预览 ========== */
let batchPreviewItems = [];

function openBatchProcessModal() {
    const ids = getSelectedIds();
    if (!ids.length) { alert('请先勾选举报'); return; }
    document.getElementById('batchProcessModal').style.display = 'flex';
    document.getElementById('batchPreviewList').innerHTML = '加载中...';

    const fd = new FormData();
    fd.append('action', 'batch_preview');
    fd.append('ids', JSON.stringify(ids));
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0) { alert(data.msg); closeBatchProcessModal(); return; }
        batchPreviewItems = data.data.list;
        document.getElementById('batchPreviewList').innerHTML =
            batchPreviewItems.map(renderPreviewItem).join('') || '<p class="text-center">没有可预览的条目</p>';
    });
}

function renderPreviewItem(r) {
    const disabled = r.status !== 0 || (r.lock_valid && !r.locked_by_me);
    let reason = '';
    if (r.status !== 0) reason = '已处理：' + r.status_label;
    else if (r.lock_valid && !r.locked_by_me) reason = '🔒 ' + r.locked_by_name + ' 正在处理';
    else if (r.locked_by_me) reason = '🔒 你已锁定';

    const radios = [1, 2, 3].map(st =>
        '<label class="mini-radio"><input type="radio" name="bp_act_' + r.id + '" value="' + st + '"'
        + (disabled ? ' disabled' : '') + '> ' + {1:'删除留言',2:'忽略',3:'驳回'}[st] + '</label>'
    ).join('');

    return '<div class="preview-item' + (disabled ? ' is-disabled' : '') + '" data-report-id="' + r.id + '" data-disabled="' + (disabled ? 1 : 0) + '">'
        + '<div class="preview-head">'
        +   '<span class="preview-index">#' + r.id + '</span>'
        +   '<span class="badge badge-' + r.report_type + '">' + r.report_type_label + '</span>'
        +   (r.priority > 0 ? ' <span class="priority-badge priority-' + ({1:'level1',2:'level2',3:'level3'}[r.priority]) + '">' + r.priority_label + '</span>' : '')
        +   '<span class="text-muted" style="margin-left:8px;">指派人：' + r.assignee_name + '</span>'
        +   (reason ? '<span class="preview-reason">· ' + reason + '</span>' : '')
        + '</div>'
        + '<div class="preview-msg">'
        +   (r.message_exists
                ? '<strong>' + r.message_title + '</strong> <span class="text-muted">(' + r.message_nickname + ' · ' + r.message_type_label + ')</span><div class="preview-content">' + r.message_content + '</div>'
                : '<span class="text-muted">留言已删除</span>')
        +   (r.description ? '<div class="preview-desc">举报说明：' + r.description + '</div>' : '')
        + '</div>'
        + '<div class="preview-actions"><label>本条动作：</label>' + radios + '</div>'
        + '<textarea class="preview-note" rows="2" maxlength="500" placeholder="本条处理备注（可选）"' + (disabled ? ' disabled' : '') + '></textarea>'
        + '<div class="preview-result" data-role="result" style="display:none;"></div>'
        + '</div>';
}

function applyCommonNote() {
    const v = document.getElementById('batchCommonNote').value;
    document.querySelectorAll('.preview-item[data-disabled="0"] .preview-note').forEach(t => { t.value = v; });
}

function closeBatchProcessModal() {
    document.getElementById('batchProcessModal').style.display = 'none';
    batchPreviewItems = [];
}

function confirmBatchProcess() {
    const items = [];
    let missing = 0;
    document.querySelectorAll('.preview-item').forEach(row => {
        if (row.dataset.disabled === '1') return;
        const id = parseInt(row.dataset.reportId);
        const checked = row.querySelector('input[name^="bp_act_"]:checked');
        if (!checked) { missing++; return; }
        items.push({ id: id, action: parseInt(checked.value), note: row.querySelector('.preview-note').value });
    });

    if (!items.length) { alert('没有可处置的有效条目'); return; }
    if (missing > 0 && !confirm('有 ' + missing + ' 条未选择动作，将被跳过。继续提交其余 ' + items.length + ' 条吗？')) return;
    if (!confirm('确认对选中的 ' + items.length + ' 条举报执行整组处置？删除动作不可恢复。')) return;

    const fd = new FormData();
    fd.append('action', 'batch_process');
    fd.append('items', JSON.stringify(items));
    fetch('api.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0) { alert(data.msg); return; }
        // 逐条展示结果
        (data.data.results || []).forEach(res => {
            const row = document.querySelector('.preview-item[data-report-id="' + res.id + '"]');
            if (!row) return;
            const box = row.querySelector('[data-role="result"]');
            box.style.display = 'block';
            box.className = 'preview-result ' + (res.ok ? 'result-ok' : 'result-fail');
            box.textContent = (res.ok ? '✅ ' : '❌ ') + res.msg;
            if (res.ok) {
                row.classList.add('is-done');
                row.dataset.disabled = '1';
                markRowProcessed(res.id, res.status);
            }
        });
        if (data.data.stats) updateStats(data.data.stats);
        refreshBatchBar();
        alert(data.msg);
    });
}

/* 弹窗遮罩点击关闭 */
['reportViewModal', 'assignModal', 'batchProcessModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
document.getElementById('processNoteModal').addEventListener('click', function(e) {
    if (e.target === this) closeProcessNoteModal(true);
});
</script>
