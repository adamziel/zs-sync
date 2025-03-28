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
	private array $ignore_file_patterns = [];
	private array $ignore_directory_patterns = [];

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
	 * @type array $ignore_file_patterns Array of regex patterns to ignore files during scanning.
	 * @type array $ignore_directory_patterns Array of regex patterns to ignore directories during scanning.
	 * }
	 */
	public function __construct( string $root_path, array $options = [] ) {
		$this->root_path      = $root_path;
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Interface::DEFAULT_MAX_CHUNK_SIZE;
		$this->cursor         = isset($options['cursor']) ? $options['cursor'] : null;
		
		// Default ignore patterns for files
		$default_ignore_file_patterns = [
			'/\.DS_Store$/',
			'/Thumbs\.db$/',
		];
		
		// Default ignore patterns for directories
		$default_ignore_directory_patterns = [
			'/^\.git(\/|$)/',
			'/^\.svn(\/|$)/',
			'/^\.mercurial(\/|$)/',
			'/^\.hg(\/|$)/',
			'/^\.bzr(\/|$)/',
			'/^CVS(\/|$)/',
			'/^node_modules(\/|$)/',
		];
		
		$this->ignore_file_patterns = isset($options['ignore_file_patterns']) ? 
			array_merge($default_ignore_file_patterns, $options['ignore_file_patterns']) : 
			$default_ignore_file_patterns;
			
		$this->ignore_directory_patterns = isset($options['ignore_directory_patterns']) ? 
			array_merge($default_ignore_directory_patterns, $options['ignore_directory_patterns']) : 
			$default_ignore_directory_patterns;
			
		// For backward compatibility
		if (isset($options['ignore_patterns'])) {
			$this->ignore_file_patterns = array_merge($this->ignore_file_patterns, $options['ignore_patterns']);
			$this->ignore_directory_patterns = array_merge($this->ignore_directory_patterns, $options['ignore_patterns']);
		}
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

			$relative_path = $this->visitor->get_relative_path();
			$absolute_path = $this->visitor->get_absolute_path();
			$is_directory = is_dir($absolute_path);
			
			// Skip directories that match ignore patterns
			if ( $is_directory ) {
				if ( $this->should_ignore_directory( $relative_path ) ) {
					continue;
				}
				// Skip directories as we only index files
				continue;
			}
			
			// Skip files that match ignore patterns
			if ( $this->should_ignore_file( $relative_path ) ) {
				continue;
			}

			if('' === $from_path ) {
				$from_path = $relative_path;
			}

			$file_hash     = hexdec( hash_file( 'crc32', $absolute_path ) );
			$file_size     = file_exists( $absolute_path ) ? filesize( $absolute_path ) : 0;

			$this->indexed_paths[ $relative_path ] = [
				'hash' => $file_hash,
				'size' => $file_size,
			];
			$processed ++;
		}
		if( 0 === $processed) {
			$this->is_finished = true;
			return false;
		}
		$this->cursor['last_path'] = $this->visitor->get_relative_path();
		if ( 0 === count( $this->indexed_paths ) ) {
			return false;
		}

		// Upsert the hash information to the sync metadata table
		$insert_rows = [];
		foreach ( $this->indexed_paths as $relative_path => $file_details ) {
			$insert_row_values = implode( ',', [
				ZS_Sync_Mysql_Helper::string_to_safe_expression( $relative_path ),
				(int) $file_details['hash'],
				(int) $file_details['size'],
			] );
			$insert_rows[]     = "($insert_row_values)";
		}
		$insert_expression = implode( ",\n", $insert_rows );

		$sql = <<<SQL
			INSERT INTO {$wpdb->prefix}wp_sync_metadata__files (
				`file_path`,
				`hash_value`,
				`filesize`
			)
			VALUES
				$insert_expression
			ON DUPLICATE KEY UPDATE
				filesize = IF(
					{$wpdb->prefix}wp_sync_metadata__files.hash_value is NULL OR {$wpdb->prefix}wp_sync_metadata__files.hash_value != VALUES(hash_value),
					VALUES(filesize),
					{$wpdb->prefix}wp_sync_metadata__files.filesize
				),				
				hash_value = IF(
					{$wpdb->prefix}wp_sync_metadata__files.hash_value is NULL OR {$wpdb->prefix}wp_sync_metadata__files.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					{$wpdb->prefix}wp_sync_metadata__files.hash_value
				)
		SQL;

		$result = $wpdb->query( $sql );

		if ( $result === false ) {
			error_log( "Failed to upsert file hashes: " . $wpdb->last_error );

			return false;
		}

		return true;
	}

	/**
	 * Checks if a file should be ignored based on the regex ignore patterns.
	 *
	 * @param string $path The relative path to check.
	 * @return bool True if the file should be ignored, false otherwise.
	 */
	private function should_ignore_file( string $path ): bool {
		foreach ( $this->ignore_file_patterns as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if a directory should be ignored based on the regex ignore patterns.
	 *
	 * @param string $path The relative path to check.
	 * @return bool True if the directory should be ignored, false otherwise.
	 */
	private function should_ignore_directory( string $path ): bool {
		foreach ( $this->ignore_directory_patterns as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}
		return false;
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
