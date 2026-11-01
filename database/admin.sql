-- Hyperf Admin (Naive UI) schema + 内置种子
-- Usage: mysql -u root -p hyperf < sql/admin.sql

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Admin users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `name` VARCHAR(64) NOT NULL DEFAULT '',
    `avatar` VARCHAR(255) NOT NULL DEFAULT '',
    -- 会话版本：改密码或执行 admin:revoke 时 +1，旧 Cookie 里的指纹就失效
    `session_version` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `admin_users_username_unique` (`username`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 已装过的库补上 session_version 列（MySQL 5.7 没有 ADD COLUMN IF NOT EXISTS，用预处理判断）
SET @has_session_version := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'session_version'
);
SET @sql := IF(
    @has_session_version = 0,
    'ALTER TABLE `admin_users` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `avatar`',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 登录限流：按「用户名 + IP」记失败次数，达到上限锁定一段时间
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL DEFAULT '',
    `ip` VARCHAR(64) NOT NULL DEFAULT '',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` TIMESTAMP NULL DEFAULT NULL,
    `locked_until` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `admin_login_attempts_key_unique` (`username`, `ip`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Default password: admin123 (bcrypt, cost 10)
INSERT INTO `admin_users` (`username`, `password`, `name`)
SELECT 'admin', '$2y$10$F.PtFhcHQ9qqI1p5.iD/Q.61iQbZHVaiIrDoWgbi4qcr3yewErvE.', 'Administrator'
WHERE NOT EXISTS (SELECT 1 FROM `admin_users` WHERE `username` = 'admin');

-- ---------------------------------------------------------------------------
-- Menu tree (optional, drive the sidebar from the database)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_menu` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `order` INT NOT NULL DEFAULT 0,
    `is_menu` TINYINT(1) NOT NULL DEFAULT 1,
    `title` VARCHAR(64) NOT NULL DEFAULT '',
    `icon` VARCHAR(64) NOT NULL DEFAULT '',
    `uri` VARCHAR(255) NOT NULL DEFAULT '',
    `permission` JSON NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `admin_menu_parent_id_index` (`parent_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 内置菜单：与 MenuHelper::defaultMenu() 完全一致（同一棵树）。
--
-- 用固定 id + ON DUPLICATE KEY UPDATE，所以这段可以**重复执行**：
-- 新装会插入，已装会把这几行的层级/文案/权限刷新成最新（其余行不动）。
-- 注意：内置菜单占用 id 3 / 4 / 5 / 6 / 11 / 12，自己加菜单请用别的 id。
--
--   └─ 系统管理（分组）
--       ├─ 用户管理   user.manage
--       ├─ 角色管理   role.manage
--       ├─ 权限管理   permission.manage
--       ├─ 菜单管理   menu.manage
--       └─ 审计日志   log.view
INSERT INTO `admin_menu` (`id`, `parent_id`, `order`, `is_menu`, `title`, `icon`, `uri`, `permission`) VALUES
    ( 3, 0, 1, 0, '系统管理', 'settings', '',           NULL),
    (11, 3, 1, 1, '用户管理', 'user',     'user',       '[5]'),
    ( 5, 3, 2, 1, '角色管理', 'settings', 'role',       '[3]'),
    ( 6, 3, 3, 1, '权限管理', 'document', 'permission', '[4]'),
    ( 4, 3, 4, 1, '菜单管理', 'menu',     'menu',       '[2]'),
    (12, 3, 5, 1, '审计日志', 'table',    'log',        '[6]')
ON DUPLICATE KEY UPDATE
    `parent_id`  = VALUES(`parent_id`),
    `order`      = VALUES(`order`),
    `is_menu`    = VALUES(`is_menu`),
    `title`      = VALUES(`title`),
    `icon`       = VALUES(`icon`),
    `uri`        = VALUES(`uri`),
    `permission` = VALUES(`permission`);

-- 下线老库里的内置菜单（表格 id=1 / 组件示例 id=2 / 子菜单整组 id=7,8,9,10）。
-- 只删这 6 个固定 id，自己加的菜单不受影响；可重复执行。
DELETE FROM `admin_menu` WHERE `id` IN (1, 2, 7, 8, 9, 10);

-- ---------------------------------------------------------------------------
-- RBAC：权限 / 角色 / 中间表
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL DEFAULT '',
    `slug` VARCHAR(64) NOT NULL DEFAULT '',
    `remark` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `admin_permissions_slug_unique` (`slug`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL DEFAULT '',
    `slug` VARCHAR(64) NOT NULL DEFAULT '',
    `remark` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `admin_roles_slug_unique` (`slug`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_role_permission` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    KEY `admin_role_permission_permission_id_index` (`permission_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_role_user` (
    `role_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `user_id`),
    KEY `admin_role_user_user_id_index` (`user_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 权限字典（id 1 曾用于已下线的 demo.view，编号保留不复用）
INSERT INTO `admin_permissions` (`id`, `name`, `slug`, `remark`)
SELECT * FROM (
    SELECT 2 AS `id`, '菜单管理' AS `name`, 'menu.manage' AS `slug`, '菜单增删改' AS `remark`
    UNION ALL SELECT 3, '角色管理', 'role.manage', '角色增删改 + 分配权限/用户'
    UNION ALL SELECT 4, '权限管理', 'permission.manage', '权限增删改'
    UNION ALL SELECT 5, '用户管理', 'user.manage', '用户增删改 + 分配角色'
    UNION ALL SELECT 6, '审计日志', 'log.view', '查看操作日志'
) AS `permission_seed`
WHERE NOT EXISTS (SELECT 1 FROM `admin_permissions` LIMIT 1);

-- 已装过的库补上新增的两条权限（可重复执行）
INSERT INTO `admin_permissions` (`id`, `name`, `slug`, `remark`) VALUES
    (5, '用户管理', 'user.manage', '用户增删改 + 分配角色'),
    (6, '审计日志', 'log.view', '查看操作日志')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `slug` = VALUES(`slug`), `remark` = VALUES(`remark`);

-- 演示页已下线：清掉老库里的 demo.view 权限与它的角色关联（可重复执行）
DELETE FROM `admin_role_permission` WHERE `permission_id` IN (
    SELECT `id` FROM (SELECT `id` FROM `admin_permissions` WHERE `slug` = 'demo.view') AS `demo_perm`
);
DELETE FROM `admin_permissions` WHERE `slug` = 'demo.view';

-- 角色：只发一个超级管理员，其余角色由使用者按需创建
INSERT INTO `admin_roles` (`id`, `name`, `slug`, `remark`)
SELECT * FROM (
    SELECT 1 AS `id`, '超级管理员' AS `name`, 'super-admin' AS `slug`, '拥有全部权限' AS `remark`
) AS `role_seed`
WHERE NOT EXISTS (SELECT 1 FROM `admin_roles` LIMIT 1);

INSERT INTO `admin_role_permission` (`role_id`, `permission_id`)
VALUES
    (1, 2), (1, 3), (1, 4), (1, 5), (1, 6)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- admin(1) → 超级管理员
INSERT INTO `admin_role_user` (`role_id`, `user_id`)
VALUES (1, 1)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- 升级：老库里内置的演示账号与它的角色（0.0.1 起不再发这些），可重复执行
DELETE FROM `admin_role_user` WHERE `role_id` IN (
    SELECT `id` FROM (SELECT `id` FROM `admin_roles` WHERE `slug` = 'viewer') AS `viewer_role`
);
DELETE FROM `admin_role_permission` WHERE `role_id` IN (
    SELECT `id` FROM (SELECT `id` FROM `admin_roles` WHERE `slug` = 'viewer') AS `viewer_role`
);
DELETE FROM `admin_roles` WHERE `slug` = 'viewer';
DELETE FROM `admin_users` WHERE `username` = 'viewer';

-- ---------------------------------------------------------------------------
-- 审计日志：登录、增删改都会写一条（见 Helper\AuditLog 与 AuditLogMiddleware）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `username` VARCHAR(64) NOT NULL DEFAULT '',
    `action` VARCHAR(32) NOT NULL DEFAULT '',
    `method` VARCHAR(8) NOT NULL DEFAULT '',
    `path` VARCHAR(255) NOT NULL DEFAULT '',
    `target` VARCHAR(128) NOT NULL DEFAULT '',
    `target_id` VARCHAR(64) NOT NULL DEFAULT '',
    `summary` VARCHAR(255) NOT NULL DEFAULT '',
    `payload` JSON NULL,
    `ip` VARCHAR(64) NOT NULL DEFAULT '',
    `ua` VARCHAR(255) NOT NULL DEFAULT '',
    `status` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `admin_logs_user_id_index` (`user_id`),
    KEY `admin_logs_path_index` (`path`),
    KEY `admin_logs_created_at_index` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 升级：老库里的 uri 可能是 `user` / `/user` / `/admin/user` 三种写法，
-- 统一成「不含 api_base 前缀、不含前导斜杠」的相对写法（幂等，可重复执行）。
-- ---------------------------------------------------------------------------
UPDATE `admin_menu`
SET `uri` = TRIM(LEADING '/' FROM REPLACE(`uri`, '/admin/', ''))
WHERE `uri` <> '' AND (`uri` LIKE '/admin/%' OR `uri` LIKE '/%');
