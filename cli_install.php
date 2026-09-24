<?php
/**
 * 命令行数据库初始化脚本 - 用于创建表结构
 * 用法: php cli_install.php
 */

$host = 'localhost';
$user = 'root';
$pass = '123456';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `community_board` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `community_board`");

    // 留言表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `nickname` VARCHAR(50) NOT NULL COMMENT '昵称',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
        `type` ENUM('help','suggest','lost') NOT NULL DEFAULT 'help' COMMENT '类型: help求助, suggest建议, lost失物招领',
        `title` VARCHAR(100) NOT NULL COMMENT '标题',
        `content` TEXT NOT NULL COMMENT '内容',
        `image` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待审核, 1已通过, 2已拒绝',
        `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_type` (`type`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言表'");

    // 管理员表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表'");

    // 收藏表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `favorites` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
        `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '收藏时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_message_id` (`message_id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表'");

    // 举报表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT UNSIGNED DEFAULT NULL COMMENT '被举报的留言ID（留言删除后置空，举报记录保留）',
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '举报人访客标识',
        `report_type` VARCHAR(50) NOT NULL COMMENT '举报类型: spam垃圾信息, abuse辱骂攻击, illegal违法违规, porn色情低俗, other其他',
        `description` TEXT COMMENT '补充说明',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待处理, 1已处理-已删除, 2已处理-已忽略, 3已驳回',
        `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
        `assigned_to` INT UNSIGNED DEFAULT NULL COMMENT '指派处理人管理员ID',
        `assigned_by` INT UNSIGNED DEFAULT NULL COMMENT '指派人管理员ID',
        `assigned_at` DATETIME DEFAULT NULL COMMENT '指派时间',
        `claim_by` INT UNSIGNED DEFAULT NULL COMMENT '当前锁定（认领）处理人，处理完成后清空',
        `claim_at` DATETIME DEFAULT NULL COMMENT '认领（加锁）时间，超时锁自动失效',
        `escalation_level` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '升级级别: 0普通, 1一级升级, 2二级升级',
        `escalated_at` DATETIME DEFAULT NULL COMMENT '最近一次升级时间',
        `due_at` DATETIME DEFAULT NULL COMMENT '当前级别处理截止时间，超过即触发升级',
        `processed_at` DATETIME DEFAULT NULL COMMENT '处理时间',
        `process_note` VARCHAR(500) DEFAULT NULL COMMENT '处理备注',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '举报时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        INDEX `idx_assigned_to` (`assigned_to`),
        INDEX `idx_escalation` (`escalation_level`, `due_at`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`processed_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`assigned_to`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`assigned_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`claim_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表'");

    // 举报设置表（升级阈值等）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `report_settings` (
        `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `escalation1_hours` INT UNSIGNED NOT NULL DEFAULT 24 COMMENT '一级升级阈值（小时），0表示不启用',
        `escalation2_hours` INT UNSIGNED NOT NULL DEFAULT 72 COMMENT '二级升级阈值（小时），0表示不启用',
        `escalation1_admin_id` INT UNSIGNED DEFAULT NULL COMMENT '一级升级通知/指派的管理员ID',
        `escalation2_admin_id` INT UNSIGNED DEFAULT NULL COMMENT '二级升级通知/指派的管理员ID',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        FOREIGN KEY (`escalation1_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`escalation2_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报功能设置'");
    $pdo->exec("INSERT IGNORE INTO `report_settings` (`id`) VALUES (1)");

    // 举报操作日志表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `report_logs` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `report_id` INT UNSIGNED NOT NULL COMMENT '举报ID',
        `admin_id` INT UNSIGNED DEFAULT NULL COMMENT '操作管理员ID',
        `action` VARCHAR(30) NOT NULL COMMENT '操作: assign指派, claim认领, release释放, process处理, escalate升级',
        `detail` VARCHAR(500) DEFAULT NULL COMMENT '操作说明/备注',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
        INDEX `idx_report_id` (`report_id`),
        INDEX `idx_created` (`created_at`),
        FOREIGN KEY (`report_id`) REFERENCES `reports`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报操作日志'");

    echo "数据库表创建成功！\n";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage() . "\n");
}
