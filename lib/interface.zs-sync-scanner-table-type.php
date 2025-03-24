<?php

interface ZS_Sync_Scanner_Table_Type {
	public function scan_next_records_chunk(): bool;
	public function get_last_pk();
	public function get_cursor(): string;
}

