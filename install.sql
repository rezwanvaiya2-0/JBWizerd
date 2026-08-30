-- JBWizerd Panel - MySQL schema
-- Run via setup.php (recommended) or import manually.
-- InnoDB + proper indexes for 1-year data retention.

CREATE TABLE IF NOT EXISTS servers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    ip VARCHAR(64) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    server_group VARCHAR(100) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_servers_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    server_id INT UNSIGNED NOT NULL,
    backup_id VARCHAR(255) DEFAULT NULL,
    server_name VARCHAR(255) DEFAULT NULL,
    cpanel_user VARCHAR(255) DEFAULT NULL,
    destination VARCHAR(150) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    start_time DATETIME DEFAULT NULL,
    end_time DATETIME DEFAULT NULL,
    error TEXT DEFAULT NULL,
    error_log MEDIUMTEXT DEFAULT NULL,
    disk_used VARCHAR(30) DEFAULT NULL,
    disk_free VARCHAR(30) DEFAULT NULL,
    disk_total VARCHAR(30) DEFAULT NULL,
    disk_used_pct VARCHAR(10) DEFAULT NULL,
    duration VARCHAR(60) DEFAULT NULL,
    progress VARCHAR(30) DEFAULT NULL,
    webhook_sent TINYINT(1) NOT NULL DEFAULT 0,
    payload JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_backups_day (start_time),
    INDEX idx_backups_server_day (server_id, start_time),
    INDEX idx_backups_status (status, start_time),
    INDEX idx_backups_user (cpanel_user),
    INDEX idx_backups_bid (server_id, backup_id),
    INDEX idx_backups_server_status (server_id, status, start_time),
    INDEX idx_backups_server_status_id (server_id, status, id),
    INDEX idx_backups_created (created_at),
    FULLTEXT INDEX ft_backups_search (server_name, cpanel_user, destination, backup_id),
    CONSTRAINT fk_backups_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhooks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(500) NOT NULL,
    format VARCHAR(20) NOT NULL DEFAULT 'generic',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_triggered_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT UNSIGNED NOT NULL,
    event VARCHAR(50) NOT NULL,
    payload MEDIUMTEXT DEFAULT NULL,
    status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_pending (status, next_attempt_at),
    CONSTRAINT fk_queue_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS webhook_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT UNSIGNED NOT NULL,
    event VARCHAR(50) DEFAULT NULL,
    server_name VARCHAR(255) DEFAULT NULL,
    cpanel_user VARCHAR(255) DEFAULT NULL,
    status ENUM('success','failed') NOT NULL,
    http_status INT UNSIGNED DEFAULT NULL,
    response TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_logs_created (created_at),
    INDEX idx_logs_webhook (webhook_id, created_at),
    CONSTRAINT fk_logs_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    password_history TEXT DEFAULT NULL,
    role ENUM('admin','member') NOT NULL DEFAULT 'member',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    username VARCHAR(100) DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    details VARCHAR(500) DEFAULT NULL,
    ip VARCHAR(64) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;