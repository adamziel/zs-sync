<?php

/**
 * Class ZS_Sync_Resource_Fetch_Request
 * 
 * Represents a request for one or more resources from the sync server.
 */
class ZS_Sync_Resource_Fetch_Request {
	/**
	 * Array of resources requested.
	 *
	 * Each element can be either a string URI or an array with URI and additional parameters.
	 *
	 * Example:
	 * [
	 *   'files:path:/uploads/2025/01/image.jpg',
	 *   [
	 *     'uri' => 'core.post:id:148',
	 *     'range' => ['start' => 0, 'length' => 1024]
	 *   ],
	 *   'core.post:guid:306CE89D-E00A-43D6-BAB1-D3E8AE768871',
	 *   'core.file.wp-content:path:/uploads/2025/01/playa.jpg',
	 *   'zs-sync.manifest::'
	 * ]
	 *
	 * @since {WP_VERSION}
	 *
	 * @var ZS_Sync_Resource_Query[]
	 */
	public $resources = [];

	/**
	 * Create a request from raw HTTP request data
	 * 
	 * @param string $json_request JSON string from HTTP request
	 * @return ZS_Sync_Resource_Fetch_Request|int Resource request object or error code
	 */
	public static function from_json_string(string $json_request): ZS_Sync_Resource_Fetch_Request|ZS_Sync_Request_Error {
		$request_data = json_decode($json_request, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
		if (JSON_ERROR_NONE !== json_last_error()) {
			return ZS_Sync_Request_Error::create(
				ZS_Sync_Request_Error::BAD_REQUEST,
				'Request data is not valid JSON: ' . json_last_error_msg()
			);
		}

		if (!isset($request_data['resources']) || !is_array($request_data['resources'])) {
			return ZS_Sync_Request_Error::create(
				ZS_Sync_Request_Error::BAD_REQUEST,
				'The request must contain a "resources" key and it must be an array.'
			);
		}
		
		return self::from_array($request_data);
	}

	public static function from_array(array $request_data): ZS_Sync_Resource_Fetch_Request|ZS_Sync_Request_Error {
		$parsed_queries = [];
		foreach($request_data['resources'] as $query) {
			if(is_string($query)) {
				$parsed_queries[] = new ZS_Sync_Resource_Query([
					'uri' => $query
				]);
			} else if(is_array($query)) {
				$parsed_queries[] = new ZS_Sync_Resource_Query($query);
			} else {
				return ZS_Sync_Request_Error::create(
					ZS_Sync_Request_Error::BAD_REQUEST,
					'Invalid resource query – only strings and arrays are supported: ' . json_encode($query)
				);
			}
		}
		
		return new self($parsed_queries);
	}

	/**
	 * Constructor
	 * 
	 * @param array $resources Array of resource queries or raw query data
	 * @param array $options   Additional request options
	 */
	private function __construct(array $resource_queries = []) {
		$this->resources = $resource_queries;
	}

}
