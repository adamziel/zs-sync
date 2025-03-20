<?php


function wp_sync_bump_version( $resource_kind, $primary_key ): bool {
	global $wpdb;
	static $column_names = array();

	switch ( $resource_kind ) {
		case "core\x00user":
			$resource_kind = $wpdb->users;
			break;
	}

	// If a resource type isn’t known it can’t be version-bumped.
	if ( str_contains( $resource_kind, "\x00" ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			'Tried to version-bump an unknown resource by its slug rather than by table name.',
			ZS_SYNC_VERSION
		);
		return false;
	}

	if ( ! isset( $column_names[ $resource_kind ] ) ) {
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $resource_kind ) );
		if ( ! isset( $columns ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				"Tried to version-bump in table {$resource_kind} but the column information is unknown.",
				ZS_SYNC_VERSION
			);
			return false;
		}
	}

	$column_string  = '';
	$key_type       = null;
	$seen_primaries = 0;
	foreach ( $column_names[ $resource_kind ] as $i => $column ) {
		$name = str_replace( '`', '``', $column['Field'] );
		$column_string .= $i > 0 ? ", {$name}" : $name;
		if ( $column['Key'] === 'PRI' ) {
			if ( ++$seen_primaries > 1 ) {
				_doing_it_wrong(
					__FUNCTION__,
					"Tried syncing table {$resource_kind} but this has a compound primary key. Exclude it with the EXCLUDE_FILTER.",
					ZS_SYNC_VERSION
				);
				return false;
			}
			$key_type = $column['Type'];
			if ( 1 === preg_match( '~^BIGINT(\(\d+\))?$~i', $key_type ) ) {
				$key_type = 'BIGINT';
			} else {
				$key_type = null;
			}
		}
	}

	$update_query = null;
	if ( 'BIGINT' === $key_type ) {
		$update_query = <<<SQL
INSERT INTO wp_sync_metadata__bigint_key (table_name, primary_key, version_id, hash_value)
SELECT %i, %d, v.next, h.value
FROM
	(SELECT COALESCE( MAX( version_id ), 0 ) + 1 as next FROM wp_sync_metadata__bigint_key) v,
    (SELECT CRC32( JSON_ARRAY( {$column_string} ) ) as value FROM %i) h
ON DUPLICATE KEY UPDATE
    version_id = IF(h.value != hash_value, v.next, version_id),
    hash_value = h.value;
SQL;
		$update_query = $wpdb->prepare(
			$update_query,
			$resource_kind,
			$primary_key,
			$resource_kind
		);
	} else {
		throw new Error( 'Hey!' );
	}

	$result = $wpdb->query( $update_query );
	if ( 1 !== $result ) {
		error_log( $wpdb->last_error() );
		return false;
	}

	return true;
}

add_action( 'wp_update_user', function ( $user_id ) { wp_sync_bump_version( "core\x00user", $user_id ); }, 10, 1 );
