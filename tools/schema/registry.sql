CREATE TABLE `registry` (
    `group`    VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `key`      VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `language` VARCHAR(2)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'empty for language-agnostic',
    `value`    TEXT        NOT NULL,
    PRIMARY KEY (`group`, `key`, `language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
