CREATE TABLE `auth_group`
(
    `group_id`   SMALLINT UNSIGNED                   NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(50)                         NOT NULL,
    `status`     ENUM ('active', 'disabled')         NOT NULL DEFAULT 'active',
    `created_at` DATETIME(6)                         NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`group_id`),
    UNIQUE KEY `name` (`name`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `auth_user`
(
    `user_id`       MEDIUMINT UNSIGNED                     NOT NULL AUTO_INCREMENT,
    `group_id`      SMALLINT UNSIGNED                      NOT NULL,
    `username`      VARCHAR(100)                           NOT NULL,
    `password`      VARCHAR(255)                           NOT NULL,
    `status`        ENUM ('active', 'disabled', 'deleted') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME(6)                            NULL,
    `created_at`    DATETIME(6)                            NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at`    DATETIME(6)                            NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `username` (`username`),
    KEY `group_id` (`group_id`),
    CONSTRAINT `fk_auth_user_group` FOREIGN KEY (`group_id`) REFERENCES `auth_group` (`group_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;

-- user_id / group_id: 0 = wildcard (matches any user or group).
-- FK constraints on user_id and group_id are not possible because 0 is used
-- as a wildcard sentinel and is part of the composite primary key (NULL not allowed in PKs).
CREATE TABLE `auth_access`
(
    `path_id`  VARCHAR(64)        CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `block`    VARCHAR(50)                                              NOT NULL DEFAULT '',
    `user_id`  MEDIUMINT UNSIGNED                                       NOT NULL DEFAULT 0,
    `group_id` SMALLINT UNSIGNED                                        NOT NULL DEFAULT 0,
    `policy`   ENUM ('allow', 'deny')                                   NOT NULL DEFAULT 'deny',
    PRIMARY KEY (`path_id`, `block`, `user_id`, `group_id`),
    KEY `user_id` (`user_id`),
    KEY `group_id` (`group_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci;
