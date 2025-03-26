CREATE TABLE IF NOT EXISTS <prefix>wp_sync_metadata__bigint_two_tuple_key (
    /* Refers to the table where this row is found. */
    table_name CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,

    /*
     * BIGINT two-tuple primary key.
     */
    primary_key_first BIGINT NOT NULL,
    primary_key_second BIGINT NOT NULL,

    /*
     * Refers to the most-recent time that this resource was
     * scanned to check for the probability that it’s stale.
	 *
	 * Stored with fractional seconds precision to allow more
	 * precise filtering.
     */
    time_of_last_scan TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    /*
     * Sized for the CRC32. If a stronger hash is required,
     * then expand as necessary. Because INT is signed, however,
     * this field is a BIGINT.
    */
    hash_value INT UNSIGNED,

	/**
	 * Records are uniquely identified by their source of origin – in
	 * this case it's the combination of their table name and both
	 * parts of the numerical primary key.
	 */
	PRIMARY KEY (`table_name`, `primary_key_first`, `primary_key_second`)
);