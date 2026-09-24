<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '举报管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 任何后台访问先跑一次超时升级扫描（未配置 cron 也能按阈值升级）
try { runReportEscalations(); } catch (Exception $e) {}

$status = $_GET['status'] ?? '';
$reportType = $_GET['report_type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$escalation = $_GET['escalation'] ?? '';
$assignee = $_GET['assignee'] ?? '';
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
if ($escalation !== '' && in_array($escalation, ['0', '1', '2'])) {
    $where .= " AND r.escalation_level = ?";
    $params[] = intval($escalation);
}
if ($assignee === 'me') {
    $where .= " AND r.assigned_to = ?";
    $params[] = $_SESSION['admin_id'];
} elseif ($assignee === 'none') {
    $where .= " AND r.assigned_to IS NULL";
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

$sql = "SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type,
        a.username as admin_name, aa.username as assignee_name, ac.username as claim_name
        FROM reports r
        LEFT JOIN messages m ON r.message_id = m.id
        LEFT JOIN admins a ON r.processed_by = a.id
        LEFT JOIN admins aa ON r.assigned_to = aa.id
        LEFT JOIN admins ac ON r.claim_by = ac.id
        $where
        ORDER BY (r.status = 0) DESC, r.escalation_level DESC, r.due_at ASC, r.created_at DESC
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$pendingCount = getPendingReportCount();
$totalReportCount = $db->query("SELECT COUNT(*) FROM reports")->fetchColumn();
$deletedCount = $db->query("SELECT COUNT(*) FROM reports WHERE status = 1")->fetchColumn();
$ignoredCount = $db->query("SELECT COUNT(*) FROM reports WHERE status = 2")->fetchColumn();
$rejectedCount = $db->query("SELECT COUNT(*) FROM reports WHERE status = 3")->fetchColumn();
$escalatedCount = $db->query("SELECT COUNT(*) FROM reports WHERE status = 0 AND escalation_level > 0")->fetchColumn();

$admins = getAdminList();
$settings = getReportSettings();

include __DIR__ . '/header.php';
?>
<style>
/* 协同指派与超时升级 */
.badge-esc1 { background:#fef3c7; color:#b45309; }
.badge-esc2 { background:#fee2e2; color:#b91c1c; }
.tag-claim { display:inline-block; padding:1px 6px; border-radius:8px; font-size:.72rem; background:#ede9fe; color:#6d28d9; margin-left:4px; }
.tag-due { font-size:.72rem; color:#6b7280; }
.tag-due.overdue { color:#dc2626; font-weight:600; }
.batch-bar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:10px 14px; margin-bottom:14px; }
.batch-bar.hidden { display:none; }
.wizard-card { border:1px solid #e5e7eb; border-radius:8px; padding:14px; margin:10px 0; background:#f9fafb; }
.wizard-option { display:block; margin:8px 0; padding:8px 10px; border:1px solid #e5e7eb; border-radius:6px; cursor:pointer; background:#fff; }
.wizard-option:hover { border-color:#3b82f6; }
.wizard-option input { margin-right:8px; }
.log-timeline { list:none; padding-left:0; margin:8px 0 0; border-left:2px solid #e5e7eb; }
.log-timeline li { position:relative; padding:4px 0 8px 16px; font-size:.85rem; color:#374151; }
.log-timeline li::before { content:''; position:absolute; left:-5px; top:10px; width:8px; height:8px; border-radius:50%; background:#3b82f6; }
.log-time { color:#9ca3af; font-size:.75rem; margin-right:6px; }
.assignee-select { padding:2px 4px; font-size:.75rem; max-width:110px; }
.row-locked { opacity:.75; }
</style>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核</a>
            <a href="reports.php" class="sidebar-link active">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php?status=0&escalation=1" class="sidebar-link">🔺 已升级 <?= $escalatedCount > 0 ? "($escalatedCount)" : '' ?></a>
            <a href="reports.php?assignee=me&status=0" class="sidebar-link">👤 指派给我</a>
            <a href="../my_reports.php" class="sidebar-link" target="_blank">📄 我的举报(前台)</a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>举报管理</h2>
            <span class="admin-user">
                <button class="btn btn-xs btn-info" onclick="openSettingsModal()">⏱ 超时升级设置</button>
                👤 <?= cleanInput($_SESSION['admin_name']) ?>
            </span>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-number" id="statTotal"><?= $totalReportCount ?></div>
                <div class="stat-label">总举报数</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number" id="statPending"><?= $pendingCount ?></div>
                <div class="stat-label">待处理</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number" id="statDeleted"><?= $deletedCount ?></div>
                <div class="stat-label">已删除</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number" id="statIgnored"><?= $ignoredCount + $rejectedCount ?></div>
                <div class="stat-label">已忽略/驳回 <small>(忽略<?= $ignoredCount ?>·驳回<?= $rejectedCount ?>)</small></div>
            </div>
            <div class="stat-card" style="border-top-color:#dc2626;">
                <div class="stat-number" id="statEscalated"><?= $escalatedCount ?></div>
                <div class="stat-label">已升级(待处理中)</div>
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
                <select name="escalation">
                    <option value="">全部级别</option>
                    <option value="0" <?= $escalation === '0' ? 'selected' : '' ?>>普通</option>
                    <option value="1" <?= $escalation === '1' ? 'selected' : '' ?>>一级升级</option>
                    <option value="2" <?= $escalation === '2' ? 'selected' : '' ?>>二级升级</option>
                </select>
                <select name="assignee">
                    <option value="">指派人(全部)</option>
                    <option value="me" <?= $assignee === 'me' ? 'selected' : '' ?>>指派给我</option>
                    <option value="none" <?= $assignee === 'none' ? 'selected' : '' ?>>未指派</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="reports.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 批量操作栏：勾选待处理举报后出现 -->
        <div class="batch-bar hidden" id="batchBar">
            <strong>已选 <span id="selectedCount">0</span> 条待处理举报</strong>
            <button class="btn btn-sm btn-info" id="btnBatchAssign" onclick="openBatchAssign()">👥 批量指派（可分别指定处理人）</button>
            <button class="btn btn-sm btn-primary" id="btnBatchProcess" onclick="openBatchWizard()">📋 批量处置（逐条预览）</button>
            <button class="btn btn-sm btn-secondary" onclick="clearSelection()">清除选择</button>
            <span class="tag-due">提示：处置时会自动锁定，其他处理人仅能查看不能重复处置</span>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:34px;"><input type="checkbox" id="checkAll" title="全选本页待处理"></th>
                        <th>ID</th>
                        <th>举报类型</th>
                        <th>被举报留言</th>
                        <th>留言作者</th>
                        <th>状态</th>
                        <th>指派处理人</th>
                        <th>升级/时限</th>
                        <th>举报时间</th>
                        <th>处理人</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                    <tr><td colspan="11" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($reports as $r):
                        $claimActive = $r['claim_by'] && $r['claim_at'] && (time() - strtotime($r['claim_at'])) < 300;
                        $claimByOther = $claimActive && $r['claim_by'] != $_SESSION['admin_id'];
                        $overdue = $r['status'] == 0 && $r['due_at'] && strtotime($r['due_at']) < time();
                    ?>
                    <tr data-id="<?= $r['id'] ?>" data-status="<?= $r['status'] ?>" class="js-row <?= $claimByOther ? 'row-locked' : '' ?>">
                        <td>
                            <?php if ($r['status'] == 0): ?>
                            <input type="checkbox" class="js-row-check" data-id="<?= $r['id'] ?>" <?= $claimByOther ? 'disabled title="其他处理人正在处理"' : '' ?>>
                            <?php endif; ?>
                        </td>
                        <td><?= $r['id'] ?></td>
                        <td><span class="badge badge-<?= $r['report_type'] ?>"><?= getReportTypeLabel($r['report_type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($r['message_title'] ?? '留言已删除') ?>">
                            <?php if ($r['message_title']): ?>
                                <a href="../detail.php?id=<?= $r['message_id'] ?>" target="_blank"><?= cleanInput(mb_substr($r['message_title'], 0, 15)) ?></a>
                            <?php else: ?>
                                <span class="text-muted">留言已删除</span>
                            <?php endif; ?>
                        </td>
                        <td><?= cleanInput($r['message_nickname'] ?? '-') ?></td>
                        <td class="js-status">
                            <span class="status-badge report-status-<?= getReportStatusClass($r['status']) ?>"><?= getReportStatusLabel($r['status']) ?></span>
                            <?php if ($claimActive): ?>
                                <span class="tag-claim js-claim">🔒 <?= cleanInput($r['claim_name']) ?>处理中</span>
                            <?php endif; ?>
                        </td>
                        <td class="js-assignee">
                            <?php if ($r['status'] == 0): ?>
                                <select class="assignee-select js-assign-select" data-id="<?= $r['id'] ?>" onchange="quickAssign(this)">
                                    <option value="0">未指派</option>
                                    <?php foreach ($admins as $ad): ?>
                                    <option value="<?= $ad['id'] ?>" <?= $r['assigned_to'] == $ad['id'] ? 'selected' : '' ?>><?= cleanInput($ad['username']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <?= $r['assignee_name'] ? cleanInput($r['assignee_name']) : '-' ?>
                            <?php endif; ?>
                        </td>
                        <td class="js-escalation">
                            <?php if ($r['escalation_level'] > 0): ?>
                                <span class="badge <?= $r['escalation_level'] >= 2 ? 'badge-esc2' : 'badge-esc1' ?>">
                                    <?= getEscalationLevelLabel($r['escalation_level']) ?>
                                </span>
                            <?php else: ?>
                                <span class="tag-due">普通</span>
                            <?php endif; ?>
                            <?php if ($r['status'] == 0 && $r['due_at']): ?>
                                <div class="tag-due <?= $overdue ? 'overdue' : '' ?>">
                                    <?= $overdue ? '已超时' : '截止' ?> <?= date('m-d H:i', strtotime($r['due_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
                        <td class="js-processor"><?= $r['admin_name'] ? cleanInput($r['admin_name']) : '-' ?></td>
                        <td class="td-actions js-actions">
                            <button class="btn btn-xs btn-info" onclick="viewReport(<?= $r['id'] ?>)">查看</button>
                            <?php if ($r['status'] == 0): ?>
                                <button class="btn btn-xs btn-danger js-act" data-id="<?= $r['id'] ?>" <?= $claimByOther ? 'disabled' : '' ?> onclick="processReport(<?= $r['id'] ?>, 1)">删除留言</button>
                                <button class="btn btn-xs btn-success js-act" data-id="<?= $r['id'] ?>" <?= $claimByOther ? 'disabled' : '' ?> onclick="processReport(<?= $r['id'] ?>, 2)">忽略</button>
                                <button class="btn btn-xs btn-warning js-act" data-id="<?= $r['id'] ?>" <?= $claimByOther ? 'disabled' : '' ?> onclick="processReport(<?= $r['id'] ?>, 3)">驳回</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $qs = function ($p) use ($status, $reportType, $keyword, $escalation, $assignee) {
            $params = array_filter([
                'status' => $status, 'report_type' => $reportType, 'keyword' => $keyword,
                'escalation' => $escalation, 'assignee' => $assignee, 'page' => $p,
            ], function ($v) { return $v !== ''; });
            return 'reports.php?' . http_build_query($params);
        };
        ?>
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= $qs($page - 1) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="<?= $qs($i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= $qs($page + 1) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 举报详情 -->
<div class="modal" id="reportViewModal" style="display:none;">
    <div class="modal-content" style="max-width:640px;">
        <div class="modal-header">
            <h3>举报详情</h3>
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
            <button class="modal-close" onclick="cancelProcessNote()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="processNote">处理备注（可选，将同步展示给举报人）</label>
                <textarea id="processNote" rows="3" maxlength="500" placeholder="请输入处理备注..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="cancelProcessNote()">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmProcess()">确认处理</button>
            </div>
        </div>
    </div>
</div>

<!-- 批量指派 -->
<div class="modal" id="batchAssignModal" style="display:none;">
    <div class="modal-content" style="max-width:680px;">
        <div class="modal-header">
            <h3>👥 批量协同指派</h3>
            <button class="modal-close" onclick="closeBatchAssign()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group" style="display:flex; align-items:center; gap:10px;">
                <label style="margin:0;">统一设置：</label>
                <select id="assignAllSelect" class="assignee-select" style="max-width:180px;" onchange="applyAssignAll(this.value)">
                    <option value="">— 选择处理人（应用到下方全部） —</option>
                    <?php foreach ($admins as $ad): ?>
                    <option value="<?= $ad['id'] ?>"><?= cleanInput($ad['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="tag-due">也可逐条分别指定不同处理人</span>
            </div>
            <div id="assignRows" style="max-height:340px; overflow-y:auto;"></div>
            <div class="form-group" style="margin-top:10px;">
                <label for="assignNote">指派说明（可选）</label>
                <input type="text" id="assignNote" maxlength="200" placeholder="如：请今天内处理">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeBatchAssign()">取消</button>
                <button type="button" class="btn btn-primary" onclick="submitBatchAssign()">确认指派</button>
            </div>
        </div>
    </div>
</div>

<!-- 批量处置向导：整组处置前逐条预览 -->
<div class="modal" id="wizardModal" style="display:none;">
    <div class="modal-content" style="max-width:720px;">
        <div class="modal-header">
            <h3>📋 批量处置 · 逐条预览 <small id="wizardProgress" class="tag-due"></small></h3>
            <button class="modal-close" onclick="closeWizard()">&times;</button>
        </div>
        <div class="modal-body" id="wizardBody">加载中...</div>
        <div class="modal-footer" style="padding:0 20px 16px; display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div>
                <button type="button" class="btn btn-secondary" id="wizPrev" onclick="wizardGo(-1)">← 上一条</button>
                <button type="button" class="btn btn-secondary" id="wizSkip" onclick="wizardSkip()">跳过本条</button>
                <button type="button" class="btn btn-info" id="wizNext" onclick="wizardGo(1)">下一条 →</button>
            </div>
            <button type="button" class="btn btn-primary" id="wizSubmit" onclick="submitWizard()">确认整组处置（<span id="wizDecided">0</span> 条）</button>
        </div>
    </div>
</div>

<!-- 超时升级设置 -->
<div class="modal" id="settingsModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>⏱ 超时升级设置</h3>
            <button class="modal-close" onclick="closeSettingsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>一级升级阈值（小时，0=不启用）</label>
                <input type="number" id="setH1" min="0" value="<?= (int)$settings['escalation1_hours'] ?>">
            </div>
            <div class="form-group">
                <label>一级升级后指派给</label>
                <select id="setA1">
                    <option value="0">不改变处理人</option>
                    <?php foreach ($admins as $ad): ?>
                    <option value="<?= $ad['id'] ?>" <?= $settings['escalation1_admin_id'] == $ad['id'] ? 'selected' : '' ?>><?= cleanInput($ad['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>二级升级阈值（小时，0=不启用，须大于一级）</label>
                <input type="number" id="setH2" min="0" value="<?= (int)$settings['escalation2_hours'] ?>">
            </div>
            <div class="form-group">
                <label>二级升级后指派给</label>
                <select id="setA2">
                    <option value="0">不改变处理人</option>
                    <?php foreach ($admins as $ad): ?>
                    <option value="<?= $ad['id'] ?>" <?= $settings['escalation2_admin_id'] == $ad['id'] ? 'selected' : '' ?>><?= cleanInput($ad['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="tag-due">建议配置服务器定时任务每小时执行：<code>php <?= cleanInput(dirname(__DIR__) . '/cron_escalate.php') ?></code>；未配置时后台访问与页面轮询也会自动触发扫描。</p>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeSettingsModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveSettings()">保存设置</button>
            </div>
        </div>
    </div>
</div>

<script>
const CURRENT_ADMIN_ID = <?= (int)$_SESSION['admin_id'] ?>;
const CLAIM_TTL = 300;
const ADMINS = <?= json_encode(array_map(function ($a) { return ['id' => (int)$a['id'], 'username' => $a['username']]; }, $admins), JSON_UNESCAPED_UNICODE) ?>;
// 本页举报基础信息（指派/向导使用）
const PAGE_REPORTS = <?= json_encode(array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'status' => (int)$r['status'],
        'type' => getReportTypeLabel($r['report_type']),
        'title' => $r['message_title'] ? mb_substr($r['message_title'], 0, 20) : '留言已删除',
        'nickname' => $r['message_nickname'] ?? '',
        'assigned_to' => $r['assigned_to'] ? (int)$r['assigned_to'] : 0,
    ];
}, $reports), JSON_UNESCAPED_UNICODE) ?>;

/* ---------------- 多选与批量栏 ---------------- */
function getSelectedIds() {
    return Array.from(document.querySelectorAll('.js-row-check:checked')).map(c => parseInt(c.dataset.id));
}

function refreshBatchBar() {
    const ids = getSelectedIds();
    document.getElementById('batchBar').classList.toggle('hidden', ids.length === 0);
    document.getElementById('selectedCount').textContent = ids.length;
}

function clearSelection() {
    document.querySelectorAll('.js-row-check:checked').forEach(c => c.checked = false);
    document.getElementById('checkAll').checked = false;
    refreshBatchBar();
}

document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.js-row-check:not(:disabled)').forEach(c => c.checked = this.checked);
    refreshBatchBar();
});
document.querySelectorAll('.js-row-check').forEach(c => c.addEventListener('change', refreshBatchBar));

/* ---------------- 单条处理：先认领加锁，再填备注 ---------------- */
let pendingProcessId = null;
let pendingProcessStatus = null;

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, ch =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function apiPost(body) {
    return fetch('api.php', {method: 'POST', body}).then(r => r.json());
}

function viewReport(id) {
    document.getElementById('reportViewModal').style.display = 'flex';
    document.getElementById('reportViewBody').innerHTML = '加载中...';
    fetch('api.php?action=report_detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0) { document.getElementById('reportViewBody').textContent = data.msg; return; }
        const d = data.data;
        const logActionMap = {assign:'指派', claim:'认领', release:'解锁', process:'处置', escalate:'系统升级'};
        let html = '<div class="detail-view">';
        html += '<p><strong>举报ID：</strong>' + d.id + '</p>';
        html += '<p><strong>举报类型：</strong><span class="badge badge-' + d.report_type + '">' + esc(d.report_type_label) + '</span></p>';
        html += '<p><strong>举报时间：</strong>' + esc(d.created_at) + '</p>';
        html += '<p><strong>举报状态：</strong><span class="status-badge report-status-' + d.status_class + '">' + esc(d.status_label) + '</span></p>';
        if (d.escalation_level > 0) {
            html += '<p><strong>升级级别：</strong><span class="badge ' + (d.escalation_level >= 2 ? 'badge-esc2' : 'badge-esc1') + '">' + esc(d.escalation_label) + '</span></p>';
        }
        if (d.due_at) html += '<p><strong>处理截止：</strong>' + esc(d.due_at) + '</p>';
        if (d.assignee_name) {
            html += '<p><strong>指派处理人：</strong>' + esc(d.assignee_name) +
                    (d.assigner_name ? '（由 ' + esc(d.assigner_name) + ' 指派' + (d.assigned_at ? '，' + esc(d.assigned_at) : '') + '）' : '') + '</p>';
        }
        if (d.claim_active) {
            html += '<p><span class="tag-claim">🔒 ' + esc(d.claim_name) + ' 正在处理' + (d.claim_by_me ? '（你）' : '') + '</span></p>';
        }
        if (d.description) {
            html += '<p><strong>举报说明：</strong></p><div class="detail-text">' + d.description + '</div>';
        }
        html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
        html += '<h4 style="margin-bottom: 12px;">被举报留言信息</h4>';
        if (d.message_exists) {
            html += '<p><strong>留言标题：</strong>' + d.message_title + '</p>';
            html += '<p><strong>留言作者：</strong>' + d.message_nickname + '</p>';
            html += '<p><strong>留言类型：</strong>' + esc(d.message_type_label) + '</p>';
            html += '<p><strong>留言内容：</strong></p><div class="detail-text">' + d.message_content + '</div>';
            if (d.message_image) {
                html += '<p><strong>留言图片：</strong><br><img src="../' + esc(d.message_image) + '" style="max-width:100%;margin-top:8px;"></p>';
            }
            html += '<p><a href="../detail.php?id=' + d.message_id + '" target="_blank" class="btn btn-sm btn-info">查看原留言</a></p>';
        } else {
            html += '<p class="text-muted">该留言已被删除（删除结果与处置记录保留）</p>';
        }
        if (d.status > 0) {
            html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
            html += '<h4 style="margin-bottom: 12px;">处理信息</h4>';
            html += '<p><strong>处理人：</strong>' + (d.admin_name ? esc(d.admin_name) : '-') + '</p>';
            html += '<p><strong>处理时间：</strong>' + (d.processed_at ? esc(d.processed_at) : '-') + '</p>';
            html += '<p><strong>处理备注：</strong></p><div class="detail-text">' + (d.process_note ? d.process_note : '<span class="text-muted">无</span>') + '</div>';
            html += '<p class="tag-due">该状态与备注对举报人前台同步可见。</p>';
        }
        if (d.logs && d.logs.length) {
            html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
            html += '<h4 style="margin-bottom: 8px;">操作记录</h4><ul class="log-timeline">';
            d.logs.forEach(l => {
                html += '<li><span class="log-time">' + esc(l.created_at) + '</span>'
                    + '<strong>' + esc(l.admin_name || '系统') + '</strong> '
                    + esc(logActionMap[l.action] || l.action)
                    + (l.detail ? '：' + esc(l.detail) : '') + '</li>';
            });
            html += '</ul>';
        }
        html += '</div>';
        document.getElementById('reportViewBody').innerHTML = html;
    });
}
function closeReportViewModal() { document.getElementById('reportViewModal').style.display = 'none'; }

function processReport(id, status) {
    // 先认领：若已被其他人处理或锁定，立即得到反馈
    const fd = new FormData();
    fd.append('action', 'report_claim');
    fd.append('id', id);
    apiPost(fd).then(data => {
        if (data.code === 2) { alert(data.msg); return; }
        if (data.code === 3) { alert(data.msg); return; }
        if (data.code !== 0) { alert(data.msg); return; }

        let actionText = {1:'删除留言并标记为已处理', 2:'忽略此举报', 3:'驳回此举报'}[status];
        if (!confirm('确定要' + actionText + '吗？')) {
            // 放弃处置，释放锁
            const rel = new FormData();
            rel.append('action', 'report_release');
            rel.append('id', id);
            apiPost(rel);
            return;
        }
        pendingProcessId = id;
        pendingProcessStatus = status;
        document.getElementById('processNoteTitle').textContent =
            {1:'（删除留言）', 2:'（忽略举报）', 3:'（驳回举报）'}[status];
        document.getElementById('processNote').value = '';
        document.getElementById('processNoteModal').style.display = 'flex';
    });
}

function cancelProcessNote() {
    if (pendingProcessId) {
        const rel = new FormData();
        rel.append('action', 'report_release');
        rel.append('id', pendingProcessId);
        apiPost(rel);
    }
    closeProcessNoteModal();
}
function closeProcessNoteModal() {
    document.getElementById('processNoteModal').style.display = 'none';
    pendingProcessId = null;
    pendingProcessStatus = null;
}

function confirmProcess() {
    if (!pendingProcessId || !pendingProcessStatus) return;
    const note = document.getElementById('processNote').value;
    const fd = new FormData();
    fd.append('action', 'process_report');
    fd.append('id', pendingProcessId);
    fd.append('status', pendingProcessStatus);
    fd.append('note', note);
    apiPost(fd).then(data => {
        if (data.code === 0) {
            alert('操作成功');
            closeProcessNoteModal();
            if (data.data && data.data.counters) updateCounters(data.data.counters);
            location.reload();
        } else if (data.code === 2 || data.code === 3) {
            alert(data.msg);
            closeProcessNoteModal();
        } else {
            alert(data.msg);
        }
    });
}

/* ---------------- 指派 ---------------- */
function quickAssign(sel) {
    const id = parseInt(sel.dataset.id);
    const adminId = parseInt(sel.value) || 0;
    const adminName = adminId ? (ADMINS.find(a => a.id === adminId) || {}).username : '';
    if (!confirm(adminId ? '确定指派给「' + adminName + '」吗？' : '确定取消指派吗？')) {
        // 还原需要拉取旧值，简单刷新
        location.reload();
        return;
    }
    const fd = new FormData();
    fd.append('action', 'report_assign');
    fd.append('assignments[' + id + ']', adminId);
    apiPost(fd).then(data => {
        alert(data.msg);
        if (data.code === 0 && data.data && data.data.counters) updateCounters(data.data.counters);
    }).catch(() => location.reload());
}

let assignIds = [];
function openBatchAssign() {
    assignIds = getSelectedIds();
    if (!assignIds.length) return;
    const box = document.getElementById('assignRows');
    box.innerHTML = '';
    assignIds.forEach(id => {
        const r = PAGE_REPORTS.find(x => x.id === id) || {};
        const row = document.createElement('div');
        row.className = 'wizard-card';
        row.dataset.id = id;
        let opts = '<option value="0">未指派</option>' + ADMINS.map(a =>
            '<option value="' + a.id + '"' + (r.assigned_to === a.id ? ' selected' : '') + '>' + esc(a.username) + '</option>'
        ).join('');
        row.innerHTML = '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">'
            + '<span class="badge badge-other">#' + id + '</span>'
            + '<strong>' + esc(r.title || '留言已删除') + '</strong>'
            + '<span class="tag-due">作者：' + esc(r.nickname || '-') + ' / 类型：' + esc(r.type || '') + '</span>'
            + '<select class="assignee-select js-row-assign" style="max-width:160px; margin-left:auto;">' + opts + '</select>'
            + '</div>';
        box.appendChild(row);
    });
    document.getElementById('assignNote').value = '';
    document.getElementById('assignAllSelect').value = '';
    document.getElementById('batchAssignModal').style.display = 'flex';
}
function applyAssignAll(val) {
    if (!val) return;
    document.querySelectorAll('.js-row-assign').forEach(s => s.value = val);
}
function closeBatchAssign() { document.getElementById('batchAssignModal').style.display = 'none'; assignIds = []; }
function submitBatchAssign() {
    const fd = new FormData();
    fd.append('action', 'report_assign');
    fd.append('note', document.getElementById('assignNote').value);
    document.querySelectorAll('#assignRows .wizard-card').forEach(row => {
        const sel = row.querySelector('.js-row-assign');
        fd.append('assignments[' + row.dataset.id + ']', sel.value);
    });
    apiPost(fd).then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}

/* ---------------- 批量处置向导（逐条预览） ---------------- */
let wizardIds = [];
let wizardIdx = 0;
let wizardData = {};   // id => {detail, status, note, skipped}

function openBatchWizard() {
    wizardIds = getSelectedIds();
    if (!wizardIds.length) return;
    wizardIdx = 0;
    wizardData = {};
    wizardIds.forEach(id => wizardData[id] = {detail: null, status: 0, note: '', skipped: false});
    document.getElementById('wizardModal').style.display = 'flex';
    loadWizardStep();
}
function closeWizard() {
    document.getElementById('wizardModal').style.display = 'none';
    wizardIds = [];
}
function wizardGo(delta) {
    saveWizardStep();
    const next = wizardIdx + delta;
    if (next < 0 || next >= wizardIds.length) return;
    wizardIdx = next;
    loadWizardStep();
}
function wizardSkip() {
    saveWizardStep();
    wizardData[wizardIds[wizardIdx]].skipped = true;
    wizardData[wizardIds[wizardIdx]].status = 0;
    if (wizardIdx < wizardIds.length - 1) { wizardIdx++; loadWizardStep(); }
    else renderWizardBody(wizardData[wizardIds[wizardIdx]].detail);
}
function saveWizardStep() {
    const id = wizardIds[wizardIdx];
    const chosen = document.querySelector('input[name=wizAction]:checked');
    wizardData[id].status = chosen ? parseInt(chosen.value) : 0;
    const noteEl = document.getElementById('wizNote');
    wizardData[id].note = noteEl ? noteEl.value : '';
    wizardData[id].skipped = !wizardData[id].status;
    document.getElementById('wizDecided').textContent =
        wizardIds.filter(x => wizardData[x].status > 0).length;
}
function loadWizardStep() {
    const id = wizardIds[wizardIdx];
    document.getElementById('wizardBody').innerHTML = '加载中...';
    fetch('api.php?action=report_detail&id=' + id).then(r => r.json()).then(data => {
        if (data.code !== 0) {
            // 已被处理等：标记跳过
            wizardData[id].skipped = true;
            wizardData[id].status = 0;
            document.getElementById('wizardBody').innerHTML =
                '<div class="wizard-card"><p class="text-muted">#' + id + ' ' + esc(data.msg) + '，将跳过。</p></div>';
            updateWizardNav();
            return;
        }
        wizardData[id].detail = data.data;
        renderWizardBody(data.data);
    });
}
function renderWizardBody(d) {
    const id = wizardIds[wizardIdx];
    const cur = wizardData[id];
    document.getElementById('wizardProgress').textContent =
        '第 ' + (wizardIdx + 1) + ' / ' + wizardIds.length + ' 条';
    const checked = v => cur.status === v ? 'checked' : '';
    let html = '<div class="wizard-card">';
    html += '<p><strong>#' + d.id + '</strong> <span class="badge badge-' + d.report_type + '">' + esc(d.report_type_label) + '</span> '
        + '<strong>' + (d.message_title ? esc(d.message_title) : '<span class="text-muted">留言已删除</span>') + '</strong></p>';
    html += '<p class="tag-due">作者：' + esc(d.message_nickname || '-') + '；举报人说明：' + (d.description ? d.description.replace(/<br\s*\/?>/gi, ' ') : '无') + '</p>';
    if (d.message_exists) {
        html += '<div class="detail-text" style="max-height:120px; overflow-y:auto;">' + d.message_content + '</div>';
    }
    if (d.assignee_name) html += '<p class="tag-due">指派给：' + esc(d.assignee_name) + '</p>';
    html += '</div>';
    html += '<label class="wizard-option"><input type="radio" name="wizAction" value="1" ' + checked(1) + '>🗑 删除留言（举报标记为已处理-已删除）</label>';
    html += '<label class="wizard-option"><input type="radio" name="wizAction" value="2" ' + checked(2) + '>✅ 忽略举报</label>';
    html += '<label class="wizard-option"><input type="radio" name="wizAction" value="3" ' + checked(3) + '>↩️ 驳回举报</label>';
    html += '<div class="form-group" style="margin-top:8px;"><label>处理备注（可选，同步给举报人）</label>'
        + '<textarea id="wizNote" rows="2" maxlength="500">' + esc(cur.note) + '</textarea></div>';
    document.getElementById('wizardBody').innerHTML = html;
    document.querySelectorAll('input[name=wizAction]').forEach(el =>
        el.addEventListener('change', () => {
            saveWizardStep();
        }));
    updateWizardNav();
}
function updateWizardNav() {
    document.getElementById('wizPrev').disabled = wizardIdx === 0;
    document.getElementById('wizNext').disabled = wizardIdx === wizardIds.length - 1;
    saveWizardStep();
}
function submitWizard() {
    saveWizardStep();
    const decided = wizardIds.filter(x => wizardData[x].status > 0);
    if (!decided.length) { alert('请至少为一条举报选择处置方式（其他可跳过）'); return; }
    let summary = '将提交 ' + decided.length + ' 条处置：\n';
    const nameMap = {1:'删除', 2:'忽略', 3:'驳回'};
    decided.forEach(id => {
        const r = wizardData[id].detail || {};
        summary += '#' + id + ' ' + nameMap[wizardData[id].status] + ' ' + (r.message_title || '留言已删除') + '\n';
    });
    if (!confirm(summary + '\n确认整组提交？')) return;

    const fd = new FormData();
    fd.append('action', 'process_report_batch');
    decided.forEach(id => {
        fd.append('items[' + id + '][status]', wizardData[id].status);
        fd.append('items[' + id + '][note]', wizardData[id].note);
    });
    apiPost(fd).then(data => {
        if (data.code !== 0) { alert(data.msg); return; }
        let msg = data.msg + '\n';
        const results = (data.data && data.data.results) || {};
        Object.keys(results).forEach(rid => {
            if (!results[rid].ok) msg += '#' + rid + ' 失败：' + results[rid].msg + '\n';
        });
        alert(msg);
        location.reload();
    });
}

/* ---------------- 超时升级设置 ---------------- */
function openSettingsModal() { document.getElementById('settingsModal').style.display = 'flex'; }
function closeSettingsModal() { document.getElementById('settingsModal').style.display = 'none'; }
function saveSettings() {
    const fd = new FormData();
    fd.append('action', 'report_settings_save');
    fd.append('escalation1_hours', document.getElementById('setH1').value);
    fd.append('escalation2_hours', document.getElementById('setH2').value);
    fd.append('escalation1_admin_id', document.getElementById('setA1').value);
    fd.append('escalation2_admin_id', document.getElementById('setA2').value);
    apiPost(fd).then(data => { alert(data.msg); if (data.code === 0) closeSettingsModal(); });
}

/* ---------------- 轮询：状态变化、锁定、升级、计数实时同步 ---------------- */
function updateCounters(c) {
    if (!c) return;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('statTotal', c.total);
    set('statPending', c.pending);
    set('statDeleted', c.deleted);
    set('statIgnored', (c.ignored || 0) + (c.rejected || 0));
    set('statEscalated', c.escalated);
}

function statusLabel(s) { return {0:'待处理',1:'已处理-已删除',2:'已处理-已忽略',3:'已驳回'}[s] || '未知'; }
function statusClass(s) { return {0:'pending',1:'resolved-deleted',2:'resolved-ignored',3:'rejected'}[s] || ''; }

function applyPollItem(it) {
    const row = document.querySelector('tr.js-row[data-id="' + it.id + '"]');
    if (!row) return;
    const oldStatus = parseInt(row.dataset.status);
    const newStatus = parseInt(it.status);

    // 状态被其他人改变：更新徽标、处理人、操作按钮、勾选框
    if (oldStatus !== newStatus) {
        row.dataset.status = newStatus;
        const st = row.querySelector('.js-status .status-badge');
        st.className = 'status-badge report-status-' + statusClass(newStatus);
        st.textContent = statusLabel(newStatus);
        const proc = row.querySelector('.js-processor');
        if (proc) proc.textContent = it.processor_name || '-';
        if (newStatus !== 0) {
            const cb = row.querySelector('.js-row-check');
            if (cb) { cb.checked = false; cb.disabled = true; }
            const acts = row.querySelector('.js-actions');
            if (acts) acts.innerHTML = '<button class="btn btn-xs btn-info" onclick="viewReport(' + it.id + ')">查看</button>';
            // 指派单元格转为纯文本
            const asCell = row.querySelector('.js-assignee');
            if (asCell) asCell.textContent = it.assignee_name || '-';
            row.classList.remove('row-locked');
            refreshBatchBar();
        }
    }

    // 认领锁变化：显示/清除"某某处理中"，锁定时禁用他人操作
    const statusCell = row.querySelector('.js-status');
    let tag = statusCell.querySelector('.tag-claim');
    row.dataset.claimBy = it.claim_by || '';
    row.dataset.claimAt = it.claim_at || '';
    if (it.claim_by && it.claim_active && newStatus === 0) {
        if (!tag) {
            tag = document.createElement('span');
            tag.className = 'tag-claim js-claim-tag';
            statusCell.appendChild(tag);
        }
        tag.textContent = '🔒 ' + (it.claim_name || '处理人') + '处理中';
        const byOther = parseInt(it.claim_by) !== CURRENT_ADMIN_ID;
        row.classList.toggle('row-locked', byOther);
        row.querySelectorAll('.js-act').forEach(b => b.disabled = byOther);
        const cb = row.querySelector('.js-row-check');
        if (cb && byOther) { cb.checked = false; cb.disabled = true; refreshBatchBar(); }
    } else if (tag) {
        tag.remove();
        row.classList.remove('row-locked');
        row.querySelectorAll('.js-act').forEach(b => b.disabled = false);
        const cb = row.querySelector('.js-row-check');
        if (cb && newStatus === 0) cb.disabled = false;
    }

    // 指派变化（仍待处理时更新下拉）
    if (newStatus === 0) {
        const sel = row.querySelector('.js-assign-select');
        if (sel && String(it.assigned_to || 0) !== sel.value) sel.value = it.assigned_to || 0;
    }

    // 升级级别/截止变化
    const escCell = row.querySelector('.js-escalation');
    if (escCell) {
        const lvl = parseInt(it.escalation_level || 0);
        let html = lvl > 0
            ? '<span class="badge ' + (lvl >= 2 ? 'badge-esc2' : 'badge-esc1') + '">'
              + (lvl >= 2 ? '二级升级' : '一级升级') + '</span>'
            : '<span class="tag-due">普通</span>';
        if (newStatus === 0 && it.due_at) {
            const od = !!it.is_overdue;
            const mm = (it.due_at || '').slice(5, 16);
            html += '<div class="tag-due ' + (od ? 'overdue' : '') + '">' + (od ? '已超时' : '截止') + ' ' + mm + '</div>';
        }
        escCell.innerHTML = html;
    }
}

function pollReports() {
    const ids = Array.from(document.querySelectorAll('tr.js-row')).map(r => parseInt(r.dataset.id));
    if (!ids.length) return;
    fetch('api.php?action=report_poll&ids=' + ids.join(','))
    .then(r => r.json())
    .then(data => {
        if (data.code !== 0 || !data.data) return;
        (data.data.items || []).forEach(applyPollItem);
        updateCounters(data.data.counters);
    })
    .catch(() => {});
}
setInterval(pollReports, 10000);

/* 弹窗点击遮罩关闭 */
['reportViewModal','processNoteModal','batchAssignModal','wizardModal','settingsModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function (e) {
        if (e.target === this) {
            if (id === 'processNoteModal') cancelProcessNote();
            else if (id === 'wizardModal') closeWizard();
            else this.style.display = 'none';
        }
    });
});
</script>
</body>
</html>
