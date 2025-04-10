<?php

/**
 * Class ZS_Sync_Data_Importer
 *
 * Handles importing data from a remote sync server:
 * - Lists and retrieves all resources
 * - Writes database-related data to CSV files (one per table)
 * - Recreates received file structure in a local directory
 */
class ZS_Sync_Data_Importer {
	/**
	 * @var ZS_Sync_Client The client to use for retrieving resources
	 */
	private $client;

	/**
	 * @var string Output directory for received files
	 */
	private $files_output_dir;

	/**
	 * @var array Keeps track of the next resource list request version
	 */
	private $last_processed_version = null;

	private $mysqli;
	private $existing_tables = [];

	private $has_more = true;
	private $stats;

	/**
	 * Constructor
	 *
	 * @param  ZS_Sync_Client  $client  Client to use for communication
	 * @param  array  $options  Optional configuration settings
	 */
	public function __construct( ZS_Sync_Client $client, array $options = [] ) {
		$this->client = $client;

		if ( isset( $options['files_output_dir'] ) ) {
			$this->files_output_dir = $options['files_output_dir'];
		} else {
			throw new Exception('files_output_dir is required');
		}

		if(isset($options['last_processed_version'])) {
			$this->last_processed_version = $options['last_processed_version'];
		}

		// Create output directories if they don't exist
		if ( ! file_exists( $this->files_output_dir ) ) {
			mkdir( $this->files_output_dir, 0755, true );
		}

		$this->mysqli = $options['mysqli'];
		
		// Get existing tables using mysqli
		$result = $this->mysqli->query("SHOW TABLES");
		$this->existing_tables = [];
		if ($result) {
			while ($row = $result->fetch_row()) {
				$this->existing_tables[] = $row[0];
			}
			$result->free();
		}
	}

	/**
	 * Run the import process
	 *
	 * @return array Statistics about the import process
	 */
	public function import_step() {
		$this->stats = [
			'tables_processed' => 0,
			'rows_processed'   => 0,
			'files_processed'  => 0,
			'errors'           => [],
		];

		$resources_response = $this->list_resources($this->last_processed_version);
		var_dump($resources_response);
		$resources_list = $resources_response['resources'];
		$this->has_more = $resources_response['has_more'];

		if ( $resources_list instanceof ZS_Sync_Response_Error ) {
			$this->stats['errors'][] = 'Error listing resources: ' . $resources_list->message;
			return false;
		}

		if ( empty( $resources_list ) ) {
			return false;
		}

		// Fetch the files first
		$download_results = $this->fetch_files( $resources_list );
		
		// Log any download errors
		foreach ($download_results as $uri => $result) {
			if (!$result['success']) {
				$this->stats['errors'][] = "Failed to download file {$uri}: " . $result['error'];
			}
		}

		// The fetch the rest of the resources and process them
		$database_resources = array_filter($resources_list, function($resource) {
			return isset($resource['type']) && $resource['type'] !== 'files';
		});
		$result = $this->process_database_records( $database_resources );

		echo "Processed " . count($database_resources) . " database records and " . count($download_results) . " files.\n";

		// Update stats
		$this->stats['tables_processed'] += $result['tables_processed'];
		$this->stats['rows_processed']   += $result['rows_processed'];
		$this->stats['files_processed']  += $result['files_processed'];

		if ( ! empty( $result['errors'] ) ) {
			$this->stats['errors'] = array_merge( $this->stats['errors'], $result['errors'] );
		}

		// Set the next version from the last resource in the list
		$last_resource = end( $resources_list );
		if ( isset( $last_resource['time_of_last_scan'] ) && array_key_exists( 'hash_value', $last_resource ) ) {
			$this->last_processed_version = [
				'time_of_last_scan' => $last_resource['time_of_last_scan'],
				'hash_value'        => $last_resource['hash_value'],
			];
		}

		return true;
	}

	public function get_last_processed_version() {
		return $this->last_processed_version;
	}

	public function get_stats() {
		return $this->stats;
	}

	public function has_more() {
		return $this->has_more;
	}

	private function fetch_files( $resources_list ) {
		$file_resources = [];
		foreach($resources_list as $resource) {
			if (!isset($resource['type']) || $resource['type'] !== 'files') {
				continue;
			}
			if($resource['is_directory']) {
				if(null === $resource['hash_value']) {
					rmdir($this->files_output_dir . '/' . $resource['file_path']);
				} elseif(!is_dir($this->files_output_dir . '/' . $resource['file_path'])) {
					mkdir($this->files_output_dir . '/' . $resource['file_path']);
				}
				continue;
			}
			if(null === $resource['hash_value']) {
				$this->delete_file($resource['uri']);
				continue;
			}
			$file_resources[] = $resource;
		}
		echo "Downloading " . count($file_resources) . " resources...\n";
		
		$downloader = new ZS_Sync_File_Downloader($this->client, [
			'temp_dir' => __DIR__ . '/temp',
			'chunk_size' => 1024 * 1024,
			'target_dir' => $this->files_output_dir,
		]);
		
		$download_results = $downloader->fetch_files($file_resources);
		return $download_results;
	}

	/**
	 * List resources from the server
	 *
	 * @return array|ZS_Sync_Response_Error List of resources or error
	 */
	private function list_resources( $since_version = null ) {
		$request = ZS_Sync_Resource_List_Request::from_array([
			'limit' => 1000,
			'since_version' => $since_version,
		]);
		return $this->client->list_resources( $request );
	}

	/**
	 * Process a batch of resources
	 *
	 * @param  array  $resources  List of resource data
	 *
	 * @return array Processing statistics
	 */
	private function process_database_records( $resources ) {
		$stats = [
			'tables_processed'      => 0,
			'rows_processed'        => 0,
			'files_processed'       => 0,
			'errors'                => [],
			'processed_table_names' => [],
		];

		// Prepare resource fetch request
		$fetch_request = ZS_Sync_Resource_Fetch_Request::from_array( [
			'resources' => array_map( function ( $resource ) {
				return $resource['uri'];
			}, $resources ),
		] );

		// Fetch the resources
		$response = $this->client->get_resources( $fetch_request );

		if ( $response instanceof ZS_Sync_Response_Error ) {
			$stats['errors'][] = 'Error fetching resources: ' . $response->message;

			return $stats;
		}

		// Process the CBOR data
		// try {
			// Since we don't know the exact structure of the CBOR object,
			// we'll try to handle it in a way that doesn't depend on specific methods
			$processedResources = 0;

			// Get the URIs from the original request to process them one by one
			foreach ( $response->getIterator() as $entry ) {
				$uri    = $entry->getKey()->getValue();
				$zs_uri = ZS_Sync_URI::from_string( $uri );
				$value  = $entry->getValue();

				if ( $value instanceof NullObject ) {
					$this->delete_table_row( $zs_uri->id_type, $value );
				} else {
					$this->save_table_row( $zs_uri->id_type, $value );
				}
				// $stats['rows_processed'] ++;

				// // Only count unique tables
				// if ( ! isset( $stats['processed_table_names'][ $table_name ] ) ) {
				// 	$stats['processed_table_names'][ $table_name ] = true;
				// 	$stats['tables_processed'] ++;
				// }
				$processedResources ++;
			}

			if ( $processedResources === 0 ) {
				// We didn't process any resources, which might indicate an issue with the response format
				$stats['errors'][] = "Warning: No resources were processed from the response.";
			}
		// } catch ( Exception $e ) {
		// 	$stats['errors'][] = "Error processing CBOR data: " . $e->getMessage();
		// }

		return $stats;
	}

	/**
	 * Save table row data to database
	 *
	 * @param  string  $table_name  Name of the table
	 * @param  array  $data  Row data from CBOR response
	 */
	private function save_table_row( $table_name, $data ) {
		// If the table doesn't exist, create it
		if ( ! in_array( $table_name, $this->existing_tables ) ) {
			$resource_uri = 'create_table:' . $table_name.':-';
			$response = $this->client->get_resources( ZS_Sync_Resource_Fetch_Request::from_array([
				'resources' => [
					$resource_uri,
				],
			]) );
			$create_table = $response->get( $resource_uri )->getValue();
			// var_dump($create_table);
			$this->mysqli->query( $create_table );
			$this->existing_tables[] = $table_name;
		}
		
		// Convert CBOR data to PHP values
		$row_data = [];
		foreach ( $data as $value ) {
			if ( $value instanceof NegativeBigIntegerTag || $value instanceof UnsignedBigIntegerTag ) {
				$row_data[] = (int)$value->getValue()->getValue();
			} elseif ( $value instanceof ByteStringObject || $value instanceof TextStringObject || $value instanceof UnsignedIntegerObject ) {
				$row_data[] = ZS_Sync_MySQL_Helper::to_safe_expression($value->getValue());
			} elseif ( $value instanceof NullObject || $value === null ) {
				$row_data[] = 'NULL';
			} else {
				$row_data[] = ZS_Sync_MySQL_Helper::to_safe_expression($value);
			}
		}
		// print_r($row_data);
		$table_identifier = ZS_Sync_MySQL_Helper::schema_object_name_for_query( $table_name );

		// @TODO: Skip certain tables and rows, e.g. site URLs, transients, etc.
		//        They should not be transferred anyway, but if they are, the client
		//        could simply reject them.
		// @TODO: Make it filterable
		
		// @TODO: Rewrite certain columns, e.g. site URLs in post_content. Make it filterable
		//        for plugins to support any custom data rewriting logic.

		$columns = $this->get_table_columns( $table_name );
		$sql = "INSERT INTO $table_identifier (" . implode(', ', $columns) . ")
				VALUES (" . implode(', ', $row_data) . ")
				ON DUPLICATE KEY UPDATE ";

		$updates = [];
		foreach ($columns as $column) {
			$updates[] = "$column = VALUES($column)";
		}
		$sql .= implode(', ', $updates);

		try {
			$this->mysqli->query($sql);
			if ($this->mysqli->error) {
				error_log("Error upserting data into $table_name: " . $this->mysqli->error);
			}
		} catch (Exception $e) {
			// Log the error but continue processing
			error_log("Error upserting data into $table_name: " . $e->getMessage());
		}
	}

	/**
	 * Temporary method to be replaced by ZS_Sync_Table_Info call. Only used
	 * because we initiate a second mysqli connection to another database.
	 */
	private function get_table_columns( $table_name ) {
		$columns = [];
		try {
			$result = $this->mysqli->query("DESCRIBE $table_name");
			if ($result) {
				while ($row = $result->fetch_assoc()) {
					$columns[] = ZS_Sync_MySQL_Helper::schema_object_name_for_query( $row['Field'] );
				}
				$result->free();
			}
		} catch (Exception $e) {
			// Log the error but continue processing
			error_log("Error getting columns for $table_name: " . $e->getMessage());
		}
		return $columns;
	}
	
	/**
	 * Delete table row data from database
	 *
	 * @param  string  $table_name  Name of the table
	 * @param  mixed  $data  Data identifying the row to delete
	 */
	private function delete_table_row( $table_name, $data ) {
		// Delete all rows for this table
		$table_identifier = ZS_Sync_MySQL_Helper::schema_object_name_for_query( $table_name );
		$sql = "DELETE FROM $table_identifier";
		$this->mysqli->query($sql);
		if ($this->mysqli->error) {
			error_log("Error deleting from $table_name: " . $this->mysqli->error);
		}
	}
	

	private function delete_file( $uri_string ) {
		$uri = ZS_Sync_URI::from_string( $uri_string );
		$file_path = $uri->id;
		$local_path = $this->files_output_dir . '/' . $file_path;
		if ( file_exists( $local_path ) ) {
			unlink( $local_path );
		}
	}
}

