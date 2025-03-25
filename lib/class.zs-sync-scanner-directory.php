<?php

/**
 * Skip the scanner tables from scanning.
 */

add_filter( 'zs_sync.should_skip_table', 'zs_sync_scanner_directory_should_skip_table', 10, 2 );

function zs_sync_scanner_directory_should_skip_table( $should_skip, $table_name ) {
	if ( $should_skip ) {
		return $should_skip;
	}

	if ( $table_name === 'wp_sync_metadata__files' ) {
		return true;
	}

	return false;
}

class ZS_Sync_Scanner_Directory implements ZS_Sync_Scanner_Interface {

	private int $max_chunk_size;
	private ?ZS_Sync_Sorted_Directory_Visitor $visitor = null;
	private ?array $cursor = null;
	private string $root_path;
	private array $indexed_paths = [];
	private bool $is_finished = false;

	/**
	 * Construct the indexer with optional settings.
	 *
	 * @param  string  $root_path  The root path to scan.
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
	public function __construct( string $root_path, array $options = [] ) {
		$this->root_path      = $root_path;
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Interface::DEFAULT_MAX_CHUNK_SIZE;
		$this->cursor         = isset($options['cursor']) ? $options['cursor'] : null;
	}

	/**
	 * Process the next chunk of files.
	 * Returns true if there's more to process, false when completed.
	 */
	public function next_chunk(): bool {
		global $wpdb;

		if ( ! $this->initialize_scanner() ) {
			return false;
		}

		// Save the starting path for this chunk for use in detecting deleted files
		$from_path = isset($this->cursor['last_path']) ? $this->cursor['last_path'] : '';
		
		// Crc32 the next $max_chunk_size files
		$processed           = 0;
		$this->indexed_paths = [];
		while ( $processed < $this->max_chunk_size ) {
			if ( ! $this->visitor->next_path() ) {
				break;
			}

			if ( is_dir( $this->visitor->get_absolute_path() ) ) {
				continue;
			}

			$relative_path                         = $this->visitor->get_relative_path();
			$file_hash                             = hexdec( hash_file( 'crc32', $this->visitor->get_absolute_path() ) );
			$this->indexed_paths[ $relative_path ] = $file_hash;
			$processed ++;
		}
		if( 0 === $processed) {
			$this->is_finished = true;
			$this->set_hash_to_null_for_deleted_files( $from_path );
			return false;
		}
		$this->cursor['last_path'] = $this->visitor->get_relative_path();
		if ( 0 === count( $this->indexed_paths ) ) {
			return false;
		}

		// Upsert the hash information to the sync metadata table
		$insert_rows = [];
		foreach ( $this->indexed_paths as $relative_path => $file_hash ) {
			$insert_row_values = implode( ',', [
				ZS_Sync_Mysql_Helper::string_to_safe_expression( $relative_path ),
				(int) $file_hash,
			] );
			$insert_rows[]     = "($insert_row_values)";
		}
		$insert_expression = implode( ",\n", $insert_rows );

		$sql = <<<SQL
			INSERT INTO wp_sync_metadata__files (
				`file_path`,
				`hash_value`
			)
			VALUES
				$insert_expression
			ON DUPLICATE KEY UPDATE
				hash_value = IF(
					wp_sync_metadata__files.hash_value is NULL OR wp_sync_metadata__files.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					wp_sync_metadata__files.hash_value
				)
		SQL;

		$result = $wpdb->query( $sql );

		if ( $result === false ) {
			error_log( "Failed to upsert file hashes: " . $wpdb->last_error );

			return false;
		}
		
		// Set hash to null for any deleted files in the processed range
		if (!empty($this->indexed_paths)) {
			$this->set_hash_to_null_for_deleted_files( $from_path, $this->cursor['last_path'] );
		}

		return true;
	}

	private function set_hash_to_null_for_deleted_files( ?string $from_path, ?string $to_path = null): void {
		global $wpdb;

		$sql = "UPDATE wp_sync_metadata__files 
				SET hash_value = NULL 
				WHERE hash_value IS NOT NULL";

		$existing_paths_in_expression = [];
		foreach (array_keys($this->indexed_paths) as $path) {
			$existing_paths_in_expression[] = ZS_Sync_Mysql_Helper::string_to_safe_expression($path);
		}
		$existing_paths_in_expression = implode(", ", $existing_paths_in_expression);

		if (!empty($existing_paths_in_expression)) {
			$sql .= " AND file_path NOT IN ( $existing_paths_in_expression )";
		}
		
		// Add range conditions for the current chunk
		if ($from_path) {
			$from_path_safe = ZS_Sync_Mysql_Helper::string_to_safe_expression($from_path);
			$sql .= " AND file_path > $from_path_safe";
		}
		
		if ($to_path) {
			$to_path_safe = ZS_Sync_Mysql_Helper::string_to_safe_expression($to_path);
			$sql .= " AND file_path <= $to_path_safe";
		}

		$wpdb->query($sql);
	}

	/**
	 * Alphabetically compares two cursors.
	 */
	static public function compare_cursors( string $cursor1, string $cursor2 ): int {
		return strcmp( $cursor1, $cursor2 );
	}

	private function initialize_scanner(): bool {
		if ( $this->visitor !== null ) {
			return true;
		}

		$visitor = ZS_Sync_Sorted_Directory_Visitor::for_directory( $this->root_path );
		if ( ! $visitor ) {
			_doing_it_wrong( __METHOD__, "Failed to initialize directory visitor", '1.0.0' );

			return false;
		}

		$this->visitor = $visitor;
		if ( $this->cursor && ! empty( $this->cursor['last_path'] ) ) {
			if ( ! $this->visitor->seek_to_closest_matching_prefix( $this->cursor['last_path'] ) ) {
				error_log( "Failed to seek to last scanned path: " . $this->cursor['last_path'] );
				return false;
			}
		}

		return true;
	}

	/**
	 * Getters for current state.
	 */
	public function get_indexed_paths(): array {
		return $this->indexed_paths;
	}

	public function get_cursor() {
		return $this->cursor;
	}

	public function is_finished(): bool {
		return $this->is_finished;
	}
}
