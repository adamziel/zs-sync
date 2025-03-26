<?php
/**
 * Register a wp-cron job for ZS Sync scanning.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define default constant if not already defined
if ( ! defined( 'ZS_SYNC_CRON_SCAN' ) ) {
	define( 'ZS_SYNC_CRON_SCAN', true );
}

// Hook for activation
register_activation_hook( __FILE__, 'zs_sync_schedule_scanning_event' );

// Hook for deactivation
register_deactivation_hook( __FILE__, 'zs_sync_unschedule_scanning_event' );

/**
 * Schedule the scanning cron job.
 */
function zs_sync_schedule_scanning_event() {
	// Only schedule if cron scanning is enabled
	if ( ! defined( 'ZS_SYNC_CRON_SCAN' ) || ! ZS_SYNC_CRON_SCAN ) {
		return;
	}
	
	if ( ! wp_next_scheduled( 'zs_sync_scanning_cron_hook' ) ) {
		wp_schedule_event( time(), 'hourly', 'zs_sync_scanning_cron_hook' );
	}
}

/**
 * Unschedule the scanning cron job.
 */
function zs_sync_unschedule_scanning_event() {
	$timestamp = wp_next_scheduled( 'zs_sync_scanning_cron_hook' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'zs_sync_scanning_cron_hook' );
	}
}

/**
 * Perform the scanning operation.
 */
function zs_sync_perform_scanning() {
	// Only run if cron scanning is enabled
	if ( ! defined( 'ZS_SYNC_CRON_SCAN' ) || ! ZS_SYNC_CRON_SCAN ) {
		return;
	}
	
	$chunk_size = get_option( 'zs_sync_scanning_chunk_size', 10 ); // Default: 10 items
	
	// Get all registered scanners
	$scanners = apply_filters( 'zs_sync_registered_scanners', array() );
	
	if ( empty( $scanners ) ) {
		// Default scanners if none registered
		if ( class_exists( 'ZS_Sync_Continuous_Scanner' ) ) {
			$scanners[] = new ZS_Sync_Continuous_Scanner(
				array( 'max_chunk_size' => $chunk_size )
			);
		} else {
			// Fallback to individual scanners if continuous scanner isn't available
			if ( class_exists( 'ZS_Sync_Scanner_Directory' ) ) {
				$root_path = WP_CONTENT_DIR;
				$scanners[] = new ZS_Sync_Scanner_Directory(
					$root_path,
					array( 'max_chunk_size' => $chunk_size )
				);
			}
			
			if ( class_exists( 'ZS_Sync_Scanner_Table' ) ) {
				$scanners[] = new ZS_Sync_Scanner_Table(
					array( 'max_chunk_size' => $chunk_size )
				);
			}
		}
	}
	
	// Process each scanner
	foreach ( $scanners as $scanner ) {
		if ( $scanner instanceof ZS_Sync_Scanner ) {
			$scanner->next_chunk();
		}
	}
	
	// Check if we should run again immediately
	$run_again = apply_filters( 'zs_sync_should_run_again', false );
	
	if ( $run_again ) {
		// Schedule a one-time event to run immediately
		wp_schedule_single_event( time(), 'zs_sync_scanning_cron_hook' );
	}
}
add_action( 'zs_sync_scanning_cron_hook', 'zs_sync_perform_scanning' );

/**
 * Register settings for the scanner.
 */
function zs_sync_register_scanner_settings() {
	register_setting( 'zs_sync_options', 'zs_sync_scanning_chunk_size', array(
		'type'              => 'integer',
		'description'       => 'Number of items to scan in each cron job execution',
		'sanitize_callback' => 'absint',
		'default'           => 10,
	));
}
add_action( 'admin_init', 'zs_sync_register_scanner_settings' );
