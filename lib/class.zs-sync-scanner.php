<?php

interface ZS_Sync_Scanner {

	/**
	 * Scans the next chunk of entities.
	 * Returns true if there's more data to process, false when completed.
	 */
	public function next_chunk(): bool;

	/**
	 * Returns the current cursor for this scanner.
	 */
	public function get_cursor(): array;

}
