<?php

/**
 * Class ZS_Sync_Resource_List_Request
 * 
 * Represents a request for listing resources from the sync server.
 */
class ZS_Sync_Resource_List_Request {
	/**
	 * The version to list resources since.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var array|null
	 */
	public $since_version = null;

	/**
	 * The maximum number of resources to return.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var int
	 */
	public $limit = 100;

	/**
	 * Create a request from raw HTTP request data
	 * 
	 * @param string $json_request JSON string from HTTP request
	 * @return ZS_Sync_Resource_List_Request|ZS_Sync_Request_Error Resource request object or error
	 */
	public static function from_json_string(string $json_request): ZS_Sync_Resource_List_Request|ZS_Sync_Request_Error {
		$request_data = json_decode($json_request, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
		if (JSON_ERROR_NONE !== json_last_error()) {
			return ZS_Sync_Request_Error::create(
				ZS_Sync_Request_Error::BAD_REQUEST,
				'Request data is not valid JSON: ' . json_last_error_msg()
			);
		}
		
		return self::from_array($request_data);
	}

	/**
	 * Create a request from an array of data
	 * 
	 * @param array $request_data Request data
	 * @return ZS_Sync_Resource_List_Request|ZS_Sync_Request_Error Resource request object or error
	 */
	public static function from_array(array $request_data): ZS_Sync_Resource_List_Request|ZS_Sync_Request_Error {
		$request = new self();
		
		if (isset($request_data['since_version'])) {
			if (!is_array($request_data['since_version']) || 
				!isset($request_data['since_version']['time_of_last_scan']) || 
				!isset($request_data['since_version']['hash_value'])) {
				return ZS_Sync_Request_Error::create(
					ZS_Sync_Request_Error::BAD_REQUEST,
					'The since_version parameter must be an array with "time_of_last_scan" and "hash_value" keys.'
				);
			}
			
			$request->since_version = [
				'time_of_last_scan' => $request_data['since_version']['time_of_last_scan'],
				'hash_value' => $request_data['since_version']['hash_value']
			];
		}
		
		if (isset($request_data['limit'])) {
			$limit = intval($request_data['limit']);
			if ($limit <= 0) {
				return ZS_Sync_Request_Error::create(
					ZS_Sync_Request_Error::BAD_REQUEST,
					'The limit parameter must be a positive integer.'
				);
			}
			$request->limit = min($limit, 1000); // Cap at 1000
		}
		
		return $request;
	}

	/**
	 * Convert the request to a JSON string
	 * 
	 * @return string JSON representation of the request
	 */
	public function to_json_string(): string {
		$data = [];
		
		if ($this->since_version !== null) {
			$data['since_version'] = $this->since_version;
		}
		
		if ($this->limit !== 100) {
			$data['limit'] = $this->limit;
		}
		
		return json_encode($data);
	}


}
