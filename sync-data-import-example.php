<?php

use CBOR\ByteStringObject;
use CBOR\OtherObject\NullObject;
use CBOR\Tag\NegativeBigIntegerTag;
use CBOR\Tag\TimestampTag;
use CBOR\Tag\UnsignedBigIntegerTag;
use CBOR\UnsignedIntegerObject;
use CBOR\TextStringObject;

require __DIR__ . '/../wordpress-develop/src/wp-load.php';

// Include required files for the ZS-Sync system
require_once __DIR__ . '/load.php';

// Set up error reporting for the example
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "Starting ZS Sync data import example...\n";

// Remote WordPress site to import from
$remote_site_url = 'http://127.0.0.1:5324/index.php?rest_route=%2Fzs-sync%2Fv1%2Fresources';

try {
    // Create client instance for the remote site
    $client = new ZS_Sync_Transport_Wordpress_Rest_Api_Client($remote_site_url);

    // Configure import options
    $import_options = [
        'files_output_dir' => __DIR__ . '/../sync-wp-content',  // Where to save received files
        'csv_output_dir' => __DIR__ . '/../sync-wp-content',     // Where to save CSV files
    ];

    // Create the importer
    $importer = new ZS_Sync_Data_Importer($client, $import_options);

    // Run the import process
    echo "Starting import process...\n";
    $stats = $importer->import();

    // Display results
    echo "\nImport completed:\n";
    echo "- Tables processed: {$stats['tables_processed']}\n";
    echo "- Rows processed: {$stats['rows_processed']}\n";
    echo "- Files processed: {$stats['files_processed']}\n";

    // Report any errors
    if (count($stats['errors']) > 0) {
        echo "\nErrors encountered during import:\n";
        foreach ($stats['errors'] as $index => $error) {
            echo ($index + 1) . ". $error\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}

echo "\nDone.\n"; 

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
	private $files_output_dir = './received-files';

	/**
	 * @var string Output directory for CSV files
	 */
	private $csv_output_dir = './received-data';

	/**
	 * @var array Keeps track of tables we've seen to manage CSV headers
	 */
	private $processed_tables = [];

	/**
	 * @var array Keeps track of the next resource list request version
	 */
	private $next_version = null;

	private $pdo;
	private $existing_tables = [];

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
		}

		if ( isset( $options['csv_output_dir'] ) ) {
			$this->csv_output_dir = $options['csv_output_dir'];
		}

		// Create output directories if they don't exist
		if ( ! file_exists( $this->files_output_dir ) ) {
			mkdir( $this->files_output_dir, 0755, true );
		}

		if ( ! file_exists( $this->csv_output_dir ) ) {
			mkdir( $this->csv_output_dir, 0755, true );
		}

		$this->pdo = new PDO('mysql:host=127.0.0.1', 'root', 'my-secret-pw');
		$this->pdo->query("CREATE DATABASE IF NOT EXISTS zs_sync_target");
		$this->pdo->query("USE zs_sync_target");
		$this->pdo->query("SET GLOBAL sql_mode='ALLOW_INVALID_DATES';");
		$this->existing_tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Run the import process
	 *
	 * @return array Statistics about the import process
	 */
	public function import() {
		$stats = [
			'tables_processed' => 0,
			'rows_processed'   => 0,
			'files_processed'  => 0,
			'errors'           => [],
		];

		$page     = 1;
		$has_more = true;

		while ( $has_more ) {
			echo "Processing page $page...\n";

			$resources_list = $this->list_resources();

			if ( $resources_list instanceof ZS_Sync_Response_Error ) {
				$stats['errors'][] = 'Error listing resources: ' . $resources_list->message;
				break;
			}

			if ( empty( $resources_list ) ) {
				$has_more = false;
				break;
			}

			// Fetch the files first
			$download_results = $this->fetch_files( $resources_list );
			
			// Log any download errors
			foreach ($download_results as $uri => $result) {
				if (!$result['success']) {
					$stats['errors'][] = "Failed to download file {$uri}: " . $result['error'];
				}
			}

			// The fetch the rest of the resources and process them
			$database_resources = array_filter($resources_list, function($resource) {
				return isset($resource['type']) && $resource['type'] !== 'files';
			});
			$result = $this->process_database_records( $database_resources );

			echo "Processed " . count($database_resources) . " database records and " . count($download_results) . " files.\n";

			// Update stats
			$stats['tables_processed'] += $result['tables_processed'];
			$stats['rows_processed']   += $result['rows_processed'];
			$stats['files_processed']  += $result['files_processed'];

			if ( ! empty( $result['errors'] ) ) {
				$stats['errors'] = array_merge( $stats['errors'], $result['errors'] );
			}

			// Set the next version from the last resource in the list
			$last_resource = end( $resources_list );
			if ( isset( $last_resource['time_of_last_scan'] ) && array_key_exists( 'hash_value', $last_resource ) ) {
				$this->next_version = [
					'time_of_last_scan' => $last_resource['time_of_last_scan'],
					'hash_value'        => $last_resource['hash_value'],
				];
			}

			// Determine if there's more to fetch
			$has_more = count( $resources_list ) >= 100; // Default limit is 100
			$page ++;
		}

		return $stats;
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
	private function list_resources() {
		$request = ZS_Sync_Resource_List_Request::from_array([
			'limit' => 100,
			'since_version' => $this->next_version,
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
	 * Save table row data to SQLite database
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
			$this->pdo->query( $create_table );
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
		
		// Build the INSERT ... ON DUPLICATE KEY UPDATE query. We'll be running this
		// with $wpdb in the future.
		$columns = $this->get_table_columns( $table_name );
		$sql = "INSERT INTO $table_identifier (" . implode(', ', $columns) . ")
				VALUES (" . implode(', ', $row_data) . ")
				ON DUPLICATE KEY UPDATE ";

		// Add the update part for each column
		$updates = [];
		foreach ($columns as $column) {
			$updates[] = "$column = VALUES($column)";
		}
		$sql .= implode(', ', $updates);
		// Prepare and execute the statement
		try {
			$this->pdo->exec($sql);
		} catch (PDOException $e) {
			// Log the error but continue processing
			error_log("Error upserting data into $table_name: " . $e->getMessage());
		}
	}

	/**
	 * Temporary method to be replaced by ZS_Sync_Table_Info call. Only used
	 * because we initiate a second PDO connection to another database.
	 */
	private function get_table_columns( $table_name ) {
		$columns = [];
		try {
			$stmt = $this->pdo->query("DESCRIBE $table_name");
			if ($stmt) {
				while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
					$columns[] = ZS_Sync_MySQL_Helper::schema_object_name_for_query( $row['Field'] );
				}
			}
		} catch (PDOException $e) {
			// Log the error but continue processing
			error_log("Error getting columns for $table_name: " . $e->getMessage());
		}
		return $columns;
	}
	
	/**
	 * Delete table row data from SQLite database
	 *
	 * @param  string  $table_name  Name of the table
	 * @param  mixed  $data  Data identifying the row to delete
	 */
	private function delete_table_row( $table_name, $data ) {
		// Delete all rows for this table
		$table_identifier = ZS_Sync_MySQL_Helper::schema_object_name_for_query( $table_name );
		$stmt = $this->pdo->prepare("DELETE FROM $table_identifier");
		$stmt->execute();
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
