<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 增加浏览量
$db->prepare("UPDATE messages SET views = views + 1 WHERE id = ?")->execute([$id]);

// 获取详情
$stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1");
$stmt->execute([$id]);
$msg = $stmt->fetch();

if (!$msg) {
    header('Location: index.php');
    exit;
}

$pageTitle = cleanInput($msg['title']) . ' - 社区便民留言板';
$currentPage = '';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                <div class="detail-meta">
                    <span>👤 <?= cleanInput($msg['nickname']) ?></span>
                    <span>🕐 <?= $msg['created_at'] ?></span>
                    <span>👁 <?= $msg['views'] ?> 次浏览</span>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <div class="detail-content">
                <?= nl2br(cleanInput($msg['content'])) ?>
            </div>

            <?php if ($msg['image']): ?>
            <div class="detail-image">
                <img src="<?= cleanInput($msg['image']) ?>" alt="留言图片" onclick="window.open(this.src)">
            </div>
            <?php endif; ?>

            <?php if ($msg['phone']): ?>
            <div class="detail-contact">
                <span>📞 联系方式：<?= cleanInput($msg['phone']) ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-actions">
                <a href="index.php" class="btn btn-secondary">← 返回列表</a>
                <?php $isFav = isFavorited($msg['id']); ?>
                <button class="btn favorite-detail-btn <?= $isFav ? 'btn-warning' : 'btn-secondary' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= $isFav ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= $isFav ? '已收藏' : '收藏' ?></span>
                </button>
                <?php $myReport = getVisitorReport($msg['id']); ?>
                <?php if ($myReport): ?>
                <button class="btn btn-secondary report-btn" data-message-id="<?= $msg['id'] ?>" disabled>
                    <span>🚩</span>
                    <span class="report-text"><?= getReportStatusLabel($myReport['status']) ?></span>
                </button>
                <?php else: ?>
                <button class="btn btn-danger report-btn" data-message-id="<?= $msg['id'] ?>" onclick="openReportModal(<?= $msg['id'] ?>)">
                    <span>🚩</span>
                    <span class="report-text">举报</span>
                </button>
                <?php endif; ?>
                <a href="my_reports.php" class="btn btn-secondary">我的举报</a>
                <a href="submit.php" class="btn btn-primary">发布留言</a>
            </div>
            <?php if ($myReport): ?>
            <div class="report-result" style="margin-top:12px; padding:10px 14px; background:#f9fafb; border-left:3px solid #3b82f6; border-radius:4px; font-size:.9rem;">
                <div>
                    🚩 <strong>我的举报：</strong>
                    <span class="status-badge report-status-<?= getReportStatusClass($myReport['status']) ?>"><?= getReportStatusLabel($myReport['status']) ?></span>
                    <?php if ($myReport['escalation_level'] > 0 && $myReport['status'] == 0): ?>
                    <span style="color:#b45309; margin-left:6px;">（<?= getEscalationLevelLabel($myReport['escalation_level']) ?>，正在加急处理）</span>
                    <?php endif; ?>
                </div>
                <?php if ($myReport['status'] == 1): ?>
                <div class="text-muted">该留言已被平台删除，感谢你的监督。</div>
                <?php elseif ($myReport['status'] == 0): ?>
                <div class="text-muted">平台正在核实处理，请耐心等待。<a href="my_reports.php">查看全部举报记录 →</a></div>
                <?php endif; ?>
                <?php if ($myReport['process_note'] !== null && $myReport['process_note'] !== ''): ?>
                <div style="margin-top:4px;"><strong>处理备注：</strong><?= nl2br(cleanInput($myReport['process_note'])) ?></div>
                <?php endif; ?>
                <?php if ($myReport['processed_at']): ?>
                <div class="text-muted">处理时间：<?= cleanInput($myReport['processed_at']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 举报弹窗 -->
<div class="modal" id="reportModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🚩 举报留言</h3>
            <button class="modal-close" onclick="closeReportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reportForm">
                <input type="hidden" id="reportMessageId" name="message_id">
                <div class="form-group">
                    <label>举报类型 <span class="required">*</span></label>
                    <div class="report-type-options">
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="spam" required>
                            <span>🗑️ 垃圾信息</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="abuse">
                            <span>😡 辱骂攻击</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="illegal">
                            <span>⚖️ 违法违规</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="porn">
                            <span>🔞 色情低俗</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="other">
                            <span>📝 其他</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reportDescription">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="reportDescription" name="description" rows="4" maxlength="500" placeholder="请描述具体的违规内容，帮助我们更好地处理..."></textarea>
                    <span class="char-count"><span id="reportDescCount">0</span>/500</span>
                </div>
                <div class="form-tip">
                    <p>⚠️ 恶意举报将被限制功能使用，请如实填写举报内容。</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()">取消</button>
                    <button type="submit" class="btn btn-danger" id="reportSubmitBtn">提交举报</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
