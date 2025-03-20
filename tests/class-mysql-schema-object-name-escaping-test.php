<?php

require_once __DIR__ . '/../lib/class-zs-sync-table-info.php';

class Mysql_Schema_Object_Name_Escaping_Test extends WP_UnitTestCase {
	/**
	 * @dataProvider data_schema_object_names_and_escapes
	 *
	 * @param string      $raw_name
	 * @param string|null $expected_name
	 * @return void
	 */
	public function escapes_appropriately( string $raw_name, ?string $expected_name ): void {
		$is_escapable = isset( $expected_name );
		$is_escaped   = $is_escapable && '`' === $expected_name[0];
		$escaped_name = ZS_Sync_Table_Info::schema_object_name_for_query( $raw_name );

		if ( $is_escapable && ! isset( $escaped_name ) ) {
			$this->assertNotNull(
				$escaped_name,
				'Should not have rejected name.'
			);
		} elseif ( $is_escaped && '`' !== $escaped_name[0] ) {
			$this->assertSame(
				$expected_name,
				$escaped_name,
				"Should have escaped name but didn't."
			);
		} elseif ( ! $is_escaped && '`' === $escaped_name[0] ) {
			$this->assertSame(
				$expected_name,
				$escaped_name,
				'Did not need to escape name but did anyway.'
			);
		} else {
			$this->assertSame(
				$expected_name,
				$escaped_name,
				"Failed to properly escape name."
			);
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public static function data_schema_object_names_and_escapes(): array {
		return array(
			'All ASCII letters'               => array( 'test', 'test' ),
			'Non-initial dollar sign'         => array( 'te$st', 'te$st' ),
			'Initial number with non-numbers' => array( '9dogs', '9dogs' ),
			'Upper BMP Character'             => array( "\u{2062}", '☂' ),

			'Non-alpha-numeric ASCII'         => array( '%', '`%`' ),
			'Grave-accent is escaped'         => array( '`', '````' ),
			'Initial dollar sign'             => array( '$test', '`$test`' ),
			'All-numeric'                     => array( '1337', '`1337`' ),

			'NUL bytes'                       => array( "wp_posts\x00wp_users", null ),
			'Supplementary Plane characters'  => array( "\u{1f170}BC", null ),
			'Non-UTF8 characters'             => array( "t\xE9st", null ),
		);
	}
}