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
        'files_output_dir' => './received-files',  // Where to save received files
        'csv_output_dir' => './received-data',     // Where to save CSV files
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

			// Set the next version from the last resource in the list
			$last_resource = end( $resources_list );
			if ( isset( $last_resource['time_of_last_scan'] ) && isset( $last_resource['hash_value'] ) ) {
				$this->next_version = [
					'time_of_last_scan' => $last_resource['time_of_last_scan'],
					'hash_value'        => $last_resource['hash_value'],
				];
			}

			// Process the resources in batches to avoid memory issues
			$batch_size = 10;
			$batches    = array_chunk( $resources_list, $batch_size );
			foreach ( $batches as $batch ) {
				$result = $this->process_resource_batch( $batch );

				// Update stats
				$stats['tables_processed'] += $result['tables_processed'];
				$stats['rows_processed']   += $result['rows_processed'];
				$stats['files_processed']  += $result['files_processed'];

				if ( ! empty( $result['errors'] ) ) {
					$stats['errors'] = array_merge( $stats['errors'], $result['errors'] );
				}
			}

			// Determine if there's more to fetch
			$has_more = count( $resources_list ) >= 100; // Default limit is 100
			$page ++;
		}

		return $stats;
	}

	/**
	 * List resources from the server
	 *
	 * @return array|ZS_Sync_Response_Error List of resources or error
	 */
	private function list_resources() {
		$request = new ZS_Sync_Resource_List_Request();

		// If we have a next version, use it for pagination
		if ( $this->next_version !== null ) {
			$request->since_version = $this->next_version;
		}

		return $this->client->list_resources( $request );
	}

	/**
	 * Process a batch of resources
	 *
	 * @param  array  $resources  List of resource data
	 *
	 * @return array Processing statistics
	 */
	private function process_resource_batch( $resources ) {
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
		try {
			// Since we don't know the exact structure of the CBOR object,
			// we'll try to handle it in a way that doesn't depend on specific methods
			$processedResources = 0;

			// Get the URIs from the original request to process them one by one
			foreach ( $response->getIterator() as $entry ) {
				$uri    = $entry->getKey()->getValue();
				$zs_uri = ZS_Sync_URI::from_string( $uri );
				$value  = $entry->getValue();

				if ( $value instanceof NullObject ) {
					// @TODO: Delete the local resource if it wasn't updated since the last sync
					continue;
				}

				switch ( $zs_uri->resource_type ) {
					case 'files':
						$this->save_file( $zs_uri, $value->getValue() );
						$stats['files_processed'] ++;
						break;
					case 'blob_key':
					case 'bigint_key':
					case 'composite_key':
					case 'bigint_two_tuple_key':
						$this->save_table_row( $zs_uri->id_type, $value );
						break;
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
		} catch ( Exception $e ) {
			$stats['errors'][] = "Error processing CBOR data: " . $e->getMessage();
		}

		return $stats;
	}

	/**
	 * Save a file to the local filesystem
	 *
	 * @param  ZS_Sync_URI  $uri  Resource URI
	 * @param  string  $data  Binary file data
	 */
	private function save_file( ZS_Sync_URI $uri, $data ) {
		// Get the file path from the URI
		$file_path = $uri->id;

		// Prepare the local path
		$local_path = $this->files_output_dir . '/' . $file_path;

		// Create directory structure if needed
		$dir = dirname( $local_path );
		if ( ! file_exists( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		// Write the file
		file_put_contents( $local_path, $data );
	}

	/**
	 * Save table row data to CSV
	 *
	 * @param  string  $table_name  Name of the table
	 * @param  array  $data  Row data from CBOR response
	 */
	private function save_table_row( $table_name, $data ) {
		$csv_path = $this->csv_output_dir . '/' . $table_name . '.csv';

		// Check if this is a new table (need to create CSV with headers)
		$is_new_table = ! isset( $this->processed_tables[ $table_name ] );

		// Open the CSV file in append mode
		$file_handle = fopen( $csv_path, $is_new_table ? 'w' : 'a' );

		$table_info = ZS_Sync_Table_Info::for( $table_name );

		// If this is a new table, write the headers
		if ( $is_new_table ) {
			// For a new table, we need to determine the column names
			// We'll use the keys from the Table_Info if available, or create columns with generic names
			$headers = [];
			for ( $i = 0; $i < count( $data ); $i ++ ) {
				$headers[] = array_values($table_info->get_fields())[ $i ]->Field;
			}

			fputcsv( $file_handle, $headers, ',', '"', '\\' );
			$this->processed_tables[ $table_name ] = $headers;
		}

		// Convert CBOR data to PHP values for CSV
		$row_data = [];
		foreach ( $data as $value ) {
			if ( $value === null ) {
				$row_data[] = '';
			} elseif ( $value instanceof NegativeBigIntegerTag || $value instanceof UnsignedBigIntegerTag ) {
				$row_data[] = $value->getValue()->getValue();
			} elseif ( $value instanceof ByteStringObject || $value instanceof TextStringObject || $value instanceof UnsignedIntegerObject ) {
				$row_data[] = $value->getValue();
			} elseif ( $value instanceof NullObject ) {
				$row_data[] = '';
			} elseif ( $value instanceof TimestampTag ) {
				$row_data[] = $value->getValue()->getValue();
			} else {
				$row_data[] = $value;
			}
		}

		// Write the row to the CSV file
		fputcsv( $file_handle, $row_data, ',', '"', '\\' );

		// Close the file
		fclose( $file_handle );
	}
}
