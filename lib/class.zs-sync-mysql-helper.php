<?php

class ZS_Sync_Mysql_Helper {
	/**
	 * Since object names are limited to 64 characters (except for
	 * aliases and compound statement labels, which this code
	 * overlooks), and since Supplementary Characters are not
	 * permitted, the maximum length is 64 * 3 bytes, because
	 * all Basic Multilingual Plane characters encode within
	 * three bytes.
	 *
	 * @see https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 */
	const MAX_OBJECT_NAME_BYTES = 192;

	/**
	 * @see https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 */
	const MAX_OBJECT_NAME_CHARS = 64;

	/**
	 * Returns a version of a schema object name safe for including in
	 * a query, escaped if necessary, and `null`, if invalid.
	 *
	 * Example:
	 *
	 *     'test'    === ZS_Sync_Mysql_Helper::schema_object_name_for_query( 'test' );
	 *     'te$st'   === ZS_Sync_Mysql_Helper::schema_object_name_for_query( 'te$st' );
	 *     '9dogs'   === ZS_Sync_Mysql_Helper::schema_object_name_for_query( '9dogs' );
	 *     '☂'       === ZS_Sync_Mysql_Helper::schema_object_name_for_query( "\u{2602}" );
	 *
	 *     '`%`'     === ZS_Sync_Mysql_Helper::schema_object_name_for_query( '%' );
	 *     '````     === ZS_Sync_Mysql_Helper::schema_object_name_for_query( '`' );
	 *     '`$test`' === ZS_Sync_Mysql_Helper::schema_object_name_for_query( '$test' );
	 *     '`1337`'  === ZS_Sync_Mysql_Helper::schema_object_name_for_query( '1337' );
	 *     '`a.b`'   === ZS_Sync_Mysql_Helper::schema_object_name_for_query( 'a.b' );
	 *
	 *     // NUL bytes are not allowed.
	 *     null === ZS_Sync_Mysql_Helper::schema_object_name_for_query( "wp_posts\x00wp_users" );
	 *
	 *     // Supplementary characters are not allowed.
	 *     null === ZS_Sync_Mysql_Helper::schema_object_name_for_query( "be\u{1F170}" );
	 *
	 *     // Non-UTF8 encodings are not supported.
	 *     null === ZS_Sync_Mysql_Helper::schema_object_name_for_query( "t\xE9st" );
	 *
	 * @see https://dev.mysql.com/doc/refman/8.4/en/identifiers.html
	 *
	 * @param string $name
	 * @return string|null
	 */
	public static function schema_object_name_for_query( string $name ): ?string {
		/*
		 * > Internally, identifiers are converted to and are stored as Unicode (UTF-8).
		 *
		 * It’s not worth trying to convert encodings. If the given name isn’t
		 * already UTF-8 it isn’t supported by this plugin, even if it might
		 * be theoretically possible to convert it into UTF-8.
		 */
		if ( ! mb_check_encoding( $name, 'UTF-8' ) ) {
			return null;
		}

		$name_length = strlen( $name );
		if (
			0 === $name_length ||
			$name_length > self::MAX_OBJECT_NAME_BYTES ||
			mb_strlen( $name, 'UTF-8' ) > self::MAX_OBJECT_NAME_CHARS
		) {
			return null;
		}

		/*
		 * > Database, table, and column names cannot
		 * > end with space characters.
		 */
		if ( ' ' === $name[ $name_length - 1 ] ) {
			return null;
		}

		$has_forbidden_characters = false;
		$needs_quoting            = false;
		$has_non_digits           = false;

		/*
		 * > Use of the dollar sign as the first character in the unquoted
		 * > name of a database, table, view, column, stored program, or
		 * > alias is deprecated, including such names used with qualifiers
		 */
		$needs_quoting |= '$' === $name[0];

		/*
		 * > ASCII NUL (U+0000) and supplementary characters
		 * > (U+10000 and higher) are not permitted in
		 * > quoted or unquoted identifiers.
		 */
		for ( $i = 0; $i < $name_length; $i++ ) {
			$c = $name[ $i ];
			$o = ord( $c );

			/*
			 * > Identifiers may begin with a digit but unless
			 * > quoted may not consist solely of digits.
			 */
			$is_digit        = $c >= '0' && $c <= '9';
			$has_non_digits |= ! $is_digit;

			/*
			 * > Permitted characters in unquoted identifiers:
			 * >   - ASCII: [0-9,a-z,A-Z$_]
			 * >   - Extended: U+0080 .. U+FFFF
			 */
			$needs_quoting |= ! (
				( $c >= 'A' && $c <= 'Z' ) ||
				( $c >= 'a' && $c <= 'z' ) ||
				$is_digit || $c === '$' || $c === '_' ||
				$o >= 0x80
			);

			$has_forbidden_characters |= ( 0 === $o ) || ( ( $o & 0xF8 ) === 0xF0 );
		}

		if ( $has_forbidden_characters ) {
			return null;
		}

		if ( ! $needs_quoting && $has_non_digits ) {
			return $name;
		}

		$quoted_name = str_replace( '`', '``', $name );
		return "`{$quoted_name}`";
	}

	public static function quote_string(string $string) {
		global $wpdb;
		return '"' . mysqli_real_escape_string($wpdb->dbh, $string) . '"';
	}
}