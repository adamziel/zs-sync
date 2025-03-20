<?php

/**
 * Skip the system tables from scanning.
 */
add_filter( 'zs_sync.should_skip_table', 'zs_sync_scanner_table_should_skip_table', 10, 2 );

function zs_sync_scanner_table_should_skip_table( $should_skip, $table_name ) {
	if ( $should_skip ) {
		return $should_skip;
	}

	if ( $table_name === 'wp_sync_metadata__bigint_key' || $table_name === 'wp_sync_metadata__blob_key' ) {
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
		if ( ! $table_name || ! $this->table_info || ! $this->table_info->get_primary_key_name() ) {
			return false;
		}
		$last_pk = $this->cursor['last_pk'];

		/**
		 * We need to interpolate the values below – they cannot be provided
		 * via prepared statements placeholders. Let's be extra careful with
		 * them. Identifiers are escaped and numbers are explicitly cast into
		 * the integer type before use.
		 */
		$primary_key_name       = $this->table_info->get_primary_key_name();
		$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_key_name );
		$hash_expression        = $this->table_info->build_row_hash_expression();

		$bound_params = array( $table_name );
		$where        = '1 = 1';
		switch ( $this->table_info->get_primary_key_php_type() ) {
			case 'int':
				if ( $last_pk !== null ) {
					$where = "$primary_key_identifier > " . (int) $last_pk;
				}
				break;
			case 'string':
				if ( $last_pk !== null ) {
					$where = $primary_key_identifier . ' > ' . ZS_Sync_Mysql_Helper::quote_string( $last_pk );
				}
				break;
			default:
				_doing_it_wrong(
					__METHOD__,
					"Skipping table " . $table_name . " with unexpected primary key type: " . $this->table_info->get_primary_key_php_type(),
					ZS_SYNC_VERSION
				);

				return false;
		}
		$sync_metadata_table_identifier = $this->sync_metadata_table_identifier;
		$scanned_table_name_identifier  = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		$max_chunk_size_number          = (int) $this->max_chunk_size;

		/**
		 * A silly variable-based technique to get the last processed primary key.
		 *
		 * We assign the primary key value to this variable in each row expression,
		 * and we process rows in a sorted order. At the end of the processing,
		 * the variable contains the most recently seen primary key value.
		 *
		 * MySQL doesn't support the RETURNING keyword from Postgres so we're
		 * simulating it with the MySQL tools that we have at our disposal.
		 *
		 * See https://stackoverflow.com/questions/1388025/how-to-get-id-of-the-last-updated-row-in-mysql
		 * for more context.
		 */
		$wpdb->query( "SET @last_processed_pk := null" );
		$sql = $wpdb->prepare(
			"INSERT INTO {$sync_metadata_table_identifier} (
                `table_name`,
                `primary_key`,
                `hash_value`
            )
            SELECT
                %s,
                (SELECT @last_processed_pk := scanned.$primary_key_identifier),
                $hash_expression
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
				)",
			...$bound_params
		);

		if ( false === $wpdb->query( $sql ) ) {
			// @todo Check the error?
			error_log( "Failed to index next records chunk: " . $wpdb->last_error );

			return false;
		}

		$this->cursor['last_pk'] = $wpdb->get_var( "SELECT @last_processed_pk" );

		return $this->cursor['last_pk'] !== null;
	}

	private function next_table(): bool {
		$table_index = array_search( $this->cursor['table_name'], $this->tables );
		if ( false === $table_index ) {
			$table_index = - 1;
		}

		while ( true ) {
			$table_index ++;

			if ( $table_index >= count( $this->tables ) - 1 ) {
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
		$should_skip_table = apply_filters( 'zs_sync.should_skip_table', $should_skip_table, $table_name );
		if ( $should_skip_table ) {
			return false;
		}

		$this->table_info = ZS_Sync_Table_Info::for( $table_name );
		if ( $this->table_info === null ) {
			return false;
		}

		switch ( $this->table_info->get_primary_key_php_type() ) {
			case 'int':
				$this->sync_metadata_table_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query(
					'wp_sync_metadata__bigint_key'
				);
				break;
			case 'string':
				$this->sync_metadata_table_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query(
					'wp_sync_metadata__blob_key'
				);
				break;
			default:
				_doing_it_wrong(
					__METHOD__,
					"Skipping table " . $this->cursor['table_name'] . " with unexpected primary key type: " . $this->table_info->get_primary_key_php_type(),
					ZS_SYNC_VERSION
				);

				return false;
		}

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
