CREATE TABLE IF NOT EXISTS wp_sync_metadata__blob_key (
    /* Refers to the table where this row is found. */
    table_name CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,

    /*
     * Plugins can create tables that have non-numeric primary keys.
	 * For example, WooCommerce uses a composite key of two bigints
	 * and, historically, have used a session_key CHAR(32) primary key.
	 *
	 * This column stores:
	 *
	 * - Any string primary keys as bytes to avoid giving them any
	 *   text-encoding assumptions.
	 * - Composite primary key values encoded as JSON.
	 *
	 * We cannot use a BLOB type here because InnoDB is limited to
	 * 768 bytes per index key. The `primary key` entries we can store
	 * in this table are, therefore, limited to 768 bytes - 64 bytes
	 * for the table name = 704 bytes.
	 *
     * For integer primary keys, see `wp_sync_metadata__bigint_key`.
     */
    primary_key VARBINARY(704) NOT NULL,

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
    hash_value INT UNSIGNED,

	/**
	 * Records are uniquely identified by their source of origin – in
	 * this case it's the combination of their table name and their
	 * numerical primary key.
	 */
	PRIMARY KEY (`table_name`, `primary_key`)
);