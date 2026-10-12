-- Optional: monthly RANGE partitioning for pkg_cdr (central MagnusBilling DB).
-- Review on a maintenance window. Requires PRIMARY KEY to include starttime.
-- Backup first. Existing CallArchive remains the primary hot-window strategy.
-- Prefer: php /var/www/html/mbilling/cron.php cdrscale   (adds safe indexes)

-- 1) Include starttime in the primary key (required for RANGE partitioning)
-- ALTER TABLE pkg_cdr DROP PRIMARY KEY, ADD PRIMARY KEY (id, starttime);

-- 2) Example partitions (extend monthly via cron.php cdrscale when partitioned)
-- ALTER TABLE pkg_cdr
-- PARTITION BY RANGE (UNIX_TIMESTAMP(starttime)) (
--   PARTITION p202601 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01 00:00:00')),
--   PARTITION p202602 VALUES LESS THAN (UNIX_TIMESTAMP('2026-03-01 00:00:00')),
--   PARTITION p202603 VALUES LESS THAN (UNIX_TIMESTAMP('2026-04-01 00:00:00')),
--   PARTITION pmax VALUES LESS THAN MAXVALUE
-- );

-- Recommended hot indexes (apply manually if cdrscale cannot run):
-- ALTER TABLE pkg_cdr ADD INDEX idx_cdr_starttime_user (starttime, id_user);
-- ALTER TABLE pkg_cdr ADD INDEX idx_cdr_id_server_start (id_server, starttime);
-- ALTER TABLE pkg_cdr_failed ADD INDEX idx_cdrf_starttime (starttime);
-- ALTER TABLE pkg_cdr_archive ADD INDEX idx_cdra_starttime (starttime);
