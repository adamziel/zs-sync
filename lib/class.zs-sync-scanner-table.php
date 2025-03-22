<?php

/**
 * Skip the system tables from scanning.
 */
add_filter( 'wp_sync_should_sync_table', 'zs_sync_scanner_table_should_skip_table', 10, 2 );

function zs_sync_scanner_table_should_skip_table( $should_skip, $table_name ) {
	if ( $should_skip ) {
		return $should_skip;
	}

	// Skip all metadata tables
	if ( strpos( $table_name, 'wp_sync_metadata__' ) === 0 ) {
		return true;
	}

	return false;
}

class ZS_Sync_Scanner_Table implements ZS_Sync_Scanner {

	private int $max_chunk_size;
	private array $exclude_tables;
	private ?array $tables = null;
	private ?object $table_info = null;
	private ?string $sync_metadata_table_identifier = null;
	private ?array $cursor = null;

	/**
	 * Construct the indexer with optional settings.
	 *
	 * @param  array  $options  {
	 *     Optional. Array of indexer settings.
	 *
	 * @type int $max_chunk_size The maximum number of rows to process at a time. Actual number of rows
	 *                                    processed may be lower. Default 50.
	 * @type array $exclude_tables Array of table names to ignore during scanning. Default empty array.
	 * @type array $cursor Existing state to resume indexing from. Contains 'table_name' and 'last_pk'
	 *                                    keys. Default null.
	 * }
	 */
	public function __construct( array $options = [] ) {
		$this->max_chunk_size = $options['max_chunk_size'] ?? 50;
		$this->exclude_tables = $options['exclude_tables'] ?? [];
		$this->cursor         = $options['cursor'] ?? [
			'table_name' => null,
			'last_pk'    => null,
		];
	}

	/**
	 * Process the next chunk of rows in the current table.
	 * Moves to the next table automatically when done.
	 * Returns true if there's more data to process, false when completed.
	 */
	public function next_chunk(): bool {
		if ( ! $this->initialize_scanner() ) {
			return false;
		}
		while ( true ) {
			if ( $this->index_next_records_chunk() ) {
				return true;
			}

			if ( $this->next_table() ) {
				continue;
			}

			return false;
		}
	}

	private function initialize_scanner(): bool {
		if ( null !== $this->tables ) {
			return true;
		}

		global $wpdb;
		$this->tables = $wpdb->get_col( "SHOW TABLES" );
		if ( null === $this->tables ) {
			// @todo Check the error?
			return false;
		}

		$table_name = $this->cursor['table_name'] ?? $this->tables[0];
		while ( true ) {
			if ( $this->initialize_table_metadata( $table_name ) ) {
				break;
			}
			if ( ! $this->next_table() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Fetch and index up to $max_chunk_size database rows from the current table.
	 * Returns true if more rows remain, false if the table is exhausted.
	 */
	private function index_next_records_chunk(): bool {
		global $wpdb;

		$table_name = $this->cursor['table_name'];
		if ( ! $table_name || ! $this->table_info ) {
			return false;
		}
		$table_name_string              = ZS_Sync_Mysql_Helper::string_to_safe_expression( $table_name );
		$last_pk                        = $this->cursor['last_pk'];
		$pk_type                        = $this->table_info->get_primary_key_type();
		$hash_expression                = $this->table_info->build_row_hash_expression();
		$sync_metadata_table_identifier = $this->sync_metadata_table_identifier;
		$scanned_table_name_identifier  = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		$max_chunk_size_number          = (int) $this->max_chunk_size;

		$wpdb->query( "SET @last_processed_pk := null" );
		$sql = "";

		// Handle different primary key types
		switch ( $pk_type ) {
			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BIGINT:
				$primary_key_name       = $this->table_info->get_primary_keys()[0];
				$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_key_name );

				$where = '1 = 1';
				if ( $last_pk !== null ) {
					$where = "$primary_key_identifier > " . (int) $last_pk;
				}

				$sql = "INSERT INTO {$sync_metadata_table_identifier} (
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
							{$sync_metadata_table_identifier}.hash_value != VALUES(hash_value),
							VALUES(hash_value),
							{$sync_metadata_table_identifier}.hash_value
						)";

				$result = $wpdb->query( $sql );
				if ( false === $result ) {
					// @todo Check the error?
					error_log( "Failed to index next records chunk: " . $wpdb->last_error );

					return false;
				}

				$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );
				$this->cursor['last_pk'] = $last_processed_pk;
				break;

			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BLOB:
				$primary_key_name       = $this->table_info->get_primary_keys()[0];
				$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_key_name );

				$where = '1 = 1';
				if ( $last_pk !== null ) {
					$where = $primary_key_identifier . ' > ' . ZS_Sync_Mysql_Helper::string_to_safe_expression( $last_pk );
				}

				$sql    = "INSERT INTO {$sync_metadata_table_identifier} (
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
							{$sync_metadata_table_identifier}.hash_value != VALUES(hash_value),
							VALUES(hash_value),
							{$sync_metadata_table_identifier}.hash_value
						)";
				$result = $wpdb->query( $sql );

