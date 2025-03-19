<?php

class ZS_Sync_Provider_Core_File {
	public static function provide_wp_content( ZS_Sync_URI $uri, Callable $next ) {
		if ( 'path' !== $uri->id_type ) {
			return $next;
		}

		$path = realpath( $uri->id );
		$path = realpath( WP_CONTENT_DIR . DIRECTORY_SEPARATOR . $path );

		// @todo Support chunking above a certain file size.
		if ( static::is_readable_file( $path ) ) {
			return file_get_contents( $path );
		}

		return $next;
	}

	private static function is_readable_file( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		if ( ! is_file( $path ) ) {
			return false;
		}

		if ( ! is_readable( $path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns a list of all file paths in WordPress' uploads directory.
	 *
	 * @return array List of file paths
	 */
	private static function get_uploads_file_paths(): array {
		$uploads_dir = wp_upload_dir()['basedir'];
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $uploads_dir ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			$files[] = $file->getPathname();
		}

		return $files;
	}
}
