<?php

class ZS_Sync_URI {
	public string $resource_type;

	public ?string $id_type;

	public ?string $id;

	private function __construct( string $resource_type, ?string $id_type = null, ?string $id = null ) {
		$this->resource_type = $resource_type;
		$this->id_type       = $id_type;
		$this->id            = $id;
	}

	/**
	 * Create a URI object from resource data.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param string $resource_type The type of resource
	 * @param string|null $id_type The type of identifier (e.g. table name)
	 * @param string|null $id The identifier value
	 * @return ZS_Sync_URI
	 */
	public static function from_data( string $resource_type, ?string $id_type = null, ?string $id = null ): ZS_Sync_URI {
		return new ZS_Sync_URI( $resource_type, $id_type, $id );
	}

	/**
	 * Convert the URI to a string representation.
	 *
	 * @since {WP_VERSION}
	 *
	 * @return string
	 */
	public function __toString(): string {
		return implode( ':', [
			$this->resource_type,
			$this->id_type,
			$this->id,
		]);
	}

	/**
	 * Create a URI object from parsing a string.
	 *
	 * @since {WP_VERSION}
	 *
	 * @param string $uri
	 * @return ZS_Sync_URI|null
	 */
	public static function from_string( string $uri ): ?ZS_Sync_URI {
		$uri_length = strlen( $uri );

		$after_type = strpos( $uri, ':' );
		if ( false === $after_type || 0 === $after_type) {
			_doing_it_wrong( __METHOD__, 'Cannot parse URI: ' . $uri, '1.0.0' );
			return null;
		}

		$after_id_type = strpos( $uri, ':', min( $after_type + 1, $uri_length ) );
		if ( false === $after_id_type ) {
			_doing_it_wrong( __METHOD__, 'Cannot parse URI: ' . $uri, '1.0.0' );
			return null;
		}

		$type = substr( $uri, 0, $after_type );
		$id_type = $after_id_type > ( $after_type + 1 )
			? substr( $uri, $after_type + 1, $after_id_type - $after_type - 1 )
			: null;

		if (
			( $uri_length - $after_id_type > 1 && ! isset( $id_type ) ) ||
			( $uri_length - $after_id_type === 1 && isset( $id_type ) )
		) {
			_doing_it_wrong( __METHOD__, 'Cannot provide an id without indicating its type.', '1.0.0' );
			return null;
		}

		$id = isset( $id_type ) ? substr( $uri, $after_id_type + 1 ) : null;

		return new ZS_Sync_URI( $type, $id_type, $id );
	}
}
