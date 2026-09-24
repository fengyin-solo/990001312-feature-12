<?php
/**
 * 举报超时升级定时任务
 *
 * 用法（服务器 crontab，建议每小时执行一次）：
 *   0 * * * * php /path/to/community_board/cron_escalate.php
 *
 * 也支持 Web 触发（无 CLI 环境时）：
 *   http://your-domain/cron_escalate.php?key=在本文件中配置的密钥
 */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

// Web 触发密钥：留空则禁止 Web 访问，仅允许命令行
define('CRON_WEB_KEY', '');

if (php_sapi_name() !== 'cli') {
    if (CRON_WEB_KEY === '' || ($_GET['key'] ?? '') !== CRON_WEB_KEY) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $count = runReportEscalations();
    echo '[' . date('Y-m-d H:i:s') . "] 举报超时升级扫描完成，本次升级 {$count} 条\n";
} catch (Exception $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] 升级扫描失败: ' . $e->getMessage() . "\n");
    exit(1);
}
