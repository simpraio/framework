-- Convert locally-stored datetimes in framework tables to UTC.
--
-- Needed once by projects upgrading from a release where framework instant columns were written in
-- `project.timezone`, either through connection-local CURRENT_TIMESTAMP defaults or PHP formatting.
--
-- Run this in a write-free maintenance window: stop every database writer, deploy v5, run this
-- migration, convert your own product tables, then resume writers. Any other order can mix local and
-- UTC values.
--
-- Run it through a client that stops at the first error and leaves the transaction uncommitted,
-- which `mariadb <database> < this-file.sql` does. A client that continues past an error or commits
-- each statement is guarded against below, but is still the wrong way to run a migration.
--
-- Run it as an account that may UPDATE the framework tables, CREATE the bookkeeping tables below,
-- and SELECT from `mysql`.`time_zone_*`, which the DST pre-flight reads. The application's own
-- least-privilege account is generally not the right one.
--
-- Set @from_timezone in the client session before sourcing this file. When it is unset, the script
-- defaults to '+00:00' and is a no-op for projects that already stored UTC.
--
-- Prefer a named zone such as 'Europe/Prague' when the historical offset changed with DST: it
-- applies the offset each value actually had, which a fixed offset cannot. Named zones require
-- populated mysql.time_zone_* tables (mariadb-tzinfo-to-sql), and CONVERT_TZ returns NULL for every
-- row without them.
--
-- Rows whose conversion cannot be resolved are skipped rather than written as NULL, so an unloaded
-- timezone table or an invalid stored date cannot destroy data. The report after the conversion
-- counts rows left unconverted per table; a non-zero count needs manual work.
--
-- The two DST edge cases are not errors and cannot be skipped: CONVERT_TZ resolves both to a
-- deterministic instant, so a wall clock in the nonexistent spring-forward hour lands on the
-- transition boundary and one in the repeated fall-back hour is read as the first (pre-transition)
-- pass. Both are guesses about data that never recorded which side it was on. Before converting
-- anything, this script records every such row - with its original local value - in
-- `simpra_dst_review`, because once converted they are indistinguishable from ordinary values.
--
-- Running it twice would shift the data twice, so it records itself in `simpra_migration`, and both
-- the duplicate key and an explicit guard on every UPDATE stop a second run. Back up first and
-- validate known events against an external UTC source after the migration.
--
-- Three bookkeeping tables are left behind: `simpra_migration` (keep it - it is the record that this
-- ran), `simpra_dst_window` and `simpra_dst_review` (drop them once reconciliation is finished).
--
-- This file expects both auth and error-log tables. Remove the statements for an extension whose
-- schema is not installed. Product-owned instant columns need an equivalent conversion.

-- Previous project.timezone, e.g. 'Europe/Prague' or '-04:00'.
SET @from_timezone = COALESCE(@from_timezone, '+00:00');

-- Whether the zone resolves at all. A named zone returns NULL from CONVERT_TZ on a server whose
-- mysql.time_zone_* tables were never populated, which would otherwise skip every row and still
-- claim the migration below, blocking the corrected re-run.
SET @timezone_resolves = CONVERT_TZ('2026-01-15 12:00:00', @from_timezone, '+00:00') IS NOT NULL;

