CREATE TABLE IF NOT EXISTS <prefix>wp_sync_metadata__files (
    /*
     * Windows limits max filepath to 256 characters,
     * and while many filesystems limit the filename
     * to this length, many allow a combined path length
     * of up to 4096.
     *
     * However, MySQL only supports creating a primary
     * key of up to 3072 bytes. Therefore, this is an
     * arbitrary limit imposed on what this can support.
     */
    file_path VARBINARY(3072) NOT NULL PRIMARY KEY,

    /*
     * Refers to the most-recent time that this resource was
     * scanned to check for the probability that it's stale.
     *
     * Stored with fractional seconds precision to allow more
     * precise filtering.
     */
    time_of_last_scan TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),

    /*
     * Sized for the CRC32. If a stronger hash is required,
     * then expand as necessary.
    */
    hash_value INT UNSIGNED,

    /*
     * The size of the file in bytes.
     */
    filesize BIGINT UNSIGNED
);
