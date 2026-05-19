CREATE TABLE `error_log` (
    `id`         INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `created_at` DATETIME(6)        NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `exception`  VARCHAR(191)       CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `message`    TEXT               NOT NULL,
    `file`       VARCHAR(512)       NOT NULL,
    `line`       MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    `trace`      MEDIUMTEXT         NOT NULL,
    `url`        VARCHAR(2048)      NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_exception` (`exception`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
