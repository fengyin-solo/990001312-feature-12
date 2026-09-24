-- 举报协同指派与超时升级迁移脚本
-- 执行此 SQL 来为举报功能添加协同指派、超时升级、操作日志所需的表结构
--
-- 注意：
-- 1. 原 messages 外键为 ON DELETE CASCADE，删除留言会连带删除举报记录，
--    导致"已删除"结果与处理备注无法留存。这里改为 ON DELETE SET NULL，
--    并把 message_id 调整为可空，保证删除结果、备注与前台状态一致。
-- 2. 若外键约束名不是 MySQL 默认的 reports_ibfk_1，可先用
--    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
--    WHERE TABLE_SCHEMA='community_board' AND TABLE_NAME='reports'
--      AND COLUMN_NAME='message_id' AND REFERENCED_TABLE_NAME='messages';
--    查到实际名称后替换下方语句。

USE `community_board`;

-- 1. 解除 message_id 旧外键，改为可空并重建为 SET NULL
ALTER TABLE `reports` DROP FOREIGN KEY `reports_ibfk_1`;
ALTER TABLE `reports` MODIFY `message_id` INT UNSIGNED NULL COMMENT '被举报的留言ID（留言删除后置空，举报记录保留）';
ALTER TABLE `reports` ADD CONSTRAINT `fk_reports_message`
    FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL;

-- 2. 协同指派与超时升级字段
ALTER TABLE `reports`
    ADD COLUMN `assigned_to` INT UNSIGNED DEFAULT NULL COMMENT '指派处理人管理员ID' AFTER `processed_by`,
    ADD COLUMN `assigned_by` INT UNSIGNED DEFAULT NULL COMMENT '指派人管理员ID' AFTER `assigned_to`,
    ADD COLUMN `assigned_at` DATETIME DEFAULT NULL COMMENT '指派时间' AFTER `assigned_by`,
    ADD COLUMN `claim_by` INT UNSIGNED DEFAULT NULL COMMENT '当前锁定（认领）处理人，处理完成后清空' AFTER `assigned_at`,
    ADD COLUMN `claim_at` DATETIME DEFAULT NULL COMMENT '认领（加锁）时间，超时锁自动失效' AFTER `claim_by`,
    ADD COLUMN `escalation_level` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '升级级别: 0普通, 1一级升级, 2二级升级' AFTER `claim_at`,
    ADD COLUMN `escalated_at` DATETIME DEFAULT NULL COMMENT '最近一次升级时间' AFTER `escalation_level`,
    ADD COLUMN `due_at` DATETIME DEFAULT NULL COMMENT '当前级别处理截止时间，超过即触发升级' AFTER `escalated_at`,
    ADD INDEX `idx_assigned_to` (`assigned_to`),
    ADD INDEX `idx_escalation` (`escalation_level`, `due_at`);

ALTER TABLE `reports`
    ADD CONSTRAINT `fk_reports_assigned_to`
    FOREIGN KEY (`assigned_to`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_reports_assigned_by`
    FOREIGN KEY (`assigned_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_reports_claim_by`
    FOREIGN KEY (`claim_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

-- 3. 举报设置表（升级阈值等）
CREATE TABLE IF NOT EXISTS `report_settings` (
    `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `escalation1_hours` INT UNSIGNED NOT NULL DEFAULT 24 COMMENT '一级升级阈值（小时），0表示不启用',
    `escalation2_hours` INT UNSIGNED NOT NULL DEFAULT 72 COMMENT '二级升级阈值（小时），0表示不启用',
    `escalation1_admin_id` INT UNSIGNED DEFAULT NULL COMMENT '一级升级通知/指派的管理员ID',
    `escalation2_admin_id` INT UNSIGNED DEFAULT NULL COMMENT '二级升级通知/指派的管理员ID',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_settings_esc1_admin` FOREIGN KEY (`escalation1_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_settings_esc2_admin` FOREIGN KEY (`escalation2_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报功能设置';

INSERT INTO `report_settings` (`id`) VALUES (1)
    ON DUPLICATE KEY UPDATE `id` = `id`;

-- 4. 举报操作日志表（指派、认领、升级、处理全程留痕）
CREATE TABLE IF NOT EXISTS `report_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id` INT UNSIGNED NOT NULL COMMENT '举报ID',
    `admin_id` INT UNSIGNED DEFAULT NULL COMMENT '操作管理员ID，0/NULL表示系统',
    `action` VARCHAR(30) NOT NULL COMMENT '操作: assign指派, claim认领, release释放, process处理, escalate升级',
    `detail` VARCHAR(500) DEFAULT NULL COMMENT '操作说明/备注',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
    INDEX `idx_report_id` (`report_id`),
    INDEX `idx_created` (`created_at`),
    CONSTRAINT `fk_logs_report` FOREIGN KEY (`report_id`) REFERENCES `reports`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报操作日志';

-- 5. 历史待处理举报补齐初始截止时间（按一级阈值）
UPDATE `reports`
SET `due_at` = DATE_ADD(`created_at`, INTERVAL (SELECT `h` FROM (
        SELECT `escalation1_hours` AS `h` FROM `report_settings` WHERE `id` = 1
    ) t) HOUR)
WHERE `status` = 0 AND `due_at` IS NULL;

-- 验证：
-- DESCRIBE reports;
-- SELECT * FROM report_settings;
-- SHOW TABLES LIKE 'report_logs';
