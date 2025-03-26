<?php

class ZS_Sync_Resource_Provider {
	/**
	 * @var int The maximum size of file chunks to read, in bytes
	 */
	private $max_file_chunk_size;

	/**
	 * @var int The maximum number of files to include in a response
	 */
	private $max_file_chunks_per_response;

	/**
	 * @var int The maximum number of database rows to include in a response
	 */
	private $max_db_rows_per_response;

	/**
	 * Constructor.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param array $options {
	 *     Optional. An array of options.
	 *
	 *     @type int $max_file_chunk_size The size of file chunks to read, in bytes. Default 1MB.
	 *     @type int $max_file_chunks_per_response The maximum number of files to include in a response. Default 100.
	 *     @type int $max_db_rows_per_response The maximum number of database rows to include in a response. Default 1000.
	 * }
	 */
	public function __construct( array $options = [] ) {
		$this->max_file_chunk_size = $options['max_file_chunk_size'] ?? 1024 * 1024; // 1MB default
		$this->max_file_chunks_per_response = $options['max_file_chunks_per_response'] ?? 5;
		$this->max_db_rows_per_response = $options['max_db_rows_per_response'] ?? 1000;
	}

	public function list_resources( $query = array() ) {
		$client_version = $query['client_version'] ?? null;

		// If we're retrieving metadata with pagination
		global $wpdb;

		// Build the UNION SELECT query combining all metadata tables
		$scan_timestamp_filter = '';
		if ( ! empty( $client_version ) ) {
			// @TODO: Account for `hash_value=null`
			$scan_timestamp_filter =
				'AND (time_of_last_scan, hash_value) >= (' .
					ZS_Sync_Mysql_Helper::string_to_safe_expression( $client_version['time_of_last_scan'] ) . ', ' .
					((int)$client_version['hash_value']) .
				')';
		}

		$union_query = "
			SELECT * FROM (
				(SELECT 
					'bigint_key' AS table_type,
					table_name,
					CAST(primary_key AS CHAR) AS primary_key, 
					time_of_last_scan,
					hash_value,
					0 AS filesize
				FROM {$wpdb->prefix}wp_sync_metadata__bigint_key)
				
				UNION ALL
				
				(SELECT 
					'bigint_two_tuple_key' AS table_type,
					table_name,
					JSON_ARRAY(
						primary_key_first,
						primary_key_second
					) AS primary_key,
					time_of_last_scan,
					hash_value,
					0 AS filesize
				FROM {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key)
				
				UNION ALL
				
				(SELECT 
					'blob_key' AS table_type,
					table_name,
					primary_key, 
					time_of_last_scan,
					hash_value,
					0 AS filesize
				FROM {$wpdb->prefix}wp_sync_metadata__blob_key)
				
				UNION ALL
				
				(SELECT 
					'composite_key' AS table_type,
					table_name,
					primary_key, 
					time_of_last_scan,
					hash_value,
					0 AS filesize
				FROM {$wpdb->prefix}wp_sync_metadata__composite_key)
				
				UNION ALL
				
				(SELECT 
					'files' AS table_type,
					'' AS table_name,
					file_path AS primary_key, 
					time_of_last_scan,
					hash_value,
					filesize
				FROM {$wpdb->prefix}wp_sync_metadata__files)
			) AS sub

			WHERE true $scan_timestamp_filter
			-- Stable ordering. If the hash_value changes, the time_of_last_scan will also change.
			ORDER BY time_of_last_scan ASC, table_name ASC, hash_value ASC
			LIMIT 100
		";

		$results = $wpdb->get_results( $union_query );

		// Process results to create a consistent format
		$resources = array();
		foreach ( $results as $item ) {
			$resource_data = array(
				'type'             => $item->table_type,
				'time_of_last_scan'=> $item->time_of_last_scan,
				'hash_value'       => $item->hash_value,
			);

			switch ( $item->table_type ) {
				case 'bigint_key':
					$resource_data['primary_key'] = (int) $item->primary_key;
					$resource_data['uri'] = ZS_Sync_URI::from_data( $item->table_type, $item->table_name, $item->primary_key )->__toString();
					break;
				case 'blob_key':
				case 'composite_key':
				case 'bigint_two_tuple_key':
					$resource_data['primary_key'] = $item->primary_key;
					$resource_data['uri'] = ZS_Sync_URI::from_data( $item->table_type, $item->table_name, $item->primary_key )->__toString();
					break;
				case 'files':
					$resource_data['file_path'] = $item->primary_key;
					$resource_data['filesize'] = (int) $item->filesize;
					$resource_data['uri'] = ZS_Sync_URI::from_data( $item->table_type, 'path', $item->primary_key )->__toString();
					break;
			}

			$resources[] = $resource_data;
		}

		return $resources;
	}
	public function get_resources( $request ) {
		global $wpdb;

		$resources = array();
		$db_rows = 0;
		$file_chunks = 0;
		foreach ( $request as $uri_or_query ) {
			if(is_array($uri_or_query)) {
				$uri = $uri_or_query['uri'];
				$query = $uri_or_query;
			} else {
				$uri = $uri_or_query;
				$query = array();
			}

			$zs_uri = ZS_Sync_URI::from_string( $uri );
			switch ( $zs_uri->resource_type ) {
				case 'bigint_key':
					if( $db_rows >= $this->max_db_rows_per_response ) {
						// @TODO: Stop processing and return an error?
						continue 2;
					}
					$db_rows++;
					$table_name = $zs_uri->id_type;
					$table_name_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
					$table_info = ZS_Sync_Table_Info::for( $table_name );
					$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_info->get_primary_keys()[0] );
					$resources[$uri] = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id ) );
					break;
				case 'bigint_two_tuple_key':
					if( $db_rows >= $this->max_db_rows_per_response ) {
						continue 2;
					}
					$db_rows++;
					$table_name = $zs_uri->id_type;
					$table_name_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
					$table_info = ZS_Sync_Table_Info::for( $table_name );
					$primary_key_first_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_info->get_primary_keys()[0] );
					$primary_key_second_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_info->get_primary_keys()[1] );
					$resources[$uri] = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_first_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id[0] ) . " AND {$primary_key_second_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id[1] ) );
					break;
				case 'blob_key':
					if( $db_rows >= $this->max_db_rows_per_response ) {
						continue 2;
					}
					$db_rows++;
					$table_name = $zs_uri->id_type;
					$table_name_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
					$table_info = ZS_Sync_Table_Info::for( $table_name );
					$primary_key_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_info->get_primary_keys()[0] );
					$resources[$uri] = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id ) );
					break;
				case 'composite_key':
					if( $db_rows >= $this->max_db_rows_per_response ) {
						continue 2;
					}
					$db_rows++;
					$table_name = $zs_uri->id_type;
					$table_name_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
					$table_info = ZS_Sync_Table_Info::for( $table_name );
					$primary_key_value = json_decode( $zs_uri->id, true );
					$primary_key_filter = [];
					foreach ( $table_info->get_primary_keys() as $key => $primary_key ) {
						$primary_key_filter[] = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $primary_key ) . ' = ' . ZS_Sync_Mysql_Helper::string_to_safe_expression( $primary_key_value[ $key ] );
					}
					$primary_key_filter = implode( ' AND ', $primary_key_filter );
					$resources[$uri] = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_filter}" );
					break;
				case 'files':
					if( $file_chunks >= $this->max_file_chunks_per_response ) {
						continue 2;
					}
					$file_chunks++;
					// Handle file range requests
					$root_path = __DIR__ . '/../tests/fixtures/';
					$file_path = $root_path . $zs_uri->id;
					
					// Get file size
					$filesize = filesize($file_path);
					
					// Default to reading the whole file within max chunk size
					$start = 0;
					$length = min($filesize, $this->max_file_chunk_size);
					
					// If range is specified in query, use it
					if (!empty($query) && isset($query['range']) && is_array($query['range'])) {
						// Check for 'from' key to determine start position
						if (isset($query['range']['from'])) {
							$start = (int)$query['range']['from'];
						}
						
						// Check for 'length' key to determine end position
						if (isset($query['range']['length'])) {
							$length = min($query['range']['length'], $this->max_file_chunk_size);
						} else {
							// If only 'from' is specified, read up to max_file_chunk_size from that position
							$length = min($filesize - $start, $this->max_file_chunk_size);
						}
					}
					
					// Read the file chunk
					$fp = fopen($file_path, 'rb');
					if ($fp) {
						fseek($fp, $start);
						$resources[$uri] = fread($fp, $length);
						fclose($fp);
					} else {
						$resources[$uri] = null;
					}
					break;
			}
		}

		return $resources;
	}
}
