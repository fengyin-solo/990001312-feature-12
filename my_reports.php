<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '我的举报 - 社区便民留言板';
$currentPage = 'my_reports';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$reports = getVisitorReports();

include __DIR__ . '/includes/header.php';
?>

<section class="favorites-section">
    <div class="container">
        <div class="page-header">
            <h1>🚩 我的举报</h1>
            <p>这里展示你提交的全部举报及平台处理结果，状态与后台处置实时一致。</p>
        </div>

        <?php if (empty($reports)): ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <p>你还没有提交过举报。</p>
            <a href="index.php" class="btn btn-primary">去首页看看</a>
        </div>
        <?php else: ?>
        <div class="favorites-list">
            <?php foreach ($reports as $r): ?>
            <div class="message-card" style="margin-bottom:16px;">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                    <div>
                        <span class="badge badge-<?= $r['report_type'] ?>"><?= getReportTypeLabel($r['report_type']) ?></span>
                        <?php if ($r['escalation_level'] > 0 && $r['status'] == 0): ?>
                        <span class="badge <?= $r['escalation_level'] >= 2 ? 'badge-esc2' : 'badge-esc1' ?>" style="margin-left:6px;">
                            <?= getEscalationLevelLabel($r['escalation_level']) ?>
                        </span>
                        <?php endif; ?>
                        <small class="text-muted" style="margin-left:8px;">举报于 <?= cleanInput($r['created_at']) ?></small>
                    </div>
                    <span class="status-badge report-status-<?= getReportStatusClass($r['status']) ?>">
                        <?= getReportStatusLabel($r['status']) ?>
                    </span>
                </div>

                <div style="margin-top:10px;">
                    <?php if (!empty($r['message_title'])): ?>
                    <h3 style="margin:0 0 6px;">
                        <a href="detail.php?id=<?= (int)$r['message_id'] ?>"><?= cleanInput($r['message_title']) ?></a>
                    </h3>
                    <?php else: ?>
                    <h3 style="margin:0 0 6px; color:#6b7280;">该留言已被平台删除</h3>
                    <?php endif; ?>
                    <?php if ($r['description']): ?>
                    <p class="text-muted" style="margin:4px 0;">举报说明：<?= nl2br(cleanInput($r['description'])) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($r['status'] != 0): ?>
                <div class="detail-result" style="margin-top:10px; padding:10px 12px; background:#f9fafb; border-left:3px solid #3b82f6; border-radius:4px; font-size:.9rem;">
                    <div><strong>处理结果：</strong><?= getReportStatusLabel($r['status']) ?></div>
                    <?php if ($r['status'] == 1): ?>
                    <div class="text-muted">被举报留言已删除，感谢你的监督。</div>
                    <?php endif; ?>
                    <?php if ($r['processed_at']): ?>
                    <div class="text-muted">处理时间：<?= cleanInput($r['processed_at']) ?></div>
                    <?php endif; ?>
                    <?php if ($r['process_note'] !== null && $r['process_note'] !== ''): ?>
                    <div style="margin-top:4px;"><strong>处理备注：</strong><?= nl2br(cleanInput($r['process_note'])) ?></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-muted" style="margin-top:10px; font-size:.88rem;">
                    ⏳ 平台正在处理中
                    <?php if ($r['due_at']): ?>，预计处理时限：<?= cleanInput($r['due_at']) ?><?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.badge-esc1 { background:#fef3c7; color:#b45309; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:600; }
.badge-esc2 { background:#fee2e2; color:#b91c1c; padding:2px 8px; border-radius:10px; font-size:.75rem; font-weight:600; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
