CREATE TABLE `seo` (
    `path_id`     VARCHAR(64)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'route path, format "module/controller" (e.g. "main/info")',
    `language`    CHAR(2)      CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `title`       VARCHAR(255) NOT NULL DEFAULT '',
    `description` TEXT         NOT NULL,
    `canonical_url` VARCHAR(512) NOT NULL DEFAULT '',
    PRIMARY KEY (`path_id`, `language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
