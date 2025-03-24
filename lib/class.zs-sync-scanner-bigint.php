<?php

/**
 * Table scanner specialized for tables with BIGINT primary keys.
 */
class ZS_Sync_Scanner_Bigint implements ZS_Sync_Scanner_Table_Type {
	private $cursor;
	private $table_info;
	private $max_chunk_size;

	public function __construct(array $options = []) {
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Table::DEFAULT_MAX_CHUNK_SIZE;;
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
		$last_pk                        = $this->cursor['last_pk'];
		$hash_expression                = $this->table_info->build_row_hash_expression();
		$scanned_table_name_identifier  = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		$max_chunk_size_number          = (int) $this->max_chunk_size;

		$wpdb->query( "SET @last_processed_pk := null" );

		$primary_key_name       = $this->table_info->get_primary_keys()[0];
		$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_key_name );

		$where = '1 = 1';
		if ( $last_pk !== null ) {
			$where = "$primary_key_identifier > " . (int) $last_pk;
		}

		$sql = "INSERT INTO wp_sync_metadata__bigint_key (
				`table_name`, `primary_key`, `hash_value`
			)
			SELECT
				$table_name_string, (SELECT @last_processed_pk := scanned.$primary_key_identifier), $hash_expression
			FROM
				$scanned_table_name_identifier scanned
			WHERE $where
			ORDER BY $primary_key_identifier ASC
			LIMIT $max_chunk_size_number
			ON DUPLICATE KEY UPDATE
				hash_value = IF(
					wp_sync_metadata__bigint_key.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					wp_sync_metadata__bigint_key.hash_value
				)";

		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			// @todo Check the error?
			error_log( "Failed to index next records chunk: " . $wpdb->last_error );

			return false;
		}

		$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );
		$this->cursor['last_pk'] = $last_processed_pk;

		return $this->cursor['last_pk'] !== null;
	}

	public static function mark_deletions(
		$table,
		$from_pk,
		$to_pk,
		$bloom_filter
	) {
		global $wpdb;
		
		$sql = <<<SQL
			SELECT * FROM wp_sync_metadata__bigint_key 
			WHERE hash_value IS NOT NULL 
			AND primary_key BETWEEN $from_pk AND $to_pk
		SQL;

		$rows = $wpdb->get_results( $sql );

		$deleted_pks = [];
		foreach($rows as $row) {
			if($bloom_filter->exists( $row->hash_value )) {
				$deleted_pks[] = (int) $row->primary_key;
			}
		}

		if (empty($deleted_pks)) {
			return 0;
		}

		$table_name_expression = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table );
		$sql = <<<SQL
			UPDATE wp_sync_metadata__bigint_key 
			SET hash_value = NULL 
			WHERE
				table_name = $table_name_expression
				AND primary_key IN (" . implode(",", $deleted_pks) . ")
		SQL;

		$wpdb->query( $sql );

		return count($deleted_pks);
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