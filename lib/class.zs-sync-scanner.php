<?php

use Pleo\BloomFilter\BloomFilter;

class ZS_Sync_Scanner implements ZS_Sync_Scanner_Interface {

	/**
	 * @var array List of scanner factories
	 */
	private array $scanner_factories;

	/**
	 * @var array List of active scanner instances
	 */
	private array $scanners;
	
	/**
	 * @var array Combined cursor state from all scanners
	 */
	private array $cursor;
	
	/**
	 * @var object Bloom filter instance
	 */
	private $indexed_pks_bloom_filter;

	/**
	 * Initialize the scanner with scanner factories and options.
	 *
	 * @param array $scanner_factories Array of callbacks that return scanner instances implementing ZS_Sync_Scanner_Interface
	 * @param array $options {
	 *     Optional. Array of scanner settings.
	 *
	 *     @type int $max_chunk_size Maximum number of items to process in one chunk. Default 50.
	 *     @type array $cursor Combined cursor state to resume from. Default empty array.
	 *     @type int $indexed_pks_bloom_filter_size Size of the bloom filter in bits. Default 10000.
	 * }
	 */
	public function __construct(array $scanner_factories, array $options = []) {
		$this->scanner_factories = $scanner_factories;
		$this->cursor = $options['cursor'] ? json_decode($options['cursor'], true) : [];
		$this->scanners = [];
		
		// Initialize scanners using the factories
		foreach ($this->scanner_factories as $factory) {
			$this->scanners[] = $factory($this->cursor['scanners'][$factory] ?? []);
		}
		
		// Initialize the bloom filter for detecting deleted items
		if(!empty($this->cursor['indexed_pks_bloom_filter'])) {
			$this->indexed_pks_bloom_filter = BloomFilter::initFromJson($this->cursor['indexed_pks_bloom_filter']);
		} else {
			// We need to restart the bloom filter at most once every 5000 scanned items to
			// preserve the desired false positive probability.
			$approximate_item_count = 5_000;
			$false_positive_probability = 1 / 5_000_000;
			/**
			 * We'll need around 20KB for the bloom filter's bit array:
			 * 
			 * > $this->indexed_pks_bloom_filter->get_bit_array_byte_length()
			 * 20066
			 * 
			 * The serialized base64 representation will be around 25 - 30KB. A regular BLOB
			 * column should be more than enough to store it.
			 */
			$this->indexed_pks_bloom_filter = BloomFilter::init(
				$approximate_item_count,
				$false_positive_probability
			);
		}
	}

	/**
	 * Scans the next chunk of entities across all scanners. Always returns true.
	 * If any scanner is done, it will be replaced with a fresh instance and start
	 * from the beginning.
	 */
	public function next_chunk(): bool {
		foreach($this->scanners as $k => $scanner) {
			if(false === $scanner->next_chunk()) {
				// If the scanner is done, replace it with a fresh instance
				// to start from the beginning.
				$factory = $scanner->get_factory();
				$this->scanners[$k] = $factory();
			}
		}

		return true;
	}

	/**
	 * Returns a cursor for resuming the scan from the current state.
	 */
	public function get_cursor(): string {
		$combined_cursor = [
			'scanners' => [],
			'bloom_filter' => $this->indexed_pks_bloom_filter->jsonSerialize(),
		];
		
		foreach ($this->scanners as $scanner) {
			$scanner_class = get_class($scanner);
			$combined_cursor['scanners'][$scanner_class] = $scanner->get_cursor();
		}
		
		return json_encode($combined_cursor);
	}
	
	/**
	 * Get the bloom filter instance.
	 * 
	 * @return object The bloom filter instance
	 */
	public function get_indexed_pks_bloom_filter() {
		return $this->indexed_pks_bloom_filter;
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
