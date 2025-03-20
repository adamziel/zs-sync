<?php

class ZS_Sync_Table_Info {

	private $fields;
	private $primary_key_name;
	private $hash_expressions;

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

			// Only tables with a single primary key are supported.
			if ( empty( $primary_keys ) || count( $primary_keys ) > 1 ) {
				$column_info[ $table_name ] = false;
				return null;
			}

			// @todo Create a record class for this.
			$column_info[ $table_name ] = (object) array(
				'fields'           => $fields,
				'primary_key'      => $primary_keys[0],
			);
		}

		$columns = $column_info[ $table_name ];
		if ( false === $columns ) {
			return null;
		}

		// @todo this class should have something like ->	( $primary_key ).
		return new ZS_Sync_Table_Info( $columns->fields, $columns->primary_key );
	}

	public function __construct( $fields, $primary_key_name ) {
		$this->fields = $fields;
		$this->primary_key_name = $primary_key_name;
	}

	public function get_fields() {
		return $this->fields;
	}

	public function get_primary_key_name() {
		return $this->primary_key_name;
	}

	public function get_primary_key_php_type() {
		$type = strtolower($this->fields[$this->primary_key_name]->Type);
		if(str_contains($type, '(')) {
			$type = substr($type, 0, strpos($type, '('));
		}
		if(str_contains($type, ' ')) {
			$type = substr($type, 0, strpos($type, ' '));
		}
		switch($type) {
			case 'char':
			case 'varchar':
			case 'text':
				return 'string';
			case 'int':
			case 'bigint':
			case 'mediumint':
				return 'int';
			case 'float':
			case 'double':
				return 'float';
			default:
				return 'unknown (' . $this->fields[$this->primary_key_name]->Type . ')';
		}
	}

	public function build_row_hash_expression() {
		if(!$this->hash_expressions) {
			$column_expressions = [];
			foreach ( $this->fields as $field ) {
				$escaped_field = ZS_Sync_Mysql_Helper::schema_object_name_for_query( $field->Field );
				$column_expressions[] = $escaped_field;
			}

			$this->hash_expressions = "CRC32(JSON_ARRAY(" . implode(', ', $column_expressions) . "))";
		}

		return $this->hash_expressions;
	}
}
