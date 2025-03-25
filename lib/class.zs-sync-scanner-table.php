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
	 * Bloom filter to add primary keys to.
	 * @var BloomFilter|null
	 */
	private $bloom_filter = null;

	/**
	 * Construct the table scanner factory with optional settings.
	 *
	 * @param  array  $options  {
	 *     Optional. Array of scanner settings.
	 *
	 * @type int $max_chunk_size The maximum number of rows to process at a time. Actual number of rows
	 *                                    processed may be lower. Default 50.
	 * @type string $cursor Existing state to resume indexing from. Default null.
	 * @type BloomFilter $bloom_filter The bloom filter to add primary keys to. Default null.
	 * }
	 */
	public function __construct( array $options = [] ) {
		// Initialize common settings
		$this->max_chunk_size = $options['max_chunk_size'] ?? \ZS_Sync_Scanner_Interface::DEFAULT_MAX_CHUNK_SIZE;;
		$this->bloom_filter = $options['bloom_filter'] ?? null;
		
		// Get all tables in alphabetical order
		$this->tables = ZS_Sync_Table_Info::get_tables();
		sort($this->tables);
		
		if(isset($options['cursor'])) {
			$this->cursor = $options['cursor'];
			$this->initialize_from_cursor();
		} else {
			$this->move_to_next_table();
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
		if (!isset($this->cursor['table_name'])) {
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
		if (isset($this->cursor['table_name']) && $this->cursor['table_name'] !== null) {
			$current_table_index = array_search($this->cursor['table_name'], $this->tables);
			if ($current_table_index === false) {
				$current_table_index = -1;
			}
		}

		// Find the next valid table
		$found_valid_table = false;
		for ($i = $current_table_index + 1; $i < count($this->tables); $i++) {
			$table_name = $this->tables[$i];
			
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
				'bloom_filter' => $this->bloom_filter,
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
			'bloom_filter' => $this->bloom_filter,
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

}

