<?php

class ZS_Sync_Continuous_Scanner implements ZS_Sync_Scanner_Interface {

	/**
	 * @var array List of scanner factories
	 */
	private array $scanner_factories;

	/**
	 * @var array List of active scanner instances
	 */
	private array $scanners;

	private array $options;
	
	/**
	 * Initialize the scanner with scanner factories and options.
	 *
	 * @param array $scanner_factories Array of callbacks that return scanner instances implementing ZS_Sync_Scanner_Interface
	 * @param array $options {
	 *     Optional. Array of scanner settings.
	 *
	 *     @type int $max_chunk_size Maximum number of items to process in one chunk. Default 50.
	 *     @type array $cursor Combined cursor state to resume from. Default empty array.
	 * }
	 */
	public function __construct(array $scanner_factories, array $options = []) {
		$this->scanner_factories = $scanner_factories;
		$this->options = $options ?? [];
		$this->scanners = [];

		// Initialize scanners using the factories
		for($i = 0; $i < count($this->scanner_factories); $i++) {
			$this->scanners[] = $this->create_scanner($i, [
				'cursor' => $options['cursor'][$i] ?? [],
			]);
		}
	}

	/**
	 * Scans the next chunk of entities across all scanners. Always returns true.
	 * If any scanner is done, it will be replaced with a fresh instance and start
	 * from the beginning.
	 */
	public function next_chunk(): bool {
		for($i = 0; $i < count($this->scanners); $i++) {
			if(false === $this->scanners[$i]->next_chunk()) {
				// If any scanner is done, replace it with a fresh instance
				// to start from the beginning.
				$this->scanners[$i] = $this->create_scanner($i, []);
			}
		}

		return true;
	}

	private function create_scanner($k, array $options): ZS_Sync_Scanner_Interface {
		$options = array_merge(
			$options ?? [],
			[
				'max_chunk_size' => $this->options['max_chunk_size'] ?? \ZS_Sync_Scanner_Interface::DEFAULT_MAX_CHUNK_SIZE,
			]
		);
		return $this->scanner_factories[$k]($options);
	}

	/**
	 * Returns a cursor for resuming the scan from the current state.
	 */
	public function get_cursor(): array {
		$combined_cursor = [];
		
		foreach ($this->scanners as $k => $scanner) {
			$combined_cursor[$k] = $scanner->get_cursor();
		}
		
		return $combined_cursor;
	}
	
	/**
	 * Get all scanners.
	 * 
	 * @return array Array of scanner instances
	 */
	public function get_scanners(): array {
		return $this->scanners;
	}
}
