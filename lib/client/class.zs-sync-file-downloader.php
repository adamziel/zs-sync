<?php

use CBOR\OtherObject\NullObject;

/**
 * ZS_Sync_File_Downloader
 * 
 * Downloads large files in chunks using the ZS_Sync_Client interface.
 * Each chunk is saved with a .chunk.$start_$length prefix. The final file is assembled
 * when all chunks are successfully downloaded.
 * 
 * @since {WP_VERSION}
 */
class ZS_Sync_File_Downloader {
	/**
	 * @var ZS_Sync_Client The client to use for retrieving resources
	 */
	private $client;

	/**
	 * @var string The temporary directory for storing chunks
	 */
	private $temp_dir;

	/**
	 * @var int The size of each chunk in bytes
	 */
	private $chunk_size;

	/**
	 * @var string The target directory for storing assembled files
	 */
	private $target_dir;

	/**
	 * @var int The maximum size of a download in bytes
	 */
	private $max_download_size;

	/**
	 * Constructor
	 *
	 * @param ZS_Sync_Client $client The client to use for communication
	 * @param array $options Optional configuration settings
	 *     @type string $temp_dir The directory to use for temporary chunk storage
	 *     @type string $target_dir The directory to store assembled files
	 *     @type int $chunk_size The size of each chunk in bytes (default: 1MB)
	 */
	public function __construct( ZS_Sync_Client $client, array $options = [] ) {
		if ( ! isset( $options['target_dir'] ) ) {
			throw new InvalidArgumentException( 'target_dir is required' );
		}
		
		$this->client = $client;
		$this->target_dir = $options['target_dir'];
		$this->temp_dir = isset( $options['temp_dir'] ) ? $options['temp_dir'] : sys_get_temp_dir() . '/zs-sync-downloads';
		$this->chunk_size = isset( $options['chunk_size'] ) ? $options['chunk_size'] : 1024 * 1024; // 1MB default
		$this->max_download_size = isset( $options['max_download_size'] ) ? $options['max_download_size'] : 1024 * 1024 * 10; // 10MB default

		// Create temporary directory if it doesn't exist
		if ( ! file_exists( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0755, true );
		}
	}

