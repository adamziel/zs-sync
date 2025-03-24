<?php

/**
 * Skip the system tables from scanning.
 */

use Pleo\BloomFilter\BloomFilter;

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

/**
 * Factory class for table scanners.
 * This class selects the appropriate scanner based on the primary key type of each table.
 * It processes one table at a time in alphabetical order, scanning a chunk of rows with each call to next_chunk().
 */
class ZS_Sync_Scanner_Table implements ZS_Sync_Scanner_Interface {

	const DEFAULT_MAX_CHUNK_SIZE = 2;

	// Current state of the scanner
	private ?array $cursor = null;
	
	// All tables in the database, sorted alphabetically
	private ?array $tables = null;
	
	// Maximum number of rows to scan in one chunk
	private int $max_chunk_size;

	/**
	 * The scanner for the current table.
	 * @var ZS_Sync_Scanner_Table_Type|null
	 */
	private $table_scanner = null;

	/**
	 * Construct the table scanner factory with optional settings.
	 *
	 * @param  array  $options  {
	 *     Optional. Array of scanner settings.
	 *
	 * @type int $max_chunk_size The maximum number of rows to process at a time. Actual number of rows
	 *                                    processed may be lower. Default 50.
	 * @type string $cursor Existing state to resume indexing from. Default null.
	 * }
	 */
	public function __construct( array $options = [] ) {
		// Initialize common settings
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Table::DEFAULT_MAX_CHUNK_SIZE;;
		
		// Get all tables in alphabetical order
		$this->tables = ZS_Sync_Table_Info::get_tables();
		sort($this->tables);
		
		if(isset($options['cursor'])) {
			$this->cursor = $options['cursor'];
			$this->initialize_from_cursor();
		} else {
			$this->cursor = [
				'table_name' => null,
				'last_pk' => null,
			];
		}
	}

	/**
	 * Process the next chunk of rows from a single table.
	 * Moves to the next table if the current one is completed.
	 * Returns true if there's more data to process, false when all tables are done.
	 */
	public function next_chunk(): bool {
		// If we don't have any tables, we're done
		if (empty($this->tables)) {
			return false;
		}
		
		// If we're not currently processing a table, find the next one
		if ($this->cursor['table_name'] === null) {
			if (!$this->move_to_next_table()) {
				return false;
			}
		}
		
		if (!$this->table_scanner) {
			if (!$this->move_to_next_table()) {
				return false;
			}
		}
		
		while(!$this->table_scanner->scan_next_records_chunk()) {
			if (!$this->move_to_next_table()) {
				return false;
			}
		}

		// Update our cursor with the scanner's current state
		$this->cursor['last_pk'] = $this->table_scanner->get_last_pk();
		
		return true;
	}
	
	/**
	 * Move to the next table that has a valid primary key and can be processed.
	 * 
	 * @return bool True if moved to a valid table, false if no more tables
	 */
	private function move_to_next_table(): bool {
		$current_table_index = -1;
		
		// Find the index of the current table
		if ($this->cursor['table_name'] !== null) {
			$current_table_index = array_search($this->cursor['table_name'], $this->tables);
			if ($current_table_index === false) {
				$current_table_index = -1;
			}
		}

		// Find the next valid table
		$found_valid_table = false;
		for ($i = $current_table_index + 1; $i < count($this->tables); $i++) {
			$table_name = $this->tables[$i];
			
			// Skip tables that should be filtered out
			$should_skip_table = apply_filters('wp_sync_should_sync_table', false, $table_name);
			if ($should_skip_table) {
				continue;
			}
			
			// Get table info to determine primary key type
			$table_info = ZS_Sync_Table_Info::for($table_name);
			if ($table_info === null) {
				continue;
			}
			
			// Check if we have a scanner for this primary key type
			$pk_type = $table_info->get_primary_key_type();
			$this->table_scanner = $this->create_scanner($pk_type, [
				'cursor' => [
					'table_name' => $table_name,
					'last_pk' => null,
				],
				'max_chunk_size' => $this->max_chunk_size,
			]);
			
			// Found a valid table
			$this->cursor['table_name'] = $table_name;
			$this->cursor['last_pk'] = null;
			$found_valid_table = true;
			break;
		}
		
		return $found_valid_table;
	}

	/**
	 * Initialize the scanner from the current cursor state.
	 * 
	 * @return bool True if initialization was successful, false otherwise.
	 */
	private function initialize_from_cursor(): bool {
		if (empty($this->cursor['table_name'])) {
			return false;
		}
		
		$table_name = $this->cursor['table_name'];
		
		// Skip tables that should be filtered out
		$should_skip_table = apply_filters('wp_sync_should_sync_table', false, $table_name);
		if ($should_skip_table) {
			return false;
		}
		
		// Get table info to determine primary key type
		$table_info = ZS_Sync_Table_Info::for($table_name);
		if ($table_info === null) {
			return false;
		}
		
		// Create the appropriate scanner for this table's primary key type
		$pk_type = $table_info->get_primary_key_type();
		$this->table_scanner = $this->create_scanner($pk_type, [
			'cursor' => $this->cursor,
			'max_chunk_size' => $this->max_chunk_size,
		]);
		
		return true;
	}
	
	/**
	 * Reinitialize a scanner with the given options.
	 * 
	 * @param string $pk_type The primary key type
	 * @param array $options Options for the scanner
	 * @return ZS_Sync_Scanner_Interface The initialized scanner
	 */
	private function create_scanner(string $pk_type, array $options) {
		switch ($pk_type) {
			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BIGINT:
				return new ZS_Sync_Scanner_Bigint($options);
			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BLOB:
				return new ZS_Sync_Scanner_Blob($options);
			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE:
				return new ZS_Sync_Scanner_Bigint_Two_Tuple($options);
			case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_COMPOSITE:
				return new ZS_Sync_Scanner_Composite($options);
			default:
				throw new \InvalidArgumentException("Unknown primary key type: $pk_type");
		}
	}

	/**
	 * Returns the current cursor for this scanner.
	 * 
	 * @return array cursor state
	 */
	public function get_cursor() {
		return $this->cursor;
	}

	/**
	 * Get the current table being processed.
	 * 
	 * @return string|null The table name, or null if no table is being processed.
	 */
	public function get_current_table(): ?string {
		return $this->cursor['table_name'];
	}
	
	/**
	 * Get the last primary key processed.
	 * 
	 * @return mixed The last primary key, or null if no primary key has been processed.
	 */
	public function get_last_pk() {
		return $this->cursor['last_pk'];
	}

	/**
	 * Mark rows as deleted between two cursor points.
	 * 
	 * @param string|null $from_cursor The starting cursor state
	 * @param string|null $to_cursor The ending cursor state
	 * @param BloomFilter $bloom_filter Bloom filter to use for checking deletion
	 * @return int|false Number of rows marked as deleted, or false on error
	 */
	static public function mark_deletions(
		?string $from_cursor,
		?string $to_cursor,
		BloomFilter $bloom_filter
	) {
		$from_cursor = json_decode($from_cursor, true);
		$to_cursor = json_decode($to_cursor, true);
		if(!$from_cursor || !$to_cursor) {
			_doing_it_wrong( __METHOD__, "Invalid cursor provided.", '1.0.0' );
			return false;
		}

		$ranges_to_process = [];

		// @TODO: Support this variant:
		//        we've scanned everything, wrapped around, and 
		//        finished with a to_pk < from_pk.
		if( $from_cursor['table_name'] === $to_cursor['table_name'] ) {
			$ranges_to_process[] = [
				'table_name' => $from_cursor['table_name'],
				'from_pk' => $from_cursor['last_pk'],
				'to_pk' => $to_cursor['last_pk'],
			];
		} else {
			$ranges_to_process[] = [
				'table_name' => $from_cursor['table_name'],
				'from_pk' => $from_cursor['last_pk'],
				'to_pk' => null,
			];
			$ranges_to_process[] = [
				'table_name' => $to_cursor['table_name'],
				'from_pk' => null,
				'to_pk' => $to_cursor['last_pk'],
			];
			
			$tables_between = [];
			$tables = ZS_Sync_Table_Info::get_tables();
			sort($tables); // Ensure alphabetical order
			foreach($tables as $table) {
				if(
					strcmp($table, $from_cursor['table_name']) > 0 &&
					strcmp($table, $to_cursor['table_name']) < 0
				) {
					$tables_between[] = $table;
				}
			}

			foreach($tables_between as $table) {
				$ranges_to_process[] = [
					'table_name' => $table,
					'from_pk' => null,
					'to_pk' => null,
				];
			}	
		}

		if(!count($ranges_to_process)) {
			return 0;
		}

		$affected_rows = 0;
		foreach($ranges_to_process as $range) {
			$table_info = ZS_Sync_Table_Info::for( $range['table_name'] );
			if ( $table_info === null ) {
				_doing_it_wrong( __METHOD__, "Table info not found for table: " . $range['table_name'], ZS_SYNC_VERSION );
				continue;
			}

			switch($table_info->get_primary_key_type()) {
				case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BIGINT:
					$affected_rows += ZS_Sync_Scanner_Bigint::mark_deletions( 
						$range['table_name'], 
						$range['from_pk'], 
						$range['to_pk'], 
						$bloom_filter 
					);
					break;
				case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BLOB:
					$affected_rows += ZS_Sync_Scanner_Blob::mark_deletions( 
						$range['table_name'], 
						$range['from_pk'], 
						$range['to_pk'], 
						$bloom_filter 
					);
					break;
				case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE:
					$affected_rows += ZS_Sync_Scanner_Bigint_Two_Tuple::mark_deletions( 
						$range['table_name'], 
						$range['from_pk'], 
						$range['to_pk'], 
						$bloom_filter 
					);
					break;
				case ZS_Sync_Table_Info::PRIMARY_KEY_TYPE_COMPOSITE:
					$affected_rows += ZS_Sync_Scanner_Composite::mark_deletions( 
						$range['table_name'], 
						$range['from_pk'], 
						$range['to_pk'], 
						$bloom_filter 
					);
					break;
			}
		}

		return $affected_rows;
	}
}

