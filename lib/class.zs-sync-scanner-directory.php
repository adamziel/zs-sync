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

class ZS_Sync_Scanner_Directory implements ZS_Sync_Scanner {

	private int $max_chunk_size;
	private ?ZS_Sync_Directory_Visitor $visitor = null;
	private ?array $cursor = null;
	private string $root_path;
	private array $indexed_paths = [];

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
		$this->max_chunk_size = $options['max_chunk_size'] ?? 1000;
		$this->cursor         = $options['cursor'] ?? null;
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
		$this->cursor['last_path'] = $this->visitor->get_relative_path();

		// Upsert the hash information to the sync metadata table

		$insert_rows = [];
		foreach ( $this->indexed_paths as $relative_path => $file_hash ) {
			$insert_row_values = implode( ',', [
				ZS_Sync_Mysql_Helper::quote_string( $relative_path ),
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
					wp_sync_metadata__files.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					wp_sync_metadata__files.hash_value
				)
		SQL;
		// echo $sql;
		$result = $wpdb->query( $sql );

		if ( $result === false ) {
			error_log( "Failed to upsert file hashes: " . $wpdb->last_error );

			return false;
		}

		return true;
	}

	private function initialize_scanner(): bool {
		if ( $this->visitor !== null ) {
			return true;
		}

		$visitor = ZS_Sync_Directory_Visitor::for_directory( $this->root_path );
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

	public function get_cursor(): array {
		return $this->cursor;
	}
}