-- Ledger of applied framework migrations. Created outside the transaction because DDL commits
-- implicitly, which would otherwise split the conversion below into separate transactions.
CREATE TABLE IF NOT EXISTS `simpra_migration`
(
    `migration_id`  VARCHAR(64) NOT NULL,
    `applied_at`    DATETIME(6) NOT NULL,
    `from_timezone` VARCHAR(64) NOT NULL,
    PRIMARY KEY (`migration_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Whether this migration already ran. The duplicate key on the ledger insert stops a second run on a
-- client that halts at the first error; this flag also gates every UPDATE, so a client that ploughs
-- on past errors cannot shift the data a second time either.
SELECT COUNT(*) INTO @already_applied FROM `simpra_migration` WHERE `migration_id` = 'utc-storage';

-- Echo what this run is about to do. A silent script cannot distinguish a forgotten @from_timezone
-- from a clean conversion: both print nothing but zeros.
SELECT @from_timezone                                                                    AS `from_timezone`,
       @timezone_resolves                                                                AS `zone_resolves`,
       (SELECT COUNT(*) FROM `mysql`.`time_zone_name` WHERE `Name` = @from_timezone)     AS `named_zone_loaded`,
       @already_applied                                                                  AS `already_applied`;

-- ---------------------------------------------------------------------------------------------
-- Pre-flight: which stored values sit in a DST transition window.
--
-- CONVERT_TZ answers every wall clock, including the ones the local calendar never had or had
-- twice, so those rows convert silently and no error marks them. This runs BEFORE the conversion,
-- while the values are still local, and PERSISTS them: the console listing is only a preview, and
-- the rows are unrecoverable once converted.
--
-- Each transition gives one window between the wall clock read at the old offset and the wall clock
-- read at the new one: skipping forward, that span never existed; falling back, it happened twice.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `simpra_dst_window`
(
    `window_start` DATETIME(6) NOT NULL,
    `window_end`   DATETIME(6) NOT NULL,
    `kind`         VARCHAR(16) NOT NULL,
    PRIMARY KEY (`window_start`, `window_end`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `simpra_dst_review`
(
    `table_name`         VARCHAR(64) NOT NULL,
    `row_key`            VARCHAR(64) NOT NULL,
    `column_name`        VARCHAR(64) NOT NULL,
    `stored_local_value` DATETIME(6) NOT NULL,
    `kind`               VARCHAR(16) NOT NULL,
    PRIMARY KEY (`table_name`, `row_key`, `column_name`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Only a first run may populate these: on a blocked re-run the stored values are already UTC and
-- would overwrite the local values this table exists to preserve.
DELETE FROM `simpra_dst_window` WHERE @already_applied = 0;

-- The 1970 cut is applied AFTER the window function, not inside it. Filtering first would hide the
-- preceding transition from LAG, leaving the earliest window without a predecessor offset - and for
-- a zone whose only change is a single post-1970 transition that silently produces no windows at all.
INSERT INTO `simpra_dst_window` (`window_start`, `window_end`, `kind`)
SELECT LEAST(`local_before`, `local_after`),
       GREATEST(`local_before`, `local_after`),
       IF(`new_offset` > `prev_offset`, 'nonexistent', 'repeated')
FROM (SELECT TIMESTAMPADD(SECOND, `transition_time` + `prev_offset`, '1970-01-01 00:00:00') AS `local_before`,
             TIMESTAMPADD(SECOND, `transition_time` + `new_offset`, '1970-01-01 00:00:00')  AS `local_after`,
             `prev_offset`,
             `new_offset`
      FROM (SELECT t.`Transition_time`                                      AS `transition_time`,
                   tt.`Offset`                                              AS `new_offset`,
                   LAG(tt.`Offset`) OVER (ORDER BY t.`Transition_time`)     AS `prev_offset`
            FROM `mysql`.`time_zone_transition` t
                     JOIN `mysql`.`time_zone_name` n
                          ON n.`Time_zone_id` = t.`Time_zone_id`
                     JOIN `mysql`.`time_zone_transition_type` tt
                          ON tt.`Time_zone_id` = t.`Time_zone_id`
                              AND tt.`Transition_type_id` = t.`Transition_type_id`
            WHERE n.`Name` = @from_timezone
              -- Bounded so TIMESTAMPADD cannot underflow DATETIME on a sentinel transition time,
              -- while still giving the first post-1970 transition a predecessor.
              AND t.`Transition_time` >= -2208988800) `s`
      WHERE `prev_offset` IS NOT NULL
        AND `prev_offset` <> `new_offset`
        AND `transition_time` >= 0) `w`
WHERE @already_applied = 0;

DELETE FROM `simpra_dst_review` WHERE @already_applied = 0;

INSERT INTO `simpra_dst_review` (`table_name`, `row_key`, `column_name`, `stored_local_value`, `kind`)
SELECT 'auth_group', CAST(d.`group_id` AS CHAR), 'created_at', d.`created_at`, w.`kind`
FROM `auth_group` d
         JOIN `simpra_dst_window` w
              ON d.`created_at` >= w.`window_start` AND d.`created_at` < w.`window_end`
UNION ALL
SELECT 'auth_user', CAST(d.`user_id` AS CHAR), 'created_at', d.`created_at`, w.`kind`
FROM `auth_user` d
         JOIN `simpra_dst_window` w
              ON d.`created_at` >= w.`window_start` AND d.`created_at` < w.`window_end`
UNION ALL
SELECT 'auth_user', CAST(d.`user_id` AS CHAR), 'updated_at', d.`updated_at`, w.`kind`
FROM `auth_user` d
         JOIN `simpra_dst_window` w
              ON d.`updated_at` >= w.`window_start` AND d.`updated_at` < w.`window_end`
UNION ALL
SELECT 'auth_user', CAST(d.`user_id` AS CHAR), 'last_login_at', d.`last_login_at`, w.`kind`
FROM `auth_user` d
         JOIN `simpra_dst_window` w
              ON d.`last_login_at` >= w.`window_start` AND d.`last_login_at` < w.`window_end`
UNION ALL
SELECT 'error_log', CAST(d.`id` AS CHAR), 'created_at', d.`created_at`, w.`kind`
FROM `error_log` d
         JOIN `simpra_dst_window` w
              ON d.`created_at` >= w.`window_start` AND d.`created_at` < w.`window_end`;

-- Totals per column, and the whole set is in `simpra_dst_review` regardless of what prints here.
SELECT `table_name`, `column_name`, `kind`, COUNT(*) AS `rows_in_dst_window`
FROM `simpra_dst_review`
GROUP BY `table_name`, `column_name`, `kind`
ORDER BY `table_name`, `column_name`, `kind`;

-- A preview only. Read the full list with:
--   SELECT * FROM simpra_dst_review ORDER BY table_name, stored_local_value;
SELECT * FROM `simpra_dst_review` ORDER BY `table_name`, `stored_local_value` LIMIT 50;

START TRANSACTION;

-- Claim the migration first: on a second run this duplicate key aborts the script before any row is
-- shifted again. Only a run that can actually convert is recorded, so neither a no-op run nor a run
-- against an unresolvable zone blocks the real one later.
-- applied_at is written with UTC_TIMESTAMP(6) because this script runs from an operator's client,
-- which is not the UTC-pinned application connection.
INSERT INTO `simpra_migration` (`migration_id`, `applied_at`, `from_timezone`)
SELECT 'utc-storage', UTC_TIMESTAMP(6), @from_timezone
FROM DUAL
WHERE @from_timezone <> '+00:00'
  AND @timezone_resolves
  AND @already_applied = 0;

-- auth_group: created_at only.
UPDATE `auth_group`
SET `created_at` = CONVERT_TZ(`created_at`, @from_timezone, '+00:00')
WHERE @from_timezone <> '+00:00'
  AND @already_applied = 0
  AND CONVERT_TZ(`created_at`, @from_timezone, '+00:00') IS NOT NULL;

-- auth_user: created_at, updated_at, and nullable last_login_at.
-- v4 wrote last_login_at through the project-timezone formatter, so historical values are local too.
-- updated_at is ON UPDATE CURRENT_TIMESTAMP, so it is assigned explicitly here to stop this very
-- statement from re-stamping it in the session timezone.
-- last_login_at keeps its stored value when the conversion cannot be resolved, so an unconvertible
-- login stays local instead of being erased; NULL stays NULL.
UPDATE `auth_user`
SET `created_at`    = CONVERT_TZ(`created_at`, @from_timezone, '+00:00'),
    `updated_at`    = CONVERT_TZ(`updated_at`, @from_timezone, '+00:00'),
    `last_login_at` = COALESCE(CONVERT_TZ(`last_login_at`, @from_timezone, '+00:00'), `last_login_at`)
WHERE @from_timezone <> '+00:00'
  AND @already_applied = 0
  AND CONVERT_TZ(`created_at`, @from_timezone, '+00:00') IS NOT NULL
  AND CONVERT_TZ(`updated_at`, @from_timezone, '+00:00') IS NOT NULL;

-- error_log: created_at only. Rows written by releases that stamped this column in PHP with the
-- display formatter are also local, so they convert the same way.
UPDATE `error_log`
SET `created_at` = CONVERT_TZ(`created_at`, @from_timezone, '+00:00')
WHERE @from_timezone <> '+00:00'
  AND @already_applied = 0
  AND CONVERT_TZ(`created_at`, @from_timezone, '+00:00') IS NOT NULL;

COMMIT;

-- Rows left unconverted, counted per ROW rather than per column: the auth_user statement above skips
-- a whole row when created_at or updated_at cannot convert, which leaves that row's last_login_at
-- local too. Counting each column on its own would report that row as converted.
-- Every count must be zero. A table-wide count means the zone did not resolve at all: nothing was
-- changed and the migration was not recorded, so load mysql.time_zone_* and run this file again. A
-- handful of rows means those rows hold a date CONVERT_TZ cannot read, such as a legacy
-- '0000-00-00'; fix or delete them and convert them by hand.
SELECT 'auth_group' AS `table`, COUNT(*) AS `rows_not_converted`
FROM `auth_group`
WHERE @from_timezone <> '+00:00'
  AND CONVERT_TZ(`created_at`, @from_timezone, '+00:00') IS NULL
UNION ALL
SELECT 'auth_user', COUNT(*)
FROM `auth_user`
WHERE @from_timezone <> '+00:00'
  AND (CONVERT_TZ(`created_at`, @from_timezone, '+00:00') IS NULL
    OR CONVERT_TZ(`updated_at`, @from_timezone, '+00:00') IS NULL
    OR (`last_login_at` IS NOT NULL AND CONVERT_TZ(`last_login_at`, @from_timezone, '+00:00') IS NULL))
UNION ALL
SELECT 'error_log', COUNT(*)
FROM `error_log`
WHERE @from_timezone <> '+00:00'
  AND CONVERT_TZ(`created_at`, @from_timezone, '+00:00') IS NULL;

-- Validate a sample of known events against an external UTC source after the migration. Include rows
-- on both sides of every DST transition and manually reconcile everything in `simpra_dst_review`.
--
-- Only these framework tables are covered. Product tables with DEFAULT CURRENT_TIMESTAMP columns
-- need the same treatment, with one exception worth stating: a daily-rollup `day` column is a
-- calendar LABEL, not an instant, and belongs in the reporting zone. Do not convert it as an instant;
-- shifting a 22:00 local event to UTC can place it in the next day's bucket.
