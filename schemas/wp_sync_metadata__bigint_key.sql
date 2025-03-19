CREATE TABLE IF NOT EXISTS wp_sync_metadata__bigint_key (
    /* Refers to the table where this row is found. */
    table_name CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,

    /*
     * All of WordPress’ default tables have BIGINT primary keys.
     * For string primary keys, see `wp_sync_metadata__string_key`.
     */
    primary_key BIGINT NOT NULL PRIMARY KEY,

    /*
     * Leave the top bit reserved for signed integers on 32bit
     * systems (specifically, SQLite).
     */
    version_id BIGINT NOT NULL,

    /*
     * Refers to the most-recent time that this resource was
     * scanned to check for the probability that it’s stale.
     */
    time_of_last_scan TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    /*
     * Sized for the CRC32. If a stronger hash is required,
     * then expand as necessary. Because INT is signed, however,
     * this field is a BIGINT.
    */
    hash_value INT UNSIGNED
);