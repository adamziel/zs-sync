<?php

class ZS_Sync_Sorted_Directory_Visitor {

	private $stack_of_open_directories = array();
	private $current_path;
	private $root;

	public static function for_directory( string $root ) {
		if ( ! is_dir( $root ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					"The root path is not a directory: %s",
					$root
				),
				'1.0.0'
			);

			return false;
		}

		return new self( $root );
	}

	private function __construct( string $root ) {
		$this->root = rtrim( realpath( $root ), '/' );
		$this->reset();
	}

	public function next_path() {
		// Pop all directories where we've already reached the last element.
		while ( true ) {
			if(empty($this->stack_of_open_directories)) {
				// We've reached the end of the directory tree.
				return false;
			}

			[ $directory_path, $children, $current_child_index ] = end( $this->stack_of_open_directories );
			
			// We're at the last element, we're done with this directory.
			if ( $current_child_index >= count( $children ) - 1 ) {
				array_pop( $this->stack_of_open_directories );
				continue;
			}

			// We still have more elements to traverse in this directory.
			// Let's stop popping and advance the cursor.
			break;
		}

		[ $directory_path, $children, $current_child_index ] = end( $this->stack_of_open_directories );
		$next_child_index = $current_child_index + 1;
		$this->stack_of_open_directories[count($this->stack_of_open_directories) - 1][2] = $next_child_index;
		$this->current_path = "$directory_path/" . $children[$next_child_index];
			
		// If this is a directory, add it to the stack for future traversal
		if ( is_dir( $this->current_path ) ) {
			$this->push_open_directory( $this->current_path );
		}

		return true;
	}

	public function get_absolute_path() {
		return $this->current_path;
	}

	public function get_relative_path() {
		$root_with_slash = $this->root . '/';
		$root_length     = strlen( $root_with_slash );

		return substr( $this->current_path, $root_length );
	}

	public function seek_to_closest_matching_prefix( string $sought_relative_path ) {
		$this->reset();

		/**
		 * If we can't seek to the exact path, we'll look for the closest
		 * ancestor that exists. For example, if we're looking for /a/b/c
		 * and it doesn't exist, we'll try seeking to /a/b, then /a, then /.
		 */
		while ( true ) {
			$sought_absolute_path = wp_join_paths( $this->root, $sought_relative_path );
			if ( file_exists( $sought_absolute_path ) ) {
				break;
			}
			$sought_relative_path = dirname( $sought_relative_path );
			if ( ! $sought_relative_path || $sought_relative_path === '.' || $sought_relative_path === '/' ) {
				return false;
			}
		}

		$subPathSegments = wp_path_segments( $sought_relative_path );
		$root_index = $this->seek_to_entry(
			$this->stack_of_open_directories[0][1],
			$subPathSegments[0]
		);
		if ( false === $root_index ) {
			return false;
		}
		$this->stack_of_open_directories[0][2] = $root_index;

		$path_at_stack_top = $this->root;
		for ( $i = 0; $i < count( $subPathSegments ) - 1; $i ++ ) {
			$path_at_stack_top .= '/' . $subPathSegments[ $i ];
			if ( ! is_dir( $path_at_stack_top ) ) {
				break;
			}

			/**
			 * We don't need to eagerly open directory handles and seek to the
			 * desired entry, but it simplifies the traversing logic so let's
			 * do it until it becomes the bottleneck.
			 */
			$entries = $this->get_sorted_directory_entries( $path_at_stack_top );
			$next_segment = $subPathSegments[ $i + 1 ];
			$index = $this->seek_to_entry( $entries, $next_segment );
			$this->stack_of_open_directories[] = array( $path_at_stack_top, $entries, $index );
		}
		
		$this->current_path = $sought_absolute_path;
		if ( is_dir( $sought_absolute_path ) ) {
			$this->push_open_directory( $sought_absolute_path );
		}

		return true;
	}

	private function push_open_directory( $path ) {
		$entries = $this->get_sorted_directory_entries( $path );
		$this->stack_of_open_directories[] = array( $path, $entries, -1 );
	}

	private function seek_to_entry( $entries, string $sought_filename ) {
		foreach ( $entries as $index => $entry ) {
			if ( $entry === $sought_filename ) {
				return $index;
			}
		}

		return false;
	}
	private function get_sorted_directory_entries( $dir_path ) {
		$all_entries = scandir( $dir_path );

		$entries = [];
		foreach ( $all_entries as $entry ) {
			if ( $entry !== '.' && $entry !== '..' ) {
				$entries[] = $entry;
			}
		}
		
		return $entries;
	}

	public function reset() {
		$this->stack_of_open_directories = array();
		$root_entries = $this->get_sorted_directory_entries( $this->root );
		
		$this->stack_of_open_directories = array(
			array( $this->root, $root_entries, -1 ),
		);
		$this->current_path = $this->root;
	}

}