	/**
	 * Fetch files from the remote server
	 *
	 * @param array $file_resources Array of file resources with their metadata
	 *                             Each resource should have 'type', 'uri', 'file_path', 'filesize', 
	 *                             'time_of_last_scan', and 'hash_value' keys
	 * @return array Results array with success/failure status for each file
	 */
	public function fetch_files( array $file_resources ) {
		$results = [];

		$remaining_resources = [];
		foreach($file_resources as $resource) {
			// Validate required fields
			if ( empty( $resource['uri'] ) || !array_key_exists('filesize', $resource) ) {
				$results[$resource['uri']] = [
					'success' => false,
					'error' => 'Missing required metadata (uri or filesize)',
				];
				continue;
			}

			$uri = $resource['uri'];
			$file_path = $this->uri_to_relative_path($uri);
			if (empty($file_path)) {
				$results[$uri] = [
					'success' => false,
					'error' => 'Could not determine file path from URI',
				];
				continue;
			}

			// Skip the download if the file already exists and is unchanged
			$target_file_path = wp_join_paths($this->target_dir, $file_path);
			if ( file_exists( $target_file_path ) ) {
				if (isset($resource['hash_value']) && function_exists('hash_file')) {
					$file_hash = hexdec( hash_file( 'crc32', $target_file_path ) );
					
					// If the hash matches, we can skip this file
					if ($file_hash == $resource['hash_value']) {
						$results[$uri] = [
							'success' => true,
							'path' => $target_file_path,
							'status' => 'unchanged',
						];
						continue;
					}
				}
			}
			
			$remaining_resources[$uri] = $resource;
		}

		while(count($remaining_resources) > 0) {
			$next_download_request = [];
			$total_requested_bytes = 0;

			foreach($remaining_resources as $uri => $resource) {
				$filesize = (int) $resource['filesize'];
				
				// Create a file-specific temp directory
				$total_chunks = ceil( $filesize / $this->chunk_size );
				$missing_chunks = $this->compute_missing_chunks($uri, $filesize);
				if ( empty( $missing_chunks ) ) {
					$results[$uri] = $this->assemble_file( $uri, $total_chunks );
					$this->cleanup_temp_files($uri);
					unset($remaining_resources[$uri]);
					continue;
				}
				
				// Download missing chunks
				foreach ( $missing_chunks as $chunk_info ) {
					$next_download_request[] = [
						'uri' => $uri,
						'range' => [
							'start' => $chunk_info['start'],
							'length' => $chunk_info['length']
						]
					];
					$total_requested_bytes += $chunk_info['length'];
					if($total_requested_bytes + $this->chunk_size > $this->max_download_size) {
						break;
					}
				}
			}

			if(count($next_download_request) === 0) {
				break;
			}

			$fetch_request = ZS_Sync_Resource_Fetch_Request::from_array([
				'resources' => $next_download_request
			]);
			
			$cbor_response = $this->client->get_resources( $fetch_request );
			// Check for errors
			if ( $cbor_response instanceof ZS_Sync_Response_Error ) {
				$results[$uri] = [
					'success' => false,
					'error' => 'Failed to download chunks: ' . json_encode($next_download_request),
				];
				$this->cleanup_temp_files($uri);
				unset($remaining_resources[$uri]);
				break;
			}

			foreach($cbor_response->getIterator() as $entry) {
				$uri = $entry->getKey()->getValue();
				$resource_data = $entry->getValue();
				if($resource_data instanceof NullObject) {
					$results[$uri] = [
						'success' => false,
						'error' => 'File no longer exists: ' . $uri,
					];
					$this->cleanup_temp_files($uri);
					unset($remaining_resources[$uri]);
					break;
				}
				$start = $resource_data->get('start')->getValue();
				$file_chunk = $resource_data->get('chunk')->getValue();
					
				// Save the chunk to a temporary file with start and length encoded in the filename
				$chunks_dir = $this->get_temporary_dir_to_buffer_chunks($uri);
				$file_path = $this->uri_to_relative_path($uri);
				$chunk_file = wp_join_paths($chunks_dir, basename($file_path) . '.chunk.' . $start . '_' . $this->chunk_size);
				$bytes_written = file_put_contents( $chunk_file, $file_chunk );
				
				if ( $bytes_written === false || $bytes_written !== strlen( $file_chunk ) ) {
					$results[$uri] = [
						'success' => false,
						'error' => 'Failed to write chunk ' . $start . ' of ' . $uri . ' to a temporary file',
					];
					$this->cleanup_temp_files($uri);
					unset($remaining_resources[$uri]);
				}
			}
		}
		
		return $results;
	}

	/**
	 * Computes which chunks are still missing for a given resource
	 *
	 * @param string $uri The URI of the file resource
	 * @param int $filesize The total size of the file
	 * @param int $chunk_size The size of each chunk
	 * @return array An array containing 'missing_chunks' and 'expected_download_size'
	 */
	private function compute_missing_chunks($uri, $filesize) {
		$file_path = $this->uri_to_relative_path($uri);
		$chunks_dir = $this->get_temporary_dir_to_buffer_chunks($uri);
		$total_chunks = ceil($filesize / $this->chunk_size);
		$missing_chunks = [];
		
		// Check which chunks already exist
		for ($chunk_index = 0; $chunk_index < $total_chunks; $chunk_index++) {
			$start = $chunk_index * $this->chunk_size;
			$chunk_file = wp_join_paths($chunks_dir, basename($file_path) . '.chunk.' . $start . '_' . $this->chunk_size);
			
			// @TODO integrity checks
			if (!file_exists($chunk_file)) {
				$missing_chunks[] = [
					'index' => $chunk_index,
					'start' => $start,
					'length' => $this->chunk_size
				];
			}
			
		}
		
		return $missing_chunks;
	}
	
	/**
	 * Gets the temporary directory for a file
	 *
	 * @param string $uri The URI of the file resource
	 * @return string The path to the temporary directory
	 */
	private function get_temporary_dir_to_buffer_chunks($uri, $create_if_missing = true) {
		$file_path = $this->uri_to_relative_path($uri);
		$chunks_dir = wp_join_paths($this->temp_dir, md5($file_path));
		
		// Create the temp directory if it doesn't exist
		if (!file_exists($chunks_dir) && $create_if_missing) {
			mkdir($chunks_dir, 0755, true);
		}
		
		return $chunks_dir;
	}
	
	
	/**
	 * Extracts the file path from a URI
	 *
	 * @param string $uri The URI of the file resource
	 * @return string The file path
	 */
	private function uri_to_relative_path($uri) {
		$zs_uri = ZS_Sync_URI::from_string($uri);
		if ($zs_uri && $zs_uri->resource_type === 'files') {
			return $zs_uri->id;
		}
		return '';
	}
	
	/**
	 * Assembles chunks into a final file
	 *
	 * @param string $chunks_dir The temporary directory containing chunks
	 * @return array Result with success/failure status
	 */
	private function assemble_file( $uri ) {
		$relative_path = $this->uri_to_relative_path($uri);
		$chunks_dir = $this->get_temporary_dir_to_buffer_chunks($uri);
		$target_file_path = wp_join_paths($this->target_dir, $relative_path);
		$target_dir = dirname($target_file_path);
		
		// Create the target directory if it doesn't exist
		if ( ! file_exists( $target_dir ) ) {
			if ( ! mkdir( $target_dir, 0755, true ) ) {
				return [
					'success' => false,
					'error' => 'Failed to create target directory: ' . $target_dir,
				];
			}
		}
		
		// Truncate the file to 0 bytes before writing to it
		file_put_contents($target_file_path, '');
		
		$output_file = fopen( $target_file_path, 'wb' );
		if ( ! $output_file ) {
			return [
				'success' => false,
				'error' => 'Failed to open output file for writing: ' . $target_file_path,
			];
		}
		
		// Append each chunk to the output file
		$bytes_written = 0;
		$filename = basename( $relative_path );
		
		// Get all chunk files and sort them by start position
		$chunk_files = glob( $chunks_dir . '/' . $filename . '.chunk.*' );
		$chunks = [];
		
		foreach ( $chunk_files as $chunk_file ) {
			$chunk_info = explode( '.chunk.', $chunk_file );
			if ( count( $chunk_info ) !== 2 ) {
				continue;
			}
			
			$position_info = explode( '_', $chunk_info[1] );
			if ( count( $position_info ) !== 2 ) {
				continue;
			}
			
			$start = (int) $position_info[0];
			$chunks[$start] = $chunk_file;
		}
		
		// Sort chunks by start position
		ksort( $chunks );
		
		// Write chunks in order
		foreach ( $chunks as $start => $chunk_file ) {
			if ( ! file_exists( $chunk_file ) ) {
				fclose( $output_file );
				return [
					'success' => false,
					'error' => 'Missing chunk file during assembly: ' . $chunk_file,
				];
			}
			
			$chunk_data = file_get_contents( $chunk_file );
			if ( $chunk_data === false ) {
				fclose( $output_file );
				return [
					'success' => false,
					'error' => 'Failed to read chunk file: ' . $chunk_file,
				];
			}
			
			$bytes = fwrite( $output_file, $chunk_data );
			if ( $bytes === false || $bytes !== strlen( $chunk_data ) ) {
				fclose( $output_file );
				return [
					'success' => false,
					'error' => 'Failed to write chunk data to output file',
				];
			}
			
			$bytes_written += $bytes;
		}
		
		fclose( $output_file );
		
		return [
			'success' => true,
			'path' => $target_file_path,
			'bytes_written' => $bytes_written,
		];
	}

	/**
	 * Cleans up temporary files for a given file resource
	 *
	 * @param string $uri The URI of the file resource
	 * @return bool Success or failure
	 */
	public function cleanup_temp_files( $uri ) {
		$chunks_dir = $this->get_temporary_dir_to_buffer_chunks($uri, false);
		
		if ( ! file_exists( $chunks_dir ) ) {
			return true; // Nothing to clean up
		}
		
		$files = scandir( $chunks_dir );
		
		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}
			$path = wp_join_paths( $chunks_dir, $file );
			if ( is_file( $path ) && ! unlink( $path ) ) {
				return false;
			}
		}
		
		return rmdir( $chunks_dir );
	}

}
