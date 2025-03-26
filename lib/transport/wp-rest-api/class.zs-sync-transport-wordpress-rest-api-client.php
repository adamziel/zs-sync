<?php

/**
 * Synchronizes the resources from the remote sync server to the local WordPress site.
 *
 * @since {WP_VERSION}
 */
class ZS_Sync_Transport_Wordpress_Rest_Api_Client implements ZS_Sync_Client {

	/**
	 * @var string The base URL of the sync endpoints.
	 */
	private $base_url;

	/**
	 * Constructor.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param string $base_url The base URL of the remote WordPress site.
	 */
	public function __construct( $base_url ) {
		// Ensure the base URL ends with a slash
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * Get a list of resources from the remote site.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param ZS_Sync_Resource_List_Request $request The resource list request.
	 * @return array|WP_Error Array of resources on success, WP_Error on failure.
	 */
	public function list_resources( ZS_Sync_Resource_List_Request $request ): ZS_Sync_Response_Error|array {
		$url = $this->base_url . '/list';
		$response = wp_remote_post( $url, [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body' => $request->to_json_string(),
		] );
		
		if ( is_wp_error( $response ) ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, $response->get_error_message() );
		}
		
		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		if ( $status_code !== 200 ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, $body );
		}
		
		$resources = json_decode( $body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, 'Failed to decode JSON response: ' . json_last_error_msg() . '. Response: ' . $body );
		}
		
		return $resources;
	}

	/**
	 * Fetch resources from the remote site.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param ZS_Sync_Resource_Fetch_Request $request The resource fetch request.
	 * @return string|WP_Error CBOR-encoded string of resources on success, WP_Error on failure.
	 */
	public function get_resources( ZS_Sync_Resource_Fetch_Request $request ): ZS_Sync_Response_Error|CBOR\MapObject {
		$url = $this->base_url . '/fetch';
		$response = wp_remote_post( $url, [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'body' => $request->to_json_string(),
		] );
		
		if ( is_wp_error( $response ) ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, $response->get_error_message() );
		}
		
		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		
		if ( $status_code !== 200 ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, $body );
		}
		
		return ZS_Sync_Resource_Provider::parse_get_resources_response( $body );
	}

	
	
	/**
	 * Process CBOR response into PHP objects.
	 * 
	 * @since {WP_VERSION}
	 * 
	 * @param string $cbor_data The CBOR-encoded data.
	 * @return array|WP_Error Decoded data on success, WP_Error on failure.
	 */
	public function process_cbor_response( $cbor_data ) {
		// Process CBOR data
		// This implementation will depend on how the project handles CBOR data
		// For now, returning a placeholder that would need to be implemented based on project requirements
		try {
			// Implementation to decode CBOR would go here
			// For example, if using a library:
			// return ZS_Sync_CBOR_Decoder::decode($cbor_data);
			
			// Placeholder for now - this will need to be implemented based on the project's CBOR processing approach
			return array(
				'status' => 'success',
				'message' => 'CBOR processing not fully implemented yet',
				'raw_data' => $cbor_data,
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'zs_sync_cbor_decode_error', $e->getMessage() );
		}
	}

}
