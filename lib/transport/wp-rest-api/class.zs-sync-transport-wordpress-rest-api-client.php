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
		// @TODO Use AsyncHttp\Client from the php-toolkit repo to remove curl dependency

		$url = $this->base_url . '/list';
		$response = $this->send_request( $url, 'POST', $request->to_json_string() );
		
		if ($response['curl_error']) {
			return ZS_Sync_Response_Error::create(ZS_Sync_Response_Error::BAD_RESPONSE, $response['curl_error']);
		}
		
		if ($response['status_code'] !== 200) {
			return ZS_Sync_Response_Error::create(ZS_Sync_Response_Error::BAD_RESPONSE, $response['body']);
		}
		
		$resources = json_decode($response['body'], true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ZS_Sync_Response_Error::create(ZS_Sync_Response_Error::BAD_RESPONSE, 'Failed to decode JSON response: ' . json_last_error_msg() . '. Response: ' . $body);
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
		$response = $this->send_request( $url, 'POST', $request->to_json_string() );
		
		if ( $response['curl_error'] ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, $response['curl_error'] );
		}
		
		if ( $response['status_code'] !== 200 ) {
			return ZS_Sync_Response_Error::create( ZS_Sync_Response_Error::BAD_RESPONSE, $response['body'] );
		}
		
		return ZS_Sync_Resource_Provider::parse_get_resources_response( $response['body'] );
	}

	private function send_request( $url, $method, $body ) {
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Content-Length: ' . strlen($body),
			],
		]);
		$body = curl_exec($curl);
		curl_close($curl);
		return [
			'body' => $body,
			'status_code' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
			'curl_error' => curl_error($curl),
		];
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
