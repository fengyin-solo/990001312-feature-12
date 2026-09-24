<?php
/**
 * 举报超时升级定时任务
 *
 * 用法（建议 crontab 每小时执行一次）：
 *   php /path/to/cli/escalate_reports.php
 *
 * 阈值配置见 config/report.php
 */

if (PHP_SAPI !== 'cli') {
    die("仅限命令行执行\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report.php';
require_once __DIR__ . '/../config/database.php';

$time = date('Y-m-d H:i:s');
$count = runReportEscalations(true);

if ($count > 0) {
    echo "[{$time}] 本次升级举报 {$count} 条\n";
} else {
    echo "[{$time}] 无超时举报需要升级\n";
}
