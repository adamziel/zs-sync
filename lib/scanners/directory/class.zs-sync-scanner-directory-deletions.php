<?php


class ZS_Sync_Scanner_Directory_Deletions implements ZS_Sync_Scanner_Interface {

	private int $max_chunk_size;
	private ?array $cursor = null;
	private bool $is_finished = false;
	private string $root_path;

	/**
	 * Construct the scanner with optional settings.
	 *
	 * @param  array  $options  {
	 *     Optional. Array of scanner settings.
	 *
	 * @type int $max_chunk_size The maximum number of rows to process at a time. Actual number of rows
	 *                                    processed may be lower. Default 50.
	 * @type array $cursor Existing state to resume scanning from. Contains 'last_path'
	 *                                    key. Default null.
	 * @type string $root_path The root path to use when checking if files exist. Default empty string.
	 * }
	 */
	public function __construct(string $root_path, array $options = []) {
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

		// Get the starting point for this chunk
		$from_path = isset($this->cursor['last_path']) ? $this->cursor['last_path'] : '';
		
		// Query the database for the next chunk of files
		$query = "SELECT file_path FROM {$wpdb->prefix}wp_sync_metadata__files 
			WHERE file_path > ".ZS_Sync_MySQL_Helper::to_safe_expression($from_path)."
			ORDER BY file_path ASC 
			LIMIT ".ZS_Sync_MySQL_Helper::to_safe_expression($this->max_chunk_size);
		
		
		$files = $wpdb->get_results($query, ARRAY_A);
		
		if (empty($files)) {
			$this->is_finished = true;
			return false;
		}
		
		// Process each file to check if it still exists
		$deleted_files = [];
		$last_path = '';
		
		foreach ($files as $file) {
			$file_path = $file['file_path'];
			$last_path = $file_path;
			
			// Check if the file exists, using the root path as a prefix
			$full_path = $this->root_path . $file_path;
			if (!file_exists($full_path)) {
				$deleted_files[] = $file_path;
			}
		}
		
		// Update the cursor to the last processed file path
		$this->cursor['last_path'] = $last_path;
		
		// Set hash to null for deleted files
		if (!empty($deleted_files)) {
			$this->set_hash_to_null_for_deleted_files($deleted_files);
		}
		
		return true;
	}
	
	/**
	 * Sets hash_value to NULL for files that no longer exist.
	 * 
	 * @param array $deleted_files Array of file paths that no longer exist
	 * @return bool True on success, false on failure
	 */
	private function set_hash_to_null_for_deleted_files(array $deleted_files): bool {
		global $wpdb;
		
		if (empty($deleted_files)) {
			return true;
		}
		
		// Prepare placeholders for the IN clause
		$placeholders = [];
		foreach ($deleted_files as $file) {
			$placeholders[] = ZS_Sync_MySQL_Helper::to_safe_expression($file);
		}
		$placeholders = implode(',', $placeholders);
		
		// Update the database to set hash_value to NULL for deleted files
		$query = "UPDATE {$wpdb->prefix}wp_sync_metadata__files 
			SET hash_value = NULL 
			WHERE file_path IN ($placeholders)";
		
		$result = $wpdb->query($query, $deleted_files);
		
		if ($result === false) {
			error_log("Failed to update hash values for deleted files: " . $wpdb->last_error);
			return false;
		}
		
		return true;
	}

	/**
	 * Alphabetically compares two cursors.
	 */
	static public function compare_cursors(string $cursor1, string $cursor2): int {
		return strcmp($cursor1, $cursor2);
	}

	/**
	 * Getters for current state.
	 */
	public function get_cursor() {
		return $this->cursor;
	}

	public function is_finished(): bool {
		return $this->is_finished;
	}
}
