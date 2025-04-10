<?php

namespace Automattic\Syndication\Includes;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Application_Passwords;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class REST_API_Endpoints
 *
 * Handles the REST API endpoints for the site connection process.
 */
class REST_API_Endpoints {

	/**
	 * The REST API namespace.
	 *
	 * @var string
	 */
	private $namespace = 'zs-sync/v1';

	/**
	 * The option name to store connections.
	 */
	private const CONNECTIONS_OPTION_NAME = 'zs_sync_connections';

	/**
	 * The transient prefix for authorization codes.
	 */
	private const AUTH_CODE_TRANSIENT_PREFIX = 'zs_sync_auth_code_';

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register custom REST API routes.
	 */
	public function register_routes() {
		// Endpoint for initial authorization
		register_rest_route(
			$this->namespace,
			'/authorize',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle_authorization' ],
				'permission_callback' => '__return_true', // No auth required for initial request
				'args'                => [
					'request_token' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return is_string( $param ) && ! empty( $param ); },
						'sanitize_callback' => 'sanitize_text_field',
					],
					'source_site_url' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return filter_var( $param, FILTER_VALIDATE_URL ); },
						'sanitize_callback' => 'esc_url_raw',
					],
					'callback_url' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return filter_var( $param, FILTER_VALIDATE_URL ); },
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			]
		);

		// Endpoint for handling the authorization confirmation
		register_rest_route(
			$this->namespace,
			'/authorize-confirm',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_authorization_confirmation' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'request_token' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return is_string( $param ) && ! empty( $param ); },
						'sanitize_callback' => 'sanitize_text_field',
					],
					'source_site_url' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return filter_var( $param, FILTER_VALIDATE_URL ); },
						'sanitize_callback' => 'esc_url_raw',
					],
					'callback_url' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return filter_var( $param, FILTER_VALIDATE_URL ); },
						'sanitize_callback' => 'esc_url_raw',
					],
					'action' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return in_array( $param, [ 'approve', 'deny' ], true ); },
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Endpoint for exchanging the auth code for credentials
		register_rest_route(
			$this->namespace,
			'/exchange-code',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_exchange_code' ],
				'permission_callback' => '__return_true', // We'll verify the source site ourselves
				'args'                => [
					'auth_code' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return is_string( $param ) && strlen( $param ) === 40; },
						'sanitize_callback' => 'sanitize_text_field',
					],
					'source_site_url' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return filter_var( $param, FILTER_VALIDATE_URL ); },
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			]
		);

		// Endpoint for handling connection revocation
		register_rest_route(
			$this->namespace,
			'/revoke-connection',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_revoke_connection' ],
				'permission_callback' => '__return_true', // We'll verify the source site ourselves
				'args'                => [
					'source_site_url' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return filter_var( $param, FILTER_VALIDATE_URL ); },
						'sanitize_callback' => 'esc_url_raw',
					],
					'credentials' => [
						'required'          => true,
						'validate_callback' => function( $param ) { return is_array( $param ) && isset( $param['uuid'] ); },
					],
				],
			]
		);
	}

	/**
	 * Check if the user has admin permission.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if the user has permission, WP_Error otherwise.
	 */
	public function check_admin_permission( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage site connections.', 'zs-sync' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Check if the request has permission to access the endpoint based on credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if the request has permission, WP_Error otherwise.
	 */
	public function check_permission( $request ) {
		// Get the authorization header
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header || ! preg_match( '/^Basic (.+)$/', $auth_header, $matches ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing or invalid authorization header.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		// Decode the credentials
		$credentials = base64_decode( $matches[1] );
		list( $username, $password ) = explode( ':', $credentials, 2 );

		// Get the source site and UUID from headers
		$source_site = $request->get_header( 'X-ZS-Sync-Source' );
		$uuid = $request->get_header( 'X-ZS-Sync-UUID' );

		if ( ! $source_site || ! $uuid ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Missing required headers.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		// Get stored connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, array() );

		// Check if this connection exists and is valid
		if ( ! isset( $connections[ $source_site ] ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Connection not found.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		$connection = $connections[ $source_site ];

		// Verify the UUID matches
		if ( $connection['credentials']['uuid'] !== $uuid ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid connection UUID.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		// Verify the credentials match
		if ( $connection['credentials']['username'] !== $username || 
			 $connection['credentials']['password'] !== $password ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Invalid credentials.', 'zs-sync' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle the initial authorization request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function handle_authorization( $request ) {
		// Redirect to the authorization page
		// Only administrators should be able to authorize connections
		if ( ! is_user_logged_in() ) {
			wp_redirect( wp_login_url( $_SERVER['REQUEST_URI'] ) );
			exit;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to authorize connections.', 'zs-sync' ),
				[ 'status' => 403 ]
			);
		}

		// Get the request parameters
		$request_token = $request->get_param( 'request_token' );
		$source_site_url = $request->get_param( 'source_site_url' );
		$callback_url = $request->get_param( 'callback_url' );

		// Create a nonce for the form
		$nonce = wp_create_nonce( 'zs_sync_authorize_connection' );

		// Output the authorization form
		include plugin_dir_path( dirname( __FILE__ ) ) . 'templates/authorize-connection.php';
		exit;
	}

	/**
	 * Handle the authorization confirmation.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function handle_authorization_confirmation( $request ) {
		// Get the request parameters
		$request_token = $request->get_param( 'request_token' );
		$source_site_url = $request->get_param( 'source_site_url' );
		$callback_url = $request->get_param( 'callback_url' );
		$action = $request->get_param( 'action' );

		// Handle denial
		if ( 'deny' === $action ) {
			$redirect_url = add_query_arg(
				[
					'status' => 'denied',
					'request_token' => $request_token,
					'site' => home_url(),
				],
				$callback_url
			);
			wp_redirect( $redirect_url );
			exit;
		}

		// For approval, proceed with creating a new application password
		if ( 'approve' === $action ) {
			// Check if application passwords are supported
			if ( ! class_exists( 'WP_Application_Passwords' ) ) {
				$redirect_url = add_query_arg(
					[
						'status' => 'error',
						'code' => 'app_pass_unavailable',
						'site' => home_url(),
						'request_token' => $request_token,
					],
					$callback_url
				);
				wp_redirect( $redirect_url );
				exit;
			}

			// Generate a unique auth code
			$auth_code = wp_generate_password( 40, false, false );

			// Get current user (must be an admin)
			$user_id = get_current_user_id();

			// Create a new application password
			$app_password = WP_Application_Passwords::create_new_application_password(
				$user_id,
				[
					'name' => 'ZS Sync - ' . $source_site_url,
					'uuid' => wp_generate_uuid4(),
				]
			);

			if ( is_wp_error( $app_password ) ) {
				$redirect_url = add_query_arg(
					[
						'status' => 'error',
						'code' => 'app_pass_failed',
						'site' => home_url(),
						'request_token' => $request_token,
					],
					$callback_url
				);
				wp_redirect( $redirect_url );
				exit;
			}

			// Store the auth code and app password temporarily
			set_transient(
				self::AUTH_CODE_TRANSIENT_PREFIX . $auth_code,
				[
					'credentials' => [
						'username' => wp_get_current_user()->user_login,
						'password' => $app_password[0], // The password value
						'uuid' => $app_password[1]['uuid'], // The UUID
					],
					'user_id' => $user_id,
					'source_site_url' => $source_site_url,
					'created_at' => time(),
				],
				15 * MINUTE_IN_SECONDS
			);

			// Redirect back to Site A with the auth code
			$redirect_url = add_query_arg(
				[
					'status' => 'approved',
					'auth_code' => $auth_code,
					'site' => home_url(),
					'request_token' => $request_token,
				],
				$callback_url
			);
			wp_redirect( $redirect_url );
			exit;
		}

		// If we get here, the action was invalid
		return new WP_Error(
			'invalid_action',
			__( 'Invalid action.', 'zs-sync' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Handle exchanging an auth code for credentials.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The response or error object.
	 */
	public function handle_exchange_code( $request ) {
		// Get the request parameters
		$auth_code = $request->get_param( 'auth_code' );
		$source_site_url = $request->get_param( 'source_site_url' );

		// Retrieve the stored auth code data
		$auth_code_data = get_transient( self::AUTH_CODE_TRANSIENT_PREFIX . $auth_code );
		if ( ! $auth_code_data ) {
			return new WP_Error(
				'invalid_auth_code',
				__( 'Invalid or expired authorization code.', 'zs-sync' ),
				[ 'status' => 400 ]
			);
		}

		// Check if the source site URL matches
		if ( $auth_code_data['source_site_url'] !== $source_site_url ) {
			return new WP_Error(
				'site_mismatch',
				__( 'Source site URL mismatch.', 'zs-sync' ),
				[ 'status' => 400 ]
			);
		}

		// Delete the transient to prevent reuse
		delete_transient( self::AUTH_CODE_TRANSIENT_PREFIX . $auth_code );

		// Return the credentials
		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Successfully exchanged authorization code for credentials.', 'zs-sync' ),
				'credentials' => $auth_code_data['credentials'],
			],
			200
		);
	}

	/**
	 * Handle connection revocation request from another site.
	 *
	 * @param WP_REST_Request $request The incoming request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function handle_revoke_connection( $request ) {
		$source_site_url = $request->get_param( 'source_site_url' );
		$credentials = $request->get_param( 'credentials' );

		// Get current connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );

		// Find the connection by source site URL and credentials UUID
		$found = false;
		foreach ( $connections as $site_url => $connection ) {
			if ( $site_url === $source_site_url && $connection['credentials']['uuid'] === $credentials['uuid'] ) {
				// Revoke the application password
				if ( class_exists( 'WP_Application_Passwords' ) ) {
					WP_Application_Passwords::delete_application_password(
						$connection['local_user_id'],
						$connection['credentials']['uuid']
					);
				}

				// Remove the connection
				unset( $connections[ $site_url ] );
				update_option( self::CONNECTIONS_OPTION_NAME, $connections );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error(
				'connection_not_found',
				__( 'Connection not found.', 'zs-sync' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Connection revoked successfully.', 'zs-sync' ),
			],
			200
		);
	}
} 