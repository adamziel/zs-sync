<?php

class ZS_Sync_Request {
	/**
	 * When the request was received and created.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $time_of_request;

	/**
	 * Security nonce passed along with request. Time sensitive.
	 *
	 * @since {WP_VERSION}
	 *
	 * @var string
	 */
	private string $nonce;

	/**
	 * Requested resource URI, extensible via plugins.
	 *
	 * URI consists of three parts: [resource type:identifier type:identifier].
	 * The resource type is required, but the identifier and type are optional.
	 *
	 * Example:
	 *
	 *     core.post:id:148
	 *     core.post:guid:306CE89D-E00A-43D6-BAB1-D3E8AE768871
	 *     core.file.wp-content:path:/uploads/2025/01/playa.jpg
	 *     zs-sync.manifest::
	 *
	 * @since {WP_VERSION}
	 *
	 * @var ZS_Sync_URI
	 */
	private ZS_Sync_URI $uri;

	/**
	 * Constructor function.
	 *
	 * @param string $uri   URI indiciating requested resource.
	 * @param string $nonce Security nonce provided with request.
	 */
	private function __construct( string $uri, string $nonce ) {
		$this->time_of_request = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$this->uri   = $uri;
		$this->nonce = $nonce;
	}

	/**
	 * Parses a JSON-encoded request for a resource.
	 *
	 * @param string $json_request Content of a JSON-encoded request for a resource.
	 * @reeturn ZS_Sync_Request|string Parsed request, if successful, otherwise `null`.
	 */
	public static function from_json_string( string $json_request ): mixed {
		$request_data = json_decode( $json_request, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $request_data ) ) {
			return ZS_Sync_Request_Errors::BAD_REQUEST;
		}

		if ( ! isset( $request_data['version'], $request_data['nonce'], $request_data['uri'] ) ) {
			return ZS_Sync_Request_Errors::BAD_REQUEST;
		}

		if ( ZS_SYNC_VERSION !== $request_data['version'] ) {
			return ZS_Sync_Request_Errors::BAD_REQUEST;
		}

		return new static( $request_data['uri'], $request_data['nonce'] );
	}
}
