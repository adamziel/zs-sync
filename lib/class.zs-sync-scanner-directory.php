<?php

class ZS_Sync_Scanner_Directory {
	
    private int $chunk_size;
    private ?ZS_Sync_Directory_Visitor $visitor = null;
    private ?array $cursor = null;
	private string $root_path;
	private array $indexed_paths = [];

    /**
     * Construct the scanner with optional settings:
     * - chunk_size: number of files to process at a time
     * - exclude_paths: paths to ignore
     * - cursor: existing state to resume scanning from
     */
    public function __construct(string $root_path, array $options = [])
    {
		$this->root_path = $root_path;
        $this->chunk_size = $options['chunk_size'] ?? 1000;
        $this->cursor = $options['cursor'] ?? null;
    }

    /**
     * Process the next chunk of files.
     * Returns true if there's more to process, false when completed.
     */
    public function next_chunk(): bool
    {
		global $wpdb;

        if(!$this->initialize_scanner()) {
            return false;
        }

		// Crc32 the next $chunk_size files
        $processed = 0;
		$this->indexed_paths = [];
        while($processed < $this->chunk_size) {
            if(!$this->visitor->next_path()) {
                break;
            }

			if(is_dir($this->visitor->get_absolute_path())) {
				continue;
			}

			$relative_path = $this->visitor->get_relative_path();
			$file_hash = hexdec(hash_file('crc32', $this->visitor->get_absolute_path()));
			$this->indexed_paths[$relative_path] = $file_hash;

            $processed++;
        }
        $this->cursor['last_path'] = $this->visitor->get_relative_path();

		// Upsert the hash information to the sync metadata table

		/**
		 * Prepare a SQL block that contains all the rows to be inserted
		 * via SELECT UNION statements.
		 * 
		 * Why not use multi-insert?
		 * 
		 * Imagine we retrieved MAX(version_id) in PHP, incremented it in
		 * memory, and used those increments to construct the query. A
		 * concurrent WordPress hook could be retrieving MAX(version_id)
		 * and updating a version ID of another record at the exact same time.
		 *
		 * Here's an illustration of the problem:
		 * 
		 *   |- request 1 reads MAX(version_id) = 10
		 *   |
		 *   |- request 2 reads MAX(version_id) = 11 and uses it to
		 *   |  assign version 11 to a different record
		 *   |
		 *   |- request 1 increments 10 to 11 and uses it to
		 *   |  assign version 11 to a record
		 *   v
		 * (time)
		 * 
		 * The more time we take between reading the MAX(version_id) and
		 * writing the incremented version numbers, the more chances of a
		 * collision.
		 *
		 * While we do not have any uniqueness guarantees on version_id and
		 * occasional duplicates are fine, we want to keep collisions as rare
		 * as possible for sync consistency.
		 * 
		 * Therefore, we minimize the timespan between version_id is read and
		 * used by making the database do the entire version generation work
		 * without a network round-trip to PHP.
		 */
		$select_rows = [];
		foreach($this->indexed_paths as $relative_path => $file_hash) {
			$select_row_values = implode(',', [
				ZS_Sync_Mysql_Helper::quote_string($relative_path),
				(int) $file_hash,
				'@next_version_id := @next_version_id + 1',
			]);
			$select_rows[] = "SELECT $select_row_values";
		}
		$select_expression = implode("\n UNION ALL \n", $select_rows);

		$sql = <<<SQL
			INSERT INTO wp_sync_metadata__files (
				`file_path`,
				`hash_value`,
				`version_id`
			)
			SELECT
				scanned_files.*
			FROM
				(
					$select_expression
				) scanned_files,
				(SELECT @next_version_id := COALESCE( MAX( version_id ), 0 ) as next FROM wp_sync_metadata__files) v
			ON DUPLICATE KEY UPDATE
				version_id = IF(
					wp_sync_metadata__files.hash_value != VALUES(hash_value),
					@next_version_id := @next_version_id + 1,
					wp_sync_metadata__files.version_id
				),
				hash_value = IF(
					wp_sync_metadata__files.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					wp_sync_metadata__files.hash_value
				)
		SQL;
		echo $sql;
		$result = $wpdb->query($sql);

		if($result === false) {
			error_log("Failed to upsert file hashes: " . $wpdb->last_error);
			return false;
		}

		return true;
    }

    private function initialize_scanner(): bool
    {
        if($this->visitor !== null) {
            return true;
        }

		$visitor = ZS_Sync_Directory_Visitor::for_directory($this->root_path);
		if(!$visitor) {
			_doing_it_wrong( __METHOD__, "Failed to initialize directory visitor", '1.0.0' );
			return false;
		}
		$this->visitor = $visitor;

        if($this->cursor && !empty($this->cursor['last_path'])) {
            if(!$this->visitor->seek_to_closest_matching_prefix($this->cursor['last_path'])) {
                error_log("Failed to seek to last scanned path: " . $this->cursor['last_path']);
                return false;
            }
        }

        return true;
    }

    /**
     * Getters for current state.
     */
    public function get_indexed_paths(): array
    {
        return $this->indexed_paths;
    }

    public function get_cursor(): array
    {
        return $this->cursor;
    }
}
