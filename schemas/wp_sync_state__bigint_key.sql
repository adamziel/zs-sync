CREATE TABLE IF NOT EXISTS wp_sync_state__bigint_keys (
    table_name CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
    last_scanned_primary_key BIGINT,
);