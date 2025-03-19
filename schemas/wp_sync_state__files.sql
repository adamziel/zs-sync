CREATE TABLE IF NOT EXISTS wp_sync_state__bigint_keys (
    file_path VARBINARY(3072) CHARACTER SET binary,
    last_scanned_primary_key VARBINARY(3072),
);