<?php

/**
 * ZS_Sync_Resource_Endpoint class.
 *
 * @since {WP_VERSION}
 */
class ZS_Sync_Resource_Endpoint {
	/**
	 * The request registry for resource providers.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var ZS_Sync_Request_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param ZS_Sync_Request_Registry $registry The request registry.
	 */
	public function __construct( ZS_Sync_Request_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Register the REST API routes.
	 *
	 * @since {WP_VERSION}
	 */
	public function register_routes() {
		register_rest_route(
			'zs-sync/v1',
			'/resources',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_resources' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'zs-sync/v1',
			'/resource/(?P<uri>.*)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_resource' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'uri' => array(
						'required'          => false,
						'validate_callback' => function( $param ) {
							return is_string( $param );
						},
					),
					'client_has_version' => array(
						'required'          => false,
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
						'sanitize_callback' => 'absint',
					),
					'page' => array(
						'required'          => false,
						'default'           => 1,
						'validate_callback' => function( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Check if the request has permission to access the endpoint.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function check_permission( $request ) {
		// Implement your permission logic here
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get a list of available resources.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function get_resources( $request ) {
		$uri_string = $request->get_param( 'uri' );
		$client_has_version = $request->get_param( 'client_has_version' );
		$page = $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1;
		$per_page = 100; // Fixed page size of 100 entries
		$offset = ( $page - 1 ) * $per_page;
		
		// If we're requesting a specific URI, use the original functionality
		if ( $uri_string ) {
			$uri_object = ZS_Sync_URI::from_string( $uri_string );

			if ( null === $uri_object ) {
				return new WP_Error(
					'invalid_uri',
					'The provided URI is invalid.',
					array( 'status' => 400 )
				);
			}

			$resource = $this->registry->provide( $uri_object );

			if ( null === $resource ) {
				return new WP_Error(
					'resource_not_found',
					'The requested resource was not found.',
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response( $resource, 200 );
		}
		
		// If we're retrieving metadata with pagination
		global $wpdb;
		
		// Prepare the WHERE clause for filtering by hash_value
		$hash_filter = '';
		if ( ! empty( $client_has_version ) ) {
			$client_hash = absint( $client_has_version );
			$hash_filter = $wpdb->prepare( ' WHERE hash_value != %d ', $client_hash );
		}
		
		// Build the UNION SELECT query combining all metadata tables
		$union_query = "
			(SELECT 
				'bigint_key' AS table_type,
				table_name,
				CAST(primary_key AS CHAR) AS primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__bigint_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'bigint_two_tuple_key' AS table_type,
				table_name,
				'' AS primary_key, 
				CAST(primary_key_first AS CHAR) AS primary_key_first,
				CAST(primary_key_second AS CHAR) AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'blob_key' AS table_type,
				table_name,
				primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__blob_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'composite_key' AS table_type,
				table_name,
				primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__composite_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'files' AS table_type,
				'' AS table_name,
				'' AS primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				file_path,
				time_of_last_scan,
				hash_value,
				filesize
			FROM {$wpdb->prefix}wp_sync_metadata__files
			{$hash_filter})
			
			ORDER BY time_of_last_scan DESC
			LIMIT %d OFFSET %d
		";
		
		$query = $wpdb->prepare(
			$union_query,
			$per_page,
			$offset
		);
		
		$results = $wpdb->get_results( $query );
		
		// Count total records for pagination
		$count_query = "
			SELECT COUNT(*) AS total FROM (
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__bigint_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__blob_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__composite_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__files {$hash_filter}
			) AS combined_counts";
		
		$total_records = $wpdb->get_var( $count_query );
		$total_pages = ceil( $total_records / $per_page );
		
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
					$resource_data['table_name'] = $item->table_name;
					$resource_data['primary_key'] = (int) $item->primary_key;
					break;
				case 'bigint_two_tuple_key':
					$resource_data['table_name'] = $item->table_name;
					$resource_data['primary_key_first'] = (int) $item->primary_key_first;
					$resource_data['primary_key_second'] = (int) $item->primary_key_second;
					break;
				case 'blob_key':
				case 'composite_key':
					$resource_data['table_name'] = $item->table_name;
					$resource_data['primary_key'] = $item->primary_key;
					break;
				case 'files':
					$resource_data['file_path'] = $item->file_path;
					$resource_data['filesize'] = (int) $item->filesize;
					break;
			}
			
			$resources[] = $resource_data;
		}
		
		$response = new WP_REST_Response( 
			array(
				'resources'    => $resources,
				'total'        => (int) $total_records,
				'total_pages'  => (int) $total_pages,
				'current_page' => (int) $page,
			),
			200
		);
		
		// Add pagination headers
		$response->header( 'X-WP-Total', (int) $total_records );
		$response->header( 'X-WP-TotalPages', (int) $total_pages );
		
		return $response;
	}

	/**
	 * Get a specific resource by its URI.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function get_resource( $request ) {
		$uri_string = $request->get_param( 'uri' );
		$client_has_version = $request->get_param( 'client_has_version' );
		$page = $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1;
		$per_page = 100; // Fixed page size of 100 entries
		$offset = ( $page - 1 ) * $per_page;
		
		// If we're requesting a specific URI, use the original functionality
		if ( $uri_string ) {
			$uri_object = ZS_Sync_URI::from_string( $uri_string );

			if ( null === $uri_object ) {
				return new WP_Error(
					'invalid_uri',
					'The provided URI is invalid.',
					array( 'status' => 400 )
				);
			}

			$resource = $this->registry->provide( $uri_object );

			if ( null === $resource ) {
				return new WP_Error(
					'resource_not_found',
					'The requested resource was not found.',
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response( $resource, 200 );
		}
		
		// If we're retrieving metadata with pagination
		global $wpdb;
		
		// Prepare the WHERE clause for filtering by hash_value
		$hash_filter = '';
		if ( ! empty( $client_has_version ) ) {
			$client_hash = absint( $client_has_version );
			$hash_filter = $wpdb->prepare( ' WHERE hash_value != %d ', $client_hash );
		}
		
		// Build the UNION SELECT query combining all metadata tables
		$union_query = "
			(SELECT 
				'bigint_key' AS table_type,
				table_name,
				CAST(primary_key AS CHAR) AS primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__bigint_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'bigint_two_tuple_key' AS table_type,
				table_name,
				'' AS primary_key, 
				CAST(primary_key_first AS CHAR) AS primary_key_first,
				CAST(primary_key_second AS CHAR) AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'blob_key' AS table_type,
				table_name,
				primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__blob_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'composite_key' AS table_type,
				table_name,
				primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				'' AS file_path,
				time_of_last_scan,
				hash_value,
				0 AS filesize
			FROM {$wpdb->prefix}wp_sync_metadata__composite_key
			{$hash_filter})
			
			UNION ALL
			
			(SELECT 
				'files' AS table_type,
				'' AS table_name,
				'' AS primary_key, 
				'' AS primary_key_first,
				'' AS primary_key_second,
				file_path,
				time_of_last_scan,
				hash_value,
				filesize
			FROM {$wpdb->prefix}wp_sync_metadata__files
			{$hash_filter})
			
			ORDER BY time_of_last_scan DESC
			LIMIT %d OFFSET %d
		";
		
		$query = $wpdb->prepare(
			$union_query,
			$per_page,
			$offset
		);
		
		$results = $wpdb->get_results( $query );
		
		// Count total records for pagination
		$count_query = "
			SELECT COUNT(*) AS total FROM (
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__bigint_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__bigint_two_tuple_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__blob_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__composite_key {$hash_filter}
				UNION ALL
				SELECT 1 FROM {$wpdb->prefix}wp_sync_metadata__files {$hash_filter}
			) AS combined_counts";
		
		$total_records = $wpdb->get_var( $count_query );
		$total_pages = ceil( $total_records / $per_page );
		
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
					$resource_data['table_name'] = $item->table_name;
					$resource_data['primary_key'] = (int) $item->primary_key;
					break;
				case 'bigint_two_tuple_key':
					$resource_data['table_name'] = $item->table_name;
					$resource_data['primary_key_first'] = (int) $item->primary_key_first;
					$resource_data['primary_key_second'] = (int) $item->primary_key_second;
					break;
				case 'blob_key':
				case 'composite_key':
					$resource_data['table_name'] = $item->table_name;
					$resource_data['primary_key'] = $item->primary_key;
					break;
				case 'files':
					$resource_data['file_path'] = $item->file_path;
					$resource_data['filesize'] = (int) $item->filesize;
					break;
			}
			
			$resources[] = $resource_data;
		}
		
		$response = new WP_REST_Response( 
			array(
				'resources'    => $resources,
				'total'        => (int) $total_records,
				'total_pages'  => (int) $total_pages,
				'current_page' => (int) $page,
			),
			200
		);
		
		// Add pagination headers
		$response->header( 'X-WP-Total', (int) $total_records );
		$response->header( 'X-WP-TotalPages', (int) $total_pages );
		
		return $response;
	}

	/**
	 * Process the request and return the response.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param string $method     The HTTP method.
	 * @param string $path       The request path.
	 * @param array  $query_args The query arguments.
	 * @param string $body       The request body.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function handle( string $method, string $path, array $query_args, string $body ) {
		// This method is now primarily used as a legacy handler
		// Most functionality is handled by the REST API callbacks
		
		if ( 'GET' === $method && strpos( $path, '/resource/' ) === 0 ) {
			$uri_string = substr( $path, strlen( '/resource/' ) );
			$uri_object = ZS_Sync_URI::from_string( $uri_string );
			
			if ( null === $uri_object ) {
				return new WP_Error( 'invalid_uri', 'Invalid URI format', array( 'status' => 400 ) );
			}
			
			$resource = $this->registry->provide( $uri_object );
			
			if ( null === $resource ) {
				return new WP_Error( 'resource_not_found', 'Resource not found', array( 'status' => 404 ) );
			}
			
			return new WP_REST_Response( $resource, 200 );
		}
		
		return new WP_Error( 'invalid_request', 'Invalid request', array( 'status' => 400 ) );
	}
}
