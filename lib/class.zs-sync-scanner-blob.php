<?php

/**
 * Table scanner specialized for tables with BLOB primary keys.
 */
class ZS_Sync_Scanner_Blob implements ZS_Sync_Scanner_Table_Type {
	private $cursor;
	private $table_info;
	private $max_chunk_size;

	public function __construct(array $options = []) {
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Interface::DEFAULT_MAX_CHUNK_SIZE;;
		if(!isset($options['cursor'])) {
			throw new \Exception('Cursor is required');
		}
		$this->cursor = $options['cursor'];
		$this->table_info = ZS_Sync_Table_Info::for($this->cursor['table_name']);
	}

	/**
	 * Fetch and index up to $max_chunk_size database rows from the current table.
	 * Returns true if more rows remain, false if the table is exhausted.
	 * 
	 * @return bool True if more rows remain, false if the table is exhausted.
	 */
	public function scan_next_records_chunk(): bool {
		global $wpdb;

		$table_name = $this->cursor['table_name'];
		if ( ! $table_name || ! $this->table_info ) {
			return false;
		}
		$table_name_string              = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table_name );
		$from_pk = $last_pk             = $this->cursor['last_pk'];
		$hash_expression                = $this->table_info->build_row_hash_expression();
		$scanned_table_name_identifier  = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		$max_chunk_size_number          = (int) $this->max_chunk_size;

		$wpdb->query( "SET @last_processed_pk := null" );

		$primary_key_name       = $this->table_info->get_primary_keys()[0];
		$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_key_name );

		$where = '1 = 1';
		if ( $last_pk !== null ) {
			$where = $primary_key_identifier . ' > ' . ZS_Sync_Mysql_Helper::string_to_safe_expression( $last_pk );
		}

		$select_query = "SELECT
				$table_name_string AS scanned__table_name,
				$primary_key_identifier AS scanned__primary_key,
				$hash_expression AS scanned__hash_value,
				(SELECT @last_processed_pk := $primary_key_identifier) AS serialized_pk
			FROM
				$scanned_table_name_identifier scanned
			WHERE $where
			ORDER BY $primary_key_identifier ASC
			LIMIT $max_chunk_size_number";

		$sql = "INSERT INTO {$wpdb->prefix}wp_sync_metadata__blob_key (
				`table_name`, `primary_key`, `hash_value`
			)
			SELECT
				scanned__table_name,
				scanned__primary_key,
				scanned__hash_value
			FROM ($select_query) AS sub
			ON DUPLICATE KEY UPDATE
				hash_value = IF(
					{$wpdb->prefix}wp_sync_metadata__blob_key.hash_value is NULL OR 
					{$wpdb->prefix}wp_sync_metadata__blob_key.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					{$wpdb->prefix}wp_sync_metadata__blob_key.hash_value
				)";
				
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			// @todo Check the error?
			error_log( "Failed to index next records chunk: " . $wpdb->last_error );

			return false;
		}

		$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );
		$this->cursor['last_pk'] = $last_processed_pk;
		
		// Set hash to null for any deleted resources in the processed range.
		$existing_pks = $wpdb->get_col($select_query, 3);
		$existing_pks_in_expression = [];
		foreach ($existing_pks as $pk) {
			$existing_pks_in_expression[] = ZS_Sync_Mysql_Helper::string_to_safe_expression($pk);
		}
		$existing_pks_in_expression = implode(", ", $existing_pks_in_expression);
		$sql = "UPDATE {$wpdb->prefix}wp_sync_metadata__blob_key 
				SET hash_value = NULL 
				WHERE hash_value IS NOT NULL 
				AND table_name = " . ZS_Sync_Mysql_Helper::string_to_safe_expression($table_name);
		
		if (!empty($existing_pks_in_expression)) {
			$sql .= " AND primary_key NOT IN ( $existing_pks_in_expression )";
		}

		if ($from_pk !== null) {
			$from_pk_safe = ZS_Sync_Mysql_Helper::string_to_safe_expression($from_pk);
			$sql .= " AND primary_key > $from_pk_safe";
		}
		
		$to_pk = $last_processed_pk;
		if ($to_pk !== null) {
			$to_pk_safe = ZS_Sync_Mysql_Helper::string_to_safe_expression($to_pk);
			$sql .= " AND primary_key <= $to_pk_safe";
		}

		$wpdb->query($sql);

		return $this->cursor['last_pk'] !== null;
	}

	/**
	 * Getters for current state.
	 */
	public function get_table_name(): ?string {
		return $this->cursor['table_name'] ?? null;
	}

	public function get_last_pk() {
		return $this->cursor['last_pk'];
	}

	public function get_cursor(): string {
		return $this->cursor;
	}

}
