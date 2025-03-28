<?php
// Configuration
define('WORDPRESS_SITE_PATH', __DIR__ . '/../../wordpress-develop/src');

// No need to change these
define('WP_SITEURL', 'http://localhost');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
define('ZS_SYNC_VERSION', '1.0.0');

require WORDPRESS_SITE_PATH . '/wp-load.php';

require_once __DIR__ . '/ConsoleTable.php';
require_once __DIR__ . '/../load.php';
foreach(glob(__DIR__ . '/../schemas/*.sql') as $file) {
	$wpdb->query(str_replace( '<prefix>', $wpdb->prefix, file_get_contents($file)));
}

$directory_scanner = new ZS_Sync_Scanner_Directory(WP_CONTENT_DIR);
while ($directory_scanner->next_chunk()) {
	// ... twiddle our thumbs ...
	print_r($directory_scanner->get_cursor());
}

$directory_scanner = new ZS_Sync_Scanner_Directory_Deletions(WP_CONTENT_DIR);
while ($directory_scanner->next_chunk()) {
	// ... twiddle our thumbs ...
	print_r($directory_scanner->get_cursor());
}

$table_scanner = new ZS_Sync_Scanner_Table();
while ($table_scanner->next_chunk()) {
	// ... twiddle our thumbs ...
	print_r($table_scanner->get_cursor());
}

echo "\nScanning complete. Displaying metadata table counts:\n\n";

$tables = [
	$wpdb->prefix . 'wp_sync_metadata__bigint_key',
	$wpdb->prefix . 'wp_sync_metadata__blob_key',
	$wpdb->prefix . 'wp_sync_metadata__composite_key', 
	$wpdb->prefix . 'wp_sync_metadata__bigint_two_tuple_key',
	$wpdb->prefix . 'wp_sync_metadata__files',
];

$table = new ConsoleTable(['Table Name', 'Entry Count']);

foreach ($tables as $table_name) {
	$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
	$table->addRow([
		$table_name,
		$count
	]);
}

echo $table->render();
