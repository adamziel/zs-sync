<?php

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

		foreach ( $file_resources as $resource ) {
			// Validate required fields
			if ( empty( $resource['uri'] ) || !array_key_exists('filesize', $resource) ) {
				$results[$resource['uri']] = [
					'success' => false,
					'error' => 'Missing required metadata (uri or filesize)',
				];
				continue;
			}

			$uri = $resource['uri'];
			$filesize = (int) $resource['filesize'];
			
			// Get file_path from uri
			$file_path = $this->get_file_path_from_uri($uri);
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

			// Create a file-specific temp directory
			$file_temp_dir = $this->temp_dir . '/' . md5( $uri );
			if ( ! file_exists( $file_temp_dir ) ) {
				mkdir( $file_temp_dir, 0755, true );
			}

			// Calculate how many chunks are needed
			$total_chunks = ceil( $filesize / $this->chunk_size );
			
			// Track missing chunks with their start and length
			$missing_chunks = [];
			$downloaded_size = 0;
			
			// Check for existing chunks
			for ( $i = 0; $i < $total_chunks; $i++ ) {
				$start = $i * $this->chunk_size;
				$length = min( $this->chunk_size, $filesize - $start );
				$chunk_file = $file_temp_dir . '/' . basename( $file_path ) . '.chunk.' . $start . '_' . $length;
				
				if ( file_exists( $chunk_file ) ) {
					$chunk_size = filesize( $chunk_file );
					$downloaded_size += $chunk_size;
					
					// If chunk is incomplete, mark it for re-download
					if ( $chunk_size !== $length ) {
						$missing_chunks[] = [
							'index' => $i,
							'start' => $start,
							'length' => $length
						];
					}
				} else {
					$missing_chunks[] = [
						'index' => $i,
						'start' => $start,
						'length' => $length
					];
				}
			}
			
			// If we have all chunks with the correct sizes, we can skip downloading
			if ( empty( $missing_chunks ) && $downloaded_size === $filesize ) {
				// Assemble the file
				$result = $this->assemble_file( $file_temp_dir, $file_path, $total_chunks );
				$results[$uri] = $result;
				continue;
			}
			
			// Download missing chunks
			$download_success = true;
			foreach ( $missing_chunks as $chunk_info ) {
				$chunk_index = $chunk_info['index'];
				$start = $chunk_info['start'];
				$length = $chunk_info['length'];
				
				// Create the request for this chunk
				$fetch_request = ZS_Sync_Resource_Fetch_Request::from_array([
					'resources' => [
						[
							'uri' => $uri,
							'range' => [
								'start' => $start,
								'length' => $length
							]
						]
					]
				]);
				
				// Fetch the chunk
				$response = $this->client->get_resources( $fetch_request );
				
				// Check for errors
				if ( $response instanceof ZS_Sync_Response_Error ) {
					$results[$uri] = [
						'success' => false,
						'error' => 'Failed to download chunk ' . $chunk_index . ': ' . $response->message,
					];
					$download_success = false;
					break;
				}
				
				// Extract the chunk data from the CBOR response
				$resource_data = $this->extract_file_chunk_from_cbor( $response, $uri );
				
				if ( $resource_data === null || empty( $resource_data ) ) {
					$results[$uri] = [
						'success' => false,
						'error' => 'Empty or missing data for chunk ' . $chunk_index,
					];
					$download_success = false;
					break;
				}
				
				// Save the chunk to a temporary file with start and length encoded in the filename
				$chunk_file = $file_temp_dir . '/' . basename( $file_path ) . '.chunk.' . $start . '_' . $length;
				$bytes_written = file_put_contents( $chunk_file, $resource_data );
				
				if ( $bytes_written === false || $bytes_written !== strlen( $resource_data ) ) {
					$results[$uri] = [
						'success' => false,
						'error' => 'Failed to write chunk ' . $chunk_index . ' to temporary file',
					];
					$download_success = false;
					break;
				}
			}
			
			// If download failed for any chunk, continue to the next file
			if ( ! $download_success ) {
				continue;
			}
			
			// All chunks downloaded successfully, assemble the final file
			$result = $this->assemble_file( $file_temp_dir, $file_path, $total_chunks );
			$results[$uri] = $result;
			
			// Clean up temp files after successful assembly
			if ($result['success']) {
				$this->cleanup_temp_files($uri);
			}
		}
		
		return $results;
	}
	
	/**
	 * Extracts the file path from a URI
	 *
	 * @param string $uri The URI of the file resource
	 * @return string The file path
	 */
	private function get_file_path_from_uri($uri) {
		$zs_uri = ZS_Sync_URI::from_string($uri);
		if ($zs_uri && $zs_uri->resource_type === 'files') {
			return $zs_uri->id;
		}
		return '';
	}
	
	/**
	 * Assembles chunks into a final file
	 *
	 * @param string $file_temp_dir The temporary directory containing chunks
	 * @param string $file_path The relative path of the file being downloaded
	 * @return array Result with success/failure status
	 */
	private function assemble_file( $file_temp_dir, $file_path ) {
		// Determine the target path based on configuration
		$target_file_path = wp_join_paths($this->target_dir, $file_path);
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
		$filename = basename( $file_path );
		
		// Get all chunk files and sort them by start position
		$chunk_files = glob( $file_temp_dir . '/' . $filename . '.chunk.*' );
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
	 * Extracts a file chunk from a CBOR response
	 * 
	 * @TODO: Don't use reflections here.
	 *
	 * @param CBOR\MapObject $cbor_response The CBOR response from the server
	 * @param string $uri The URI of the resource to extract
	 * @return string|null The file chunk data or null if not found
	 */
	private function extract_file_chunk_from_cbor( CBOR\MapObject $cbor_response, $uri ) {
		if(!$cbor_response->has($uri)) {
			return null;
		}
		$value_obj = $cbor_response->get($uri);
		if($value_obj instanceof CBOR\OtherObject\NullObject) {
			return null;
		}
		return $value_obj->getValue();
	}
	
	/**
	 * Cleans up temporary files for a given file resource
	 *
	 * @param string $uri The URI of the file resource
	 * @return bool Success or failure
	 */
	public function cleanup_temp_files( $uri ) {
		$file_temp_dir = $this->temp_dir . '/' . md5( $uri );
		
		if ( ! file_exists( $file_temp_dir ) ) {
			return true; // Nothing to clean up
		}
		
		$files = scandir( $file_temp_dir );
		
		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}
			$path = wp_join_paths( $file_temp_dir, $file );
			if ( is_file( $path ) && ! unlink( $path ) ) {
				return false;
			}
		}
		
		return rmdir( $file_temp_dir );
	}
	
}
