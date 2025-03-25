<?php

/**
 * Table scanner specialized for tables with BIGINT primary keys.
 */
class ZS_Sync_Scanner_Bigint implements ZS_Sync_Scanner_Table_Type {
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

		$sql = "INSERT INTO wp_sync_metadata__bigint_key (
				`table_name`, `primary_key`, `hash_value`
			)
			SELECT
				scanned__table_name,
				scanned__primary_key,
				scanned__hash_value
			FROM ($select_query) AS sub
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
		
		// Add scanned PKs to bloom filter if set
		if ($this->bloom_filter !== null) {
			$rows = $wpdb->get_results($select_query);
			foreach ($rows as $row) {
				// Use the primary key value as the item to add to the bloom filter
				// We use a string prefix to ensure unique identification across different tables
				$this->bloom_filter->add("bigint:{$table_name}:{$row->serialized_pk}");
			}
		}

		return $this->cursor['last_pk'] !== null;
	}

	public static function mark_deletions(
		$table,
		$from_pk,
		$to_pk,
		$bloom_filter
	) {
		global $wpdb;
		$sql = "SELECT * FROM wp_sync_metadata__bigint_key WHERE hash_value IS NOT NULL AND table_name = " . ZS_Sync_Mysql_Helper::string_to_safe_expression($table);
		
		if ($from_pk !== null) {
			$sql .= " AND primary_key >= " . (int) $from_pk;
		}
		
		if ($to_pk !== null) {
			$sql .= " AND primary_key <= " . (int) $to_pk;
		}

		$rows = $wpdb->get_results( $sql );

		$deleted_pks = [];
		foreach($rows as $row) {
			if(!$bloom_filter->exists( "bigint:{$table}:{$row->primary_key}" )) {
				$deleted_pks[] = (int) $row->primary_key;
			}
		}

		if (empty($deleted_pks)) {
			return 0;
		}

		$in_expression = [];
		foreach ($deleted_pks as $pk) {
			$in_expression[] = ZS_Sync_Mysql_Helper::string_to_safe_expression($pk);
		}
		$in_expression = implode(", ", $in_expression);

		$table_name_expression = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table );
		$sql = <<<SQL
			UPDATE wp_sync_metadata__bigint_key 
			SET hash_value = NULL 
			WHERE
				table_name = $table_name_expression
				AND primary_key IN ( $in_expression )
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