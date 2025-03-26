<?php

/**
 * Table scanner specialized for tables with BIGINT_TWO_TUPLE primary keys.
 */
class ZS_Sync_Scanner_Bigint_Two_Tuple implements ZS_Sync_Scanner_Table_Type {
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
		$from_pk = $last_pk = $this->cursor['last_pk'];
		if(false === $last_pk) {
			return false;
		}

		$table_name_string              = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table_name );
		$hash_expression                = $this->table_info->build_row_hash_expression();
		$scanned_table_name_identifier  = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		$max_chunk_size_number          = (int) $this->max_chunk_size;

		$wpdb->query( "SET @last_processed_pk := null" );

		$primary_keys     = $this->table_info->get_primary_keys();
		$primary_key_first = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_keys[0] );
		$primary_key_second = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_keys[1] );

		$where = '1 = 1';
		if ( $last_pk !== null && is_array( $last_pk ) && isset( $last_pk[0] ) && isset( $last_pk[1] ) ) {
			$where = "($primary_key_first, $primary_key_second) > (" . (int) $last_pk[0] . ", " . (int) $last_pk[1] . ")";
		}

		$select_query = "SELECT
				$table_name_string AS scanned__table_name,
				$primary_key_first AS scanned__primary_key_first,
				$primary_key_second AS scanned__primary_key_second,
				$hash_expression AS scanned__hash_value,
				(SELECT @last_processed_pk := JSON_ARRAY(scanned.$primary_key_first, scanned.$primary_key_second)) AS serialized_pk
			FROM
				$scanned_table_name_identifier scanned
			WHERE $where
			ORDER BY $primary_key_first ASC, $primary_key_second ASC
			LIMIT $max_chunk_size_number";

		$sql = "INSERT INTO {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key (
				`table_name`, `primary_key_first`, `primary_key_second`, `hash_value`
			)
			SELECT 
				scanned__table_name,
				scanned__primary_key_first,
				scanned__primary_key_second,
				scanned__hash_value
			FROM ($select_query) AS sub
			ON DUPLICATE KEY UPDATE
				hash_value = IF(
					{$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key.hash_value is NULL OR 
					{$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					{$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key.hash_value
				)";

		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			// @todo Check the error?
			error_log( "Failed to index next records chunk: " . $wpdb->last_error );

			return false;
		}

		$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );

		$this->cursor['last_pk'] = $last_processed_pk !== null ? json_decode( $last_processed_pk, true ) : false;
		
		// Set hash to null for any deleted resources in the processed range.
		$pairs = $wpdb->get_results($select_query);
		
		if (!empty($pairs)) {
			$sql = "UPDATE {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key 
				SET hash_value = NULL 
				WHERE 
					hash_value IS NOT NULL 
					AND table_name = " . ZS_Sync_Mysql_Helper::string_to_safe_expression($table_name);
			
			// Construct conditions for existing records
			$or_conditions = [];
			foreach ($pairs as $pair) {
				$first = (int)$pair->scanned__primary_key_first;
				$second = (int)$pair->scanned__primary_key_second;
				$or_conditions[] = "(primary_key_first = $first AND primary_key_second = $second)";
			}
			
			$or_conditions_expr = implode(" OR ", $or_conditions);
			if (!empty($or_conditions_expr)) {
				$sql .= " AND NOT ( " . $or_conditions_expr . " )";
			}

			// Add range conditions
			if ($from_pk !== null && is_array($from_pk) && isset($from_pk[0]) && isset($from_pk[1])) {
				$from_first = (int)$from_pk[0];
				$from_second = (int)$from_pk[1];
				$sql .= " AND (primary_key_first, primary_key_second) > ($from_first, $from_second)";
			}
			
			$to_pk = $this->cursor['last_pk'];
			if ($to_pk !== false && is_array($to_pk) && isset($to_pk[0]) && isset($to_pk[1])) {
				$to_first = (int)$to_pk[0]; 
				$to_second = (int)$to_pk[1];
				$sql .= " AND (primary_key_first, primary_key_second) <= ($to_first, $to_second)";
			}
			
			$wpdb->query($sql);
		}
		
		return $this->cursor['last_pk'] !== false;
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