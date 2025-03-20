<?php

class ZS_Sync_Table_Scanner {

    private int $chunkSize;
    private array $excludeTables;
    private ?array $tables = null;
    private ?object $table_info = null;
    private ?string $sync_metadata_table_identifier = null;
    private ?array $cursor = null;

    /**
     * Construct the indexer with optional settings:
     * - chunk_size: number of rows to process at a time
     * - exclude_tables: tables to ignore
     * - cursor: existing state to resume indexing from
     */
    public function __construct(array $options = [])
    {
        $this->chunkSize = $options['chunk_size'] ?? 1000;
        $this->excludeTables = $options['exclude_tables'] ?? [];
        $this->excludeTables[] = 'wp_sync_metadata__bigint_key';
        $this->cursor = $options['cursor'] ?? null;
    }

    /**
     * Process the next chunk of rows in the current table.
     * Moves to the next table automatically when done.
     * Returns true if there's more data to process, false when completed.
     */
    public function index_next(): bool
    {
        if(!$this->initialize()) {
            return false;
        }
        while(true) {
            if(true === $this->index_next_records_chunk()) {
                return true;
            }

            if(true === $this->next_table()) {
                continue;
            }

            return false;
        }
    }

    private function initialize(): bool
    {
        if ($this->tables !== null) {
            return true;
        }

        global $wpdb;
        $this->tables = $wpdb->get_col("SHOW TABLES");
        if(null === $this->tables ) {
            // @todo Check the error?
            return false;
        }

		$table_name = $this->tables[0];
		while(true) {
			if($this->initialize_table($table_name)) {
				break;
			}
			if(!$this->next_table()) {
				return false;
			}
			$table_name = $this->cursor['table_name'];
		}

        return true;
    }

    private function next_table(): bool
    {
        $table_index = array_search($this->cursor['table_name'], $this->tables);
        if(false === $table_index) {
            $table_index = -1;
        }

        while(true) {
            $table_index++;

            if ($table_index >= count($this->tables) - 1) {
                // We've already processed all the tables
                return false;
            }

			if(!$this->initialize_table($this->tables[$table_index])) {
				continue;
			}

            break;
        }

        return true;
    }

    private function initialize_table($table_name)
    {
		if (in_array($table_name, $this->excludeTables)) {
			return false;
		}
		$this->cursor = array(
			'table_name' => $table_name,
			'last_pk' => null,
		);

        $this->table_info = ZS_Sync_Table_Info::for($table_name);
        if($this->table_info === null) {
            return false;
        }

        switch($this->table_info->get_primary_key_php_type()) {
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
				$this->next_table();
				return false;
        }
        return true;
    }

    /**
     * Fetch and index up to $chunkSize database rows from the current table.
     * Returns true if more rows remain, false if the table is exhausted.
     */
    private function index_next_records_chunk(): bool
    {
        global $wpdb;
        
        $table_name = $this->cursor['table_name'];
        if (!$table_name || !$this->table_info || !$this->table_info->get_primary_key_name()) {
            return false;
        }
        $last_pk = $this->cursor['last_pk'];

        /**
         * We need to interpolate the values below – they cannot be provided
         * via prepared statements placeholders. Let's be extra careful with
         * them. Identifiers are escaped and numbers are explicitly cast into
         * the integer type before use.
         */
        $primary_key_name = $this->table_info->get_primary_key_name();
        $primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query($primary_key_name);
        $hash_expression = $this->table_info->build_row_hash_expression();

        $bound_params = array($table_name);
        $where = '1 = 1';
        switch($this->table_info->get_primary_key_php_type()) {
			case 'int':
				if ($last_pk !== null) {
					$where = "$primary_key_identifier > " . (int)$last_pk;
				}
				break;
			case 'string':
				if ($last_pk !== null) {
					$where = $primary_key_identifier . ' > "' . mysqli_real_escape_string($wpdb->dbh, $last_pk) . '"';
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
        $scanned_table_name_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query($table_name);
        $chunk_size_number = (int)$this->chunkSize;

        /**
         * A silly variable-based technique to get the last processed primary key.
         *
         * We assign the primary key value to this variable in each row expression,
         * and we process rows in a sorted order. At the end of the processing,
         * the variable contains the most recently seen primary key value.
		 * 
		 * Also, for version bumps, we are assigning a range of values from
		 * MAX(version_id) + 1 to MAX(version_id) + inserted/updated rows. There
		 * are no uniqueness guarantees. A concurrent update coming from a WordPress
		 * hook could assign a version_id that overlaps with our range. Similarly,
		 * two concurrent hooks could assign the same bumped version_id.
		 * 
		 * That's why the synchronization logic is not relying on unique version_ids.
         *
         * MySQL doesn't support the RETURNING keyword from Postgres so we're
         * simulating it with the MySQL tools that we have at our disposal.
         * 
         * See https://stackoverflow.com/questions/1388025/how-to-get-id-of-the-last-updated-row-in-mysql
         * for more context.
         */
        $wpdb->query("SET @last_processed_pk := null");
        $sql = $wpdb->prepare(
            "INSERT INTO {$sync_metadata_table_identifier} (
                `table_name`,
                `primary_key`,
                `version_id`,
                `hash_value`
            )
            SELECT
                %s,
                (SELECT @last_processed_pk := scanned.$primary_key_identifier),
                @next_version_id := @next_version_id + 1,
                $hash_expression
            FROM
				$scanned_table_name_identifier scanned,
				(SELECT @next_version_id := COALESCE( MAX( version_id ), 0 ) as next FROM {$sync_metadata_table_identifier}) v
            WHERE $where
            ORDER BY $primary_key_identifier ASC
            LIMIT $chunk_size_number
            ON DUPLICATE KEY UPDATE
                version_id = IF(
					{$sync_metadata_table_identifier}.hash_value != VALUES(hash_value), 
                    VALUES(version_id),
                    {$sync_metadata_table_identifier}.version_id
                ),
                hash_value = IF(
					{$sync_metadata_table_identifier}.hash_value != VALUES(hash_value),
					VALUES(hash_value),
					{$sync_metadata_table_identifier}.hash_value
				)",
            ...$bound_params
        );

        echo $sql;
        if(false === $wpdb->query($sql)) {
            // @todo Check the error?
            throw new Exception("Failed to index next records chunk: " . $wpdb->last_error);
        }

        $this->cursor['last_pk'] = $wpdb->get_var("SELECT @last_processed_pk");

        return $this->cursor['last_pk'] !== null;
    }

    /**
     * Getters for current state.
     */
    public function get_table_name(): ?string
    {
        return $this->cursor['table_name'] ?? null;
    }

    public function get_last_pk()
    {
        return $this->cursor['last_pk'];
    }

    public function get_cursor(): array
    {
        return $this->cursor;
    }

}
