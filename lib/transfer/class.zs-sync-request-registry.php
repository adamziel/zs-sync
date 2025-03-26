<?php

class ZS_Sync_Request_Registry {
	private array $providers = array();

	private array $provider_priorities = array();

	public function register_provider( string $resource_type, Callable $provider, int $priority = 10 ) {
		if ( isset( $this->providers[ $resource_type ] ) ) {
			$this->providers[ $resource_type ] = array();
			$this->provider_priorities[ $resource_type ] = array();
		}

		$providers  = $this->providers[ $resource_type ];
		$priorities = $this->provider_priorities[ $resource_type ];

		$existing_providers = count( $providers );
		for ( $i = 0; $i < $existing_providers; $i++ ) {
			if ( $priorities[ $i ] > $priority ) {
				array_splice( $this->providers[ $resource_type ], $i, 0, array( $provider ) );
				array_splice( $this->provider_priorities[ $resource_type ], $i, 0, array( $priority ) );
			}
		}

		if ( 0 === $i ) {
			$this->providers[ $resource_type ][] = $provider;
			$this->provider_priorities[ $resource_type ][] = $priority;
		}
	}

	/**
	 * Given a provided URI string, provide a resource which can be sent
	 * to a sync client. The value will be a string.
	 *
	 * @param string $raw_uri
	 *
	 * @return string|null
	 */
	public function provide( ZS_Sync_URI $uri ): mixed {
		if ( ! isset( $this->providers[ $uri->resource_type ] ) ) {
			return null;
		}

		/**
		 * Sentinel value for communicating inside a provider that
		 * the value should be gathered from the next-higher-priority
		 * provider in the stack.
		 *
		 * @return null
		 */
		$next = fn () => null;

		$providers = $this->providers[ $uri->resource_type ];
		foreach ( $providers as $provider ) {
			$returned_value = $provider( $uri, $next );
			if ( $next !== $returned_value ) {
				return $returned_value;
			}
		}

		return null;
	}
}
