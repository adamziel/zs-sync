<?php

use CBOR\MapObject;
use CBOR\ByteStringObject;
use CBOR\ListObject;
use CBOR\UnsignedIntegerObject;
use CBOR\OtherObject\TrueObject;
use CBOR\OtherObject\FalseObject;
use CBOR\OtherObject\NullObject;
use CBOR\Tag\DecimalFractionTag;
use CBOR\Tag\NegativeBigIntegerTag;
use CBOR\Tag\TimestampTag;
use CBOR\Tag\UnsignedBigIntegerTag;

class ZS_Sync_CBOR_Resource_Encoder {

	private $map;

	public function __construct() {
		$this->map = MapObject::create();
	}

	public function add_byte_string( $uri, $string ) {
		$this->map->add(ByteStringObject::create($uri), ByteStringObject::create($string));
	}

	public function add_null( $uri ) {
		$this->map->add(ByteStringObject::create($uri), NullObject::create());
	}

	public function add_database_row( $uri, $row, ZS_Sync_Table_Info $table_info ) {
		$list = new ListObject();
		$fields = $table_info->get_fields();
		
		foreach ( $fields as $field ) {
			$field_name = $field->Field;
			$val = $row->{$field_name};
			$type = $field->Type;
			
			if ($val !== null) {
				if (stripos($type, 'DECIMAL') !== false) {
					// Convert decimal value to CBOR decimal fraction
					$valStr = (string)$val;
					$parts = explode('.', $valStr);
					$scale = isset($parts[1]) ? strlen($parts[1]) : 0;
					$mantissa = intval(str_replace('.', '', $valStr));
					
					$list->add(DecimalFractionTag::createFromExponentAndMantissa(
						UnsignedIntegerObject::create($scale * -1),
						UnsignedIntegerObject::create($mantissa)
					));
				} elseif (stripos($type, 'DATE') !== false || stripos($type, 'TIME') !== false) {
					$list->add(ByteStringObject::create($val));
				} else {
					// For other values, convert based on PHP type
					if (is_int($val) || ctype_digit($val)) {
						if($val >= 0) {
							$list->add(UnsignedBigIntegerTag::create(ByteStringObject::create((string)$val)));
						} else {
							$list->add(NegativeBigIntegerTag::create(ByteStringObject::create((string)$val)));
						}
					} elseif (is_string($val)) {
						$list->add(ByteStringObject::create($val));
					} elseif (is_bool($val)) {
						$list->add($val ? TrueObject::create() : FalseObject::create());
					} else {
						// Default fallback
						$list->add(ByteStringObject::create((string)$val));
					}
				}
			} else {
				$list->add(NullObject::create());
			}
		}

		$this->map->add(ByteStringObject::create($uri), $list);
	}

	public function add_file_chunk( $uri, ?string $file_chunk ) {
		$this->map->add(ByteStringObject::create($uri), null === $file_chunk ? NullObject::create() : ByteStringObject::create($file_chunk));
	}

	public function get_cbor_map() {
		return $this->map;
	}

}

