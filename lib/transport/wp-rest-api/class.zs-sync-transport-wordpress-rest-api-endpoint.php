<?php


/**
 * ZS_Sync_Transport_Wordpress_Rest_Api_Endpoint class.
 *
 * This class serves as a network bridge between the resource provider and the sync client.
 * It exposes data from the authoritative host to the sync client through WordPress REST API.
 *
 * @since {WP_VERSION}
 */
class ZS_Sync_Transport_Wordpress_Rest_Api_Endpoint {
	/**
	 * The resource provider that supplies the data.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var ZS_Sync_Resource_Provider
	 */
	private $resource_provider;

	/**
	 * Constructor.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param ZS_Sync_Resource_Provider $resource_provider The resource provider.
	 */
	public function __construct( ZS_Sync_Resource_Provider $resource_provider ) {
		$this->resource_provider = $resource_provider;
	}

	/**
	 * Register the REST API routes.
	 *
	 * @since {WP_VERSION}
	 */
	public function register_routes() {
		register_rest_route(
			'zs-sync/v1',
			'/resources/list',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'list_resources' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			'zs-sync/v1',
			'/resources/fetch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_resources' ),
				'permission_callback' => array( $this, 'check_permission' ),
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
		// Get the authorization header
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header || ! preg_match( '/^Basic (.+)$/', $auth_header, $matches ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing or invalid authorization header.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		// Decode the credentials
		$credentials = base64_decode( $matches[1] );
		list( $username, $password ) = explode( ':', $credentials, 2 );

		// Get the source site and UUID from headers
		$source_site = $request->get_header( 'X-ZS-Sync-Source' );
		$uuid = $request->get_header( 'X-ZS-Sync-UUID' );

		if ( ! $source_site || ! $uuid ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing required headers.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		// Get stored connections
		$connections = get_option( 'zs_sync_connections', array() );

		// Check if this connection exists and is valid
		if ( ! isset( $connections[ $source_site ] ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Connection not found.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		$connection = $connections[ $source_site ];

		// @TODO: Verify connection credentials. They don't
		//        seem to be stored right now.
		// // Verify the UUID matches
		// if ( $connection['credentials']['uuid'] !== $uuid ) {
		// 	return new WP_Error(
		// 		'rest_forbidden',
		// 		__( 'Invalid connection UUID.', 'zs-sync' ),
		// 		array( 'status' => 401 )
		// 	);
		// }

		// // Verify the credentials match
		// if ( $connection['credentials']['username'] !== $username || 
		// 	 $connection['credentials']['password'] !== $password ) {
		// 	return new WP_Error(
		// 		'rest_forbidden',
		// 		__( 'Invalid credentials.', 'zs-sync' ),
		// 		array( 'status' => 401 )
		// 	);
		// }

		return true;
	}

	/**
	 * Get a list of available resources.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function list_resources( $request ) {
		$resource_list_request = ZS_Sync_Resource_List_Request::from_json_string( $request->get_body() );
		if ( $resource_list_request instanceof ZS_Sync_Request_Error ) {
			return new WP_REST_Response( $resource_list_request, $resource_list_request->code );
		}
		
		$resource_list = $this->resource_provider->list_resources( $resource_list_request );
		if ( is_wp_error( $resource_list ) ) {
			return $resource_list;
		}
		
		$response = new WP_REST_Response( $resource_list, 200 );
		
		return $response;
	}

	/**
	 * Get resources from the provider.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function get_resources( $request ) {
		// Respond in a CBOR binary format
		$resource_request = ZS_Sync_Resource_Fetch_Request::from_json_string( $request->get_body() );
		if ( $resource_request instanceof ZS_Sync_Request_Error ) {
			return new WP_REST_Response( $resource_request, $resource_request->code );
		}
		
		$resources_cbor = $this->resource_provider->get_resources( $resource_request );
		if ( $resources_cbor instanceof ZS_Sync_Request_Error ) {
			return new WP_REST_Response( $resources_cbor->__toString(), $resources_cbor->code );
		}

		// Not using WP_REST_Response because it wraps the string in quotes, presumably
		// encoding it as JSON.
		// @TODO: Figure out why this is happening.
		http_response_code(200);
		header('Content-Type: application/cbor');
		echo $resources_cbor;
		die();
	}		

}