				if ( false === $result ) {
					// @todo Check the error?
					error_log( "Failed to index next records chunk: " . $wpdb->last_error );

					return false;
				}

				$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );
				$this->cursor['last_pk'] = $last_processed_pk;
				break;

			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE:
				$primary_keys     = $this->table_info->get_primary_keys();
				$primary_key_head = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_keys[0] );
				$primary_key_tail = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_keys[1] );

				$where = '1 = 1';
				if ( $last_pk !== null && is_array( $last_pk ) && isset( $last_pk[0] ) && isset( $last_pk[1] ) ) {
					$where = "($primary_key_head, $primary_key_tail) > (" . (int) $last_pk[0] . ", " . (int) $last_pk[1] . ")";
				}

				$sql = "INSERT INTO {$sync_metadata_table_identifier} (
						`table_name`, `primary_key_head`, `primary_key_tail`, `hash_value`
					)
					SELECT 
						scanned__table_name,
						scanned__primary_key_head,
						scanned__primary_key_tail,
						scanned__hash_value
					FROM (
						SELECT
							$table_name_string AS scanned__table_name,
							$primary_key_head AS scanned__primary_key_head,
							$primary_key_tail AS scanned__primary_key_tail,
							$hash_expression AS scanned__hash_value,
							(SELECT @last_processed_pk := JSON_ARRAY(scanned.$primary_key_head, scanned.$primary_key_tail))
						FROM
							$scanned_table_name_identifier scanned
						WHERE $where
						ORDER BY $primary_key_head ASC, $primary_key_tail ASC
						LIMIT $max_chunk_size_number
					) AS sub
					ON DUPLICATE KEY UPDATE
						hash_value = IF(
							{$sync_metadata_table_identifier}.hash_value != VALUES(hash_value),
							VALUES(hash_value),
							{$sync_metadata_table_identifier}.hash_value
						)";

				$result = $wpdb->query( $sql );

				if ( false === $result ) {
					// @todo Check the error?
					error_log( "Failed to index next records chunk: " . $wpdb->last_error );

					return false;
				}

				$last_processed_pk       = $wpdb->get_var( "SELECT @last_processed_pk" );
				$this->cursor['last_pk'] = $last_processed_pk !== null ? json_decode( $last_processed_pk, true ) : null;
				break;

			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_COMPOSITE:
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

				$sql = "INSERT INTO {$sync_metadata_table_identifier} (
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
							{$sync_metadata_table_identifier}.hash_value != VALUES(hash_value),
							VALUES(hash_value),
							{$sync_metadata_table_identifier}.hash_value
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
				break;

			default:
				_doing_it_wrong(
					__METHOD__,
					"Skipping table " . $table_name . " with unexpected primary key type: " . $pk_type,
					ZS_SYNC_VERSION
				);

				return false;
		}

		return $this->cursor['last_pk'] !== null;
	}

	private function next_table(): bool {
		$table_index = array_search( $this->cursor['table_name'], $this->tables );
		if ( false === $table_index ) {
			$table_index = - 1;
		}

		while ( true ) {
			$table_index ++;

			if ( $table_index >= count( $this->tables ) ) {
				// We've already processed all the tables
				return false;
			}

			$table_name = $this->tables[ $table_index ];
			if ( $this->initialize_table_metadata( $table_name ) ) {
				$this->cursor = [
					'table_name' => $table_name,
					'last_pk'    => null,
				];

				return true;
			}
		}
	}

	private function initialize_table_metadata( $table_name ) {
		$should_skip_table = in_array( $table_name, $this->exclude_tables );
		$should_skip_table = apply_filters( 'wp_sync_should_sync_table', $should_skip_table, $table_name );
		if ( $should_skip_table ) {
			return false;
		}

		$this->table_info = ZS_Sync_Table_Info::for( $table_name );
		if ( $this->table_info === null ) {
			return false;
		}

		// Get the appropriate metadata table based on primary key type
		$metadata_table = $this->table_info->get_sync_metadata_table();
		if ( $metadata_table === null ) {
			_doing_it_wrong(
				__METHOD__,
				"Skipping table " . $table_name . " with unsupported primary key type: " . $this->table_info->get_primary_key_type(),
				ZS_SYNC_VERSION
			);

			return false;
		}

		$this->sync_metadata_table_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query(
			$metadata_table
		);

		return true;
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

	public function get_cursor(): array {
		return $this->cursor;
	}

}

