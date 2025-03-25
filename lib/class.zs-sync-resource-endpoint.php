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
						'required'          => true,
						'validate_callback' => function( $param ) {
							return is_string( $param );
						},
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
		// This would need to be implemented based on how resources are stored
		// For now, returning a placeholder response
		$resources = array(
			'resource_types' => array(
				'core.post',
				'core.file.wp-content',
				'zs-sync.manifest',
				// Add other resource types as needed
			),
		);

		return new WP_REST_Response( $resources, 200 );
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
