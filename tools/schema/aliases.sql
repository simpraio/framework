CREATE TABLE `aliases` (
    `language`   CHAR(2)       CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `path`       VARCHAR(128)  CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `module`     VARCHAR(64)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `controller` VARCHAR(64)   CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`language`, `path`),
    KEY `idx_route` (`language`, `module`, `controller`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
