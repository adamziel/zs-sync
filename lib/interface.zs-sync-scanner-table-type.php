<?php

interface ZS_Sync_Scanner_Table_Type {

	/**
	 * The default maximum number of entities to process in one chunk.
	 */
	const DEFAULT_MAX_CHUNK_SIZE = 50;

	public function scan_next_records_chunk(): bool;
	public function get_last_pk();
	public function get_cursor(): string;
}

