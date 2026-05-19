CREATE TABLE `translation`
(
    `path_id`  VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'route path "module/controller", or "layout" for layout-wide tokens',
    `language` CHAR(2)     CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `id`       VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'translation key, uppercased',
    `text`     TEXT                                              NOT NULL,
    PRIMARY KEY (`path_id`, `language`, `id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
