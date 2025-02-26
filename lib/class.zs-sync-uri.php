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
			return null;
		}

		$after_id_type = strpos( $uri, ':', min( $after_type + 1, $uri_length ) );
		if ( false === $after_id_type ) {
			return null;
		}

		$type = substr( $uri, 0, $after_type );
		$id_type = $after_id_type > ( $after_type + 1 )
			? substr( $uri, $after_type + 1, $after_id_type - $after_type - 1 )
			: null;

		// Cannot provide an id without indicating its type.
		if (
			( $uri_length - $after_id_type > 1 && ! isset( $id_type ) ) ||
			( $uri_length - $after_id_type === 1 && isset( $id_type ) )
		) {
			return null;
		}

		$id = isset( $id_type ) ? substr( $uri, $after_id_type + 1 ) : null;

		return new ZS_Sync_URI( $type, $id_type, $id );
	}
}
