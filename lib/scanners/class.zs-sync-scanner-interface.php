<?php

interface ZS_Sync_Scanner_Interface {

	/**
	 * The default maximum number of entities to process in one chunk.
	 */
	const DEFAULT_MAX_CHUNK_SIZE = 50;

	/**
	 * Scans the next chunk of entities.
	 * Returns true if there's more data to process, false when completed.
	 */
	public function next_chunk(): bool;

	/**
	 * Returns the current cursor for this scanner.
	 */
	public function get_cursor();

}
