<?php

class ZS_Sync_Table_Info {

	const PRIMARY_KEY_TYPE_BIGINT = 'bigint';
	const PRIMARY_KEY_TYPE_BLOB = 'blob';
	const PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE = 'bigint_two_tuple';
	const PRIMARY_KEY_TYPE_COMPOSITE = 'composite';

	private $fields;
	private $primary_keys = array();
	private $primary_key_type;
	private $hash_expressions;

	/**
	 * Get all tables in the database.
	 * 
	 * @return array Array of table names.
	 */
	public static function get_tables(): array {
		static $tables = null;
		
		if (null === $tables) {
			global $wpdb;
			$tables = $wpdb->get_col("SHOW TABLES");
			
			if (null === $tables) {
				// Return empty array if there was an error
				return [];
			}

			sort($tables);

			// Skip tables that should be filtered out
			$tables = array_filter($tables, function($table_name) {
				return ! apply_filters('wp_sync_should_sync_table', false, $table_name);
			});
		}
		
		return $tables;
	}

	public static function for( $table_name ): ?object {
		global $wpdb;

		static $column_info = array();

		$escaped_table_name = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $table_name );
		if ( ! isset( $escaped_table_name ) ) {
			return null;
		}

		$should_sync = apply_filters( 'wp_sync_should_sync_table', true, $table_name );
		if ( true !== $should_sync ) {
			return null;
		}

		if ( ! isset( $column_info[ $table_name ] ) ) {
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$escaped_table_name};" );
			if ( null === $columns ) {
				// @todo Check the error?
				$column_info[ $table_name ] = false;

				return null;
			}

			$fields       = array();
			$primary_keys = array();

			foreach ( $columns as $column ) {
				$name            = $column->Field;
				$fields[ $name ] = $column;
				if ( 'PRI' === $column->Key ) {
					$primary_keys[] = $name;
				}
			}

			// Skip tables without primary keys
			if ( empty( $primary_keys ) ) {
				$column_info[ $table_name ] = false;

				return null;
			} elseif ( count( $primary_keys ) === 1 ) {
				$type = ZS_Sync_Mysql_Helper::mysql_type_to_php_type( $fields[ $primary_keys[0] ]->Type );
				if ( $type === 'int' ) {
					$primary_key_type = self::PRIMARY_KEY_TYPE_BIGINT;
				} elseif ( $type === 'string' ) {
					$primary_key_type = self::PRIMARY_KEY_TYPE_BLOB;
				} else {
					// Unsupported primary key type
					_doing_it_wrong(
						__METHOD__,
						sprintf( "Unsupported primary key type: %s for table %s", $fields[ $primary_keys[0] ]->Type, $table_name ),
						ZS_SYNC_VERSION
					);

					return null;
				}
			} else {
				$primary_key_type = self::PRIMARY_KEY_TYPE_COMPOSITE;
				$is_bigint_tuple  = (
					count( $primary_keys ) === 2 &&
					'int' === ZS_Sync_Mysql_Helper::mysql_type_to_php_type( $fields[ $primary_keys[0] ]->Type ) &&
					'int' === ZS_Sync_Mysql_Helper::mysql_type_to_php_type( $fields[ $primary_keys[1] ]->Type )
				);
				if ( $is_bigint_tuple ) {
					$primary_key_type = self::PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE;
				}
			}

			// @todo Create a record class for this.
			$column_info[ $table_name ] = (object) array(
				'fields'           => $fields,
				'primary_keys'     => $primary_keys,
				'primary_key_type' => $primary_key_type,
			);
		}

		$columns = $column_info[ $table_name ];
		if ( false === $columns ) {
			return null;
		}

		return new ZS_Sync_Table_Info( $columns->fields, $columns->primary_keys, $columns->primary_key_type );
	}

	public function __construct( $fields, $primary_keys, $primary_key_type ) {
		$this->fields           = $fields;
		$this->primary_keys     = $primary_keys;
		$this->primary_key_type = $primary_key_type;
	}

	public function get_fields() {
		return $this->fields;
	}

	public function get_primary_keys() {
		return $this->primary_keys;
	}

	public function get_primary_key_type() {
		return $this->primary_key_type;
	}

	public function encode_composite_key( $values ) {
		// For bigint_two_tuple, we return an array with the two values
		if ( $this->primary_key_type === self::PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE ) {
			if ( count( $values ) !== 2 ) {
				return null;
			}

			return array(
				'head' => (int) $values[0],
				'tail' => (int) $values[1],
			);
		}

		// For general composite keys, we JSON encode the values
		if ( $this->primary_key_type === self::PRIMARY_KEY_TYPE_COMPOSITE ) {
			return json_encode( $values );
		}

		// For single key, we just return the value
		return $values[0];
	}

	public function build_row_hash_expression() {
		if ( ! $this->hash_expressions ) {
			$column_expressions = [];
			foreach ( $this->fields as $field ) {
				$escaped_field        = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $field->Field );
				$column_expressions[] = $escaped_field;
			}

			$this->hash_expressions = "CRC32(JSON_ARRAY(" . implode( ', ', $column_expressions ) . "))";
		}

		return $this->hash_expressions;
	}

	/**
	 * Get the appropriate metadata table name for this table's primary key type
	 */
	public function get_sync_metadata_table() {
		switch ( $this->primary_key_type ) {
			case self::PRIMARY_KEY_TYPE_BIGINT:
				return 'wp_sync_metadata__bigint_key';
			case self::PRIMARY_KEY_TYPE_BLOB:
				return 'wp_sync_metadata__blob_key';
			case self::PRIMARY_KEY_TYPE_BIGINT_TWO_TUPLE:
				return 'wp_sync_metadata__bigint_two_tuple_key';
			case self::PRIMARY_KEY_TYPE_COMPOSITE:
				return 'wp_sync_metadata__composite_key';
			default:
				return null;
		}
	}
}
