-- 举报协同指派与超时升级迁移脚本
-- 在已有 migration_add_reports.sql 基础上执行
-- 推荐使用 mysql 客户端执行（含触发器，需要 DELIMITER 支持）：
--   mysql -u root -p community_board < migration_report_collaboration.sql

USE `community_board`;

-- reports 表增加：协同指派、编辑锁、超时升级字段
ALTER TABLE `reports`
    ADD COLUMN `assignee_id` INT UNSIGNED DEFAULT NULL COMMENT '指派处理人管理员ID' AFTER `processed_by`,
    ADD COLUMN `assigned_at` DATETIME DEFAULT NULL COMMENT '最近一次指派时间' AFTER `assignee_id`,
    ADD COLUMN `locked_by` INT UNSIGNED DEFAULT NULL COMMENT '正在编辑（持有处理锁）的管理员ID' AFTER `assigned_at`,
    ADD COLUMN `locked_at` DATETIME DEFAULT NULL COMMENT '处理锁获取时间' AFTER `locked_by`,
    ADD COLUMN `priority` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '优先级: 0普通, 1一级升级, 2二级升级, 3三级升级' AFTER `locked_at`,
    ADD COLUMN `escalated_at` DATETIME DEFAULT NULL COMMENT '最近一次升级时间' AFTER `priority`,
    ADD COLUMN `deadline` DATETIME DEFAULT NULL COMMENT '当前级别处理截止时间' AFTER `escalated_at`,
    ADD INDEX `idx_assignee` (`assignee_id`),
    ADD INDEX `idx_priority` (`priority`),
    ADD INDEX `idx_deadline` (`status`, `deadline`);

ALTER TABLE `reports`
    ADD CONSTRAINT `fk_reports_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_reports_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;

-- 举报操作日志表：指派 / 升级 / 处理全程留痕
CREATE TABLE IF NOT EXISTS `report_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `report_id` INT UNSIGNED NOT NULL COMMENT '举报ID',
    `admin_id` INT UNSIGNED DEFAULT NULL COMMENT '操作管理员ID（超时升级为NULL=系统）',
    `action` VARCHAR(30) NOT NULL COMMENT '动作: assign指派, escalate升级, process处理, lock加锁, unlock释放',
    `detail` VARCHAR(500) DEFAULT NULL COMMENT '动作详情（如 指派人、目标状态、备注）',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
    INDEX `idx_report_id` (`report_id`),
    INDEX `idx_created` (`created_at`),
    FOREIGN KEY (`report_id`) REFERENCES `reports`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报操作日志表';

-- 给历史待处理数据补齐默认截止时间（取举报时间 + 一级阈值，24小时）
UPDATE `reports` SET `deadline` = DATE_ADD(`created_at`, INTERVAL 24 HOUR)
WHERE `status` = 0 AND `deadline` IS NULL;

-- 新举报自动设置初始截止时间（举报时间 + 一级阈值 24 小时，与 config/report.php 保持一致）
DROP TRIGGER IF EXISTS `tr_reports_before_insert`;
DELIMITER //
CREATE TRIGGER `tr_reports_before_insert` BEFORE INSERT ON `reports`
FOR EACH ROW
BEGIN
    IF NEW.`deadline` IS NULL THEN
        SET NEW.`deadline` = DATE_ADD(NEW.`created_at`, INTERVAL 24 HOUR);
    END IF;
END//
DELIMITER ;

-- 验证：
-- DESCRIBE reports;
-- SHOW TABLES LIKE 'report_logs';
-- SHOW TRIGGERS LIKE 'reports';
