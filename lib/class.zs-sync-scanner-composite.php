<?php

/**
 * Table scanner specialized for tables with COMPOSITE primary keys.
 */
class ZS_Sync_Scanner_Composite implements ZS_Sync_Scanner_Table_Type {
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

		$primary_keys   = $this->table_info->get_primary_keys();
		$pk_identifiers = array();
		$pk_json_parts  = array();

		foreach ( $primary_keys as $i => $key ) {
			$pk_id            = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $key );
			$pk_identifiers[] = $pk_id;
			$pk_json_parts[]  = "$pk_id";
		}

		$where = '1 = 1';
		if ( $last_pk !== null && is_array( $last_pk ) ) {
			$primary_key_identifier = '(' . implode( ', ', $pk_json_parts ) . ')';
			$primary_key_values     = [];
			foreach ( $last_pk as $pk_value ) {
				$primary_key_values[] = is_int( $pk_value ) ? $pk_value : ZS_Sync_Mysql_Helper::string_to_safe_expression( $pk_value );
			}
			$primary_key_value_expression = '(' . implode( ', ', $primary_key_values ) . ')';
			$where                        = $primary_key_identifier . ' > ' . $primary_key_value_expression;
		}

		$json_object = "JSON_ARRAY(" . implode( ', ', $pk_json_parts ) . ")";
		$order_by    = implode( ' ASC, ', $pk_identifiers ) . ' ASC';

		$sql = "INSERT INTO wp_sync_metadata__composite_key (
				`table_name`, `primary_key`, `hash_value`
			)
			SELECT
				scanned__table_name,
				scanned__primary_key,
				scanned__hash_value
			FROM (
				SELECT
					$table_name_string AS scanned__table_name,
					$json_object AS scanned__primary_key,
					$hash_expression AS scanned__hash_value,
					(SELECT @last_processed_pk := $json_object) AS last_processed_pk
				FROM
					$scanned_table_name_identifier scanned
				WHERE $where
				ORDER BY $order_by
				LIMIT $max_chunk_size_number
			) AS sub
			ON DUPLICATE KEY UPDATE
				hash_value = IF(
					wp_sync_metadata__composite_key.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					wp_sync_metadata__composite_key.hash_value
				)
		";

		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			// @todo Check the error?
			error_log( "Failed to index next records chunk: " . $wpdb->last_error );

			return false;
		}

		$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );
		$this->cursor['last_pk'] = $last_processed_pk !== null ? json_decode( $last_processed_pk, true ) : null;

		return $this->cursor['last_pk'] !== null;
	}

	public static function mark_deletions(
		$table,
		$from_pk,
		$to_pk,
		$bloom_filter
	) {
		global $wpdb;
		
		$from_pk_json = json_encode($from_pk);
		$to_pk_json = json_encode($to_pk);
		
		$sql = <<<SQL
			SELECT * FROM wp_sync_metadata__composite 
			WHERE hash_value IS NOT NULL 
			AND primary_key >= '$from_pk_json'
			AND primary_key <= '$to_pk_json'
		SQL;

		$rows = $wpdb->get_results( $sql );

		$deleted_pks = [];
		foreach($rows as $row) {
			if($bloom_filter->exists( $row->hash_value )) {
				$deleted_pks[] = $row->primary_key;
			}
		}

		if (empty($deleted_pks)) {
			return 0;
		}

		$table_name_expression = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table );
		
		$conditions = [];
		foreach ($deleted_pks as $pk) {
			$conditions[] = "primary_key = " . ZS_Sync_Mysql_Helper::string_to_safe_expression($pk);
		}
		
		$sql = <<<SQL
			UPDATE wp_sync_metadata__composite 
			SET hash_value = NULL 
			WHERE
				table_name = $table_name_expression
				AND (" . implode(" OR ", $conditions) . ")
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