<?php

use CBOR\Decoder;
use CBOR\StringStream;
use CBOR\OtherObject;
use CBOR\Tag;


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
	 * @var string The root path for files
	 */
	private $root_path;

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
	 *     @type string $root_path The root path for files. Default WP_CONTENT_DIR.
	 * }
	 */
	public function __construct( array $options = [] ) {
		$this->max_file_chunk_size = $options['max_file_chunk_size'] ?? 1024 * 1024; // 1MB default
		$this->max_file_chunks_per_response = $options['max_file_chunks_per_response'] ?? 5;
		$this->max_db_rows_per_response = $options['max_db_rows_per_response'] ?? 1000;
		$this->root_path = $options['root_path'] ?? WP_CONTENT_DIR;
	}

	public function list_resources( ZS_Sync_Resource_List_Request $request ) {
		$since_version = $request->since_version;
		$limit = (int)min($request->limit ?? 1000, 1000); // Ensure limit is no more than 1000

		// If we're retrieving metadata with pagination
		global $wpdb;

		// Build the UNION SELECT query combining all metadata tables
		$scan_timestamp_filter = '';
		if ( ! empty( $since_version ) ) {
			// @TODO: Account for `hash_value=null`
			$scan_timestamp_filter =
				'AND (time_of_last_scan, hash_value) >= (' .
					ZS_Sync_Mysql_Helper::string_to_safe_expression( $since_version['time_of_last_scan'] ) . ', ' .
					((int)$since_version['hash_value']) .
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
			LIMIT $limit
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
					$resource_data['is_directory'] = is_dir(wp_join_paths($this->root_path, $item->primary_key));
					break;
			}

			$resources[] = $resource_data;
		}

		return [
			'resources' => $resources,
			'has_more' => count($resources) === $limit,
		];
	}

	public function get_resources( ZS_Sync_Resource_Fetch_Request $request ) {
		global $wpdb;

		$cbor_map = new ZS_Sync_CBOR_Resource_Encoder();

		$db_rows = 0;
		$file_chunks = 0;
		
		foreach ( $request->resources as $resource_query ) {
			$zs_uri = $resource_query->uri;
			
			switch ( $zs_uri->resource_type ) {
				case 'create_table':
					$table_name = $zs_uri->id_type;
					$table_name_identifier = ZS_Sync_Mysql_Helper::schema_object_name_for_query($table_name);
					$create_table_query = $wpdb->get_var("SHOW CREATE TABLE {$table_name_identifier}");
					
					if ($create_table_query) {
						// Extract the CREATE TABLE statement from the result
						// SHOW CREATE TABLE returns a row with two columns: Table and Create Table
						if (is_array($create_table_query)) {
							$create_table_query = $create_table_query[1]; // Get the second column
						} elseif (strpos($create_table_query, 'CREATE TABLE') === false) {
							// If we got a string but it doesn't contain CREATE TABLE, try to get it directly
							$create_table_result = $wpdb->get_row("SHOW CREATE TABLE {$table_name_identifier}", ARRAY_N);
							if ($create_table_result && isset($create_table_result[1])) {
								$create_table_query = $create_table_result[1];
							}
						}
						
						// Add the create table statement to the CBOR response
						$cbor_map->add_byte_string($zs_uri->__toString(), $create_table_query);
					} else {
						// Table doesn't exist or error occurred
						$cbor_map->add_null($zs_uri->__toString());
					}
					break;
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
					$row = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id ) );
					$cbor_map->add_database_row( $zs_uri->__toString(), $row, $table_info );
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
					$row = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_first_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id[0] ) . " AND {$primary_key_second_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id[1] ) );
					$cbor_map->add_database_row( $zs_uri->__toString(), $row, $table_info );
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
					$row = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_identifier} = " . ZS_Sync_Mysql_Helper::string_to_safe_expression( $zs_uri->id ) );
					$cbor_map->add_database_row( $zs_uri->__toString(), $row, $table_info );
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
					$row = $wpdb->get_row( "SELECT * FROM {$table_name_identifier} WHERE {$primary_key_filter}" );
					$cbor_map->add_database_row( $zs_uri->__toString(), $row, $table_info );
					break;
				case 'files':
					if( $file_chunks >= $this->max_file_chunks_per_response ) {
						// @TODO: Stop processing and return an error?
						// continue 2;
					}
					$file_chunks++;

					$file_path = wp_join_paths( $this->root_path, $zs_uri->id );
										
					// Confirm the file exists
					if(!file_exists($file_path) || !is_file($file_path)) {
						// @TODO how to handle a missing file or a non-file?
						$cbor_map->add_file_chunk( $zs_uri->__toString(), 0, null );
						continue 2;
					}

					// Default to reading the whole file within max chunk size
					$start = $resource_query->range_start ?? 0;
					$length = $resource_query->range_length ?? $this->max_file_chunk_size;
					$length = min($length, filesize($file_path) - $start);
					if($length <= 0) {
						continue 2;
					}

					$fp = fopen($file_path, 'rb');
					if (!$fp) {
						// @TODO how to handle a file that cannot be opened?
						// $cbor_map->add_file_chunk( $zs_uri->__toString(), null );
						continue 2;
					}

					try {
						fseek($fp, $start);
						$file_chunk = fread($fp, $length);
						$cbor_map->add_file_chunk( $zs_uri->__toString(), $start, $file_chunk );
					} finally {
						fclose($fp);
					}
					break;
			}
		}

		// CBOR-encode the resources
		$cbor_map = $cbor_map->get_cbor_map();
		return $cbor_map->__toString();
	}

	static public function parse_get_resources_response( string $response ): CBOR\MapObject {
		$otherObjectManager = OtherObject\OtherObjectManager::create()
			->add(OtherObject\SimpleObject::class)
			->add(OtherObject\FalseObject::class)
			->add(OtherObject\TrueObject::class)
			->add(OtherObject\NullObject::class)
			->add(OtherObject\UndefinedObject::class)
			->add(OtherObject\HalfPrecisionFloatObject::class)
			->add(OtherObject\SinglePrecisionFloatObject::class)
			->add(OtherObject\DoublePrecisionFloatObject::class)
		;

		$tagManager = Tag\TagManager::create()
			->add(Tag\DatetimeTag::class)
			->add(Tag\TimestampTag::class)
			->add(Tag\NegativeBigIntegerTag::class)
			->add(Tag\UnsignedBigIntegerTag::class)
			->add(Tag\DecimalFractionTag::class)
			->add(Tag\BigFloatTag::class)
			->add(Tag\Base64UrlEncodingTag::class)
			->add(Tag\Base64EncodingTag::class)
			->add(Tag\Base16EncodingTag::class)
		;

		$decoder = Decoder::create($tagManager, $otherObjectManager);

		// Load and decode the CBOR data
		$stream = StringStream::create($response);
		$result = $decoder->decode($stream);

		return $result;
	}

}
