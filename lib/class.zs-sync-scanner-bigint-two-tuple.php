<?php

/**
 * Table scanner specialized for tables with BIGINT_TWO_TUPLE primary keys.
 */
class ZS_Sync_Scanner_Bigint_Two_Tuple implements ZS_Sync_Scanner_Table_Type {
	private $cursor;
	private $table_info;
	private $max_chunk_size;
	/**
	 * Bloom filter to add primary keys to.
	 * @var \Pleo\BloomFilter\BloomFilter|null
	 */
	private $bloom_filter;

	public function __construct(array $options = []) {
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Table::DEFAULT_MAX_CHUNK_SIZE;;
		if(!isset($options['cursor'])) {
			throw new \Exception('Cursor is required');
		}
		$this->cursor = $options['cursor'];
		$this->table_info = ZS_Sync_Table_Info::for($this->cursor['table_name']);
		$this->bloom_filter = $options['bloom_filter'] ?? null;
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
		$last_pk = $this->cursor['last_pk'];
		if(false === $last_pk) {
			return false;
		}

		$table_name_string              = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table_name );
		$hash_expression                = $this->table_info->build_row_hash_expression();
		$scanned_table_name_identifier  = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		$max_chunk_size_number          = (int) $this->max_chunk_size;

		$wpdb->query( "SET @last_processed_pk := null" );

		$primary_keys     = $this->table_info->get_primary_keys();
		$primary_key_head = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_keys[0] );
		$primary_key_tail = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_keys[1] );

		$where = '1 = 1';
		if ( $last_pk !== null && is_array( $last_pk ) && isset( $last_pk[0] ) && isset( $last_pk[1] ) ) {
			$where = "($primary_key_head, $primary_key_tail) > (" . (int) $last_pk[0] . ", " . (int) $last_pk[1] . ")";
		}

		$select_query = "SELECT
				$table_name_string AS scanned__table_name,
				$primary_key_head AS scanned__primary_key_head,
				$primary_key_tail AS scanned__primary_key_tail,
				$hash_expression AS scanned__hash_value,
				(SELECT @last_processed_pk := JSON_ARRAY(scanned.$primary_key_head, scanned.$primary_key_tail)) AS serialized_pk
			FROM
				$scanned_table_name_identifier scanned
			WHERE $where
			ORDER BY $primary_key_head ASC, $primary_key_tail ASC
			LIMIT $max_chunk_size_number";

		$sql = "INSERT INTO wp_sync_metadata__bigint_two_tuple_key (
				`table_name`, `primary_key_head`, `primary_key_tail`, `hash_value`
			)
			SELECT 
				scanned__table_name,
				scanned__primary_key_head,
				scanned__primary_key_tail,
				scanned__hash_value
			FROM ($select_query) AS sub
			ON DUPLICATE KEY UPDATE
				hash_value = IF(
					wp_sync_metadata__bigint_two_tuple_key.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					wp_sync_metadata__bigint_two_tuple_key.hash_value
				)";

		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			// @todo Check the error?
			error_log( "Failed to index next records chunk: " . $wpdb->last_error );

			return false;
		}

		$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );

		$this->cursor['last_pk'] = $last_processed_pk !== null ? json_decode( $last_processed_pk, true ) : false;
		
		// Add scanned PKs to bloom filter if set
		if ($this->bloom_filter !== null && $this->cursor['last_pk'] !== false) {
			// Get all the rows we just processed to add them to the bloom filter
			$rows = $wpdb->get_results($select_query);		
			foreach ($rows as $row) {
				// Use the primary key value as the item to add to the bloom filter
				// We use a string prefix to ensure unique identification across different tables
				$this->bloom_filter->add("bigint_two_tuple:{$table_name}:{$row->serialized_pk}");
				var_dump("indexing bigint_two_tuple:{$table_name}:{$row->serialized_pk}");
			}
		}
		
		return $this->cursor['last_pk'] !== false;
	}
	
	public static function mark_deletions(
		$table,
		$from_pk,
		$to_pk,
		$bloom_filter
	) {
		global $wpdb;
		$table_name_expression = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table );
		$sql = "SELECT 
			JSON_ARRAY(primary_key_head, primary_key_tail) AS serialized_pk,
			primary_key_head,
			primary_key_tail
		FROM wp_sync_metadata__bigint_two_tuple_key 
		WHERE hash_value IS NOT NULL AND table_name = " . $table_name_expression;
		
		if ($from_pk !== null) {
			$from_pk_head = (int) $from_pk[0];
			$from_pk_tail = (int) $from_pk[1];
			$sql .= " AND (primary_key_head, primary_key_tail) > ($from_pk_head, $from_pk_tail)";
		}
		
		if ($to_pk !== null) {
			$to_pk_head = (int) $to_pk[0];
			$to_pk_tail = (int) $to_pk[1];
			$sql .= " AND (primary_key_head, primary_key_tail) <= ($to_pk_head, $to_pk_tail)";
		}

		$rows = $wpdb->get_results( $sql );

		$deleted_pks = [];
		foreach($rows as $row) {
			if(!$bloom_filter->exists( "bigint_two_tuple:{$table}:{$row->serialized_pk}" )) {
				$deleted_pks[] = [
					'head' => $row->primary_key_head,
					'tail' => $row->primary_key_tail
				];
			}
		}

		if (empty($deleted_pks)) {
			return 0;
		}
		
		$or_conditions = [];
		foreach ($deleted_pks as $pk) {
			$or_conditions[] = "(primary_key_head = {$pk['head']} AND primary_key_tail = {$pk['tail']})";
		}

		$or_conditions = implode(" OR ", $or_conditions);
		$sql = <<<SQL
			UPDATE wp_sync_metadata__bigint_two_tuple_key 
			SET hash_value = NULL 
			WHERE
				table_name = $table_name_expression
				AND ( $or_conditions )
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