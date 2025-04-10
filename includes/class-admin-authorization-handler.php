<?php

namespace Automattic\Syndication\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WP_Error;
use WP_User;
use WP_Application_Passwords;

/**
 * Class Admin_Authorization_Handler
 *
 * Handles the admin UI part of the authorization flow (Site B).
 */
class Admin_Authorization_Handler {

	private const PAGE_SLUG = 'zs-sync-authorize';
	private const CONNECTIONS_OPTION_NAME = 'zs_sync_connections'; // Option to store connection details
	private const AUTH_CODE_TRANSIENT_PREFIX = 'zs_sync_auth_code_'; // Transient prefix for temporary auth codes

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		// Add action to handle form processing before headers are sent
		add_action( 'admin_init', [ $this, 'maybe_process_authorization_form' ] );
	}

	/**
	 * Register a hidden admin page for the authorization flow.
	 */
	public function register_admin_page() {
		// Register the page but hide it from the menu
		add_submenu_page(
			'__non_existent_parent_slug', // Non-existent parent slug hides it from the menu
			__( 'Connection Authorization', 'zs-sync' ), // Page title
			__( 'Connection Authorization', 'zs-sync' ), // Menu title (not visible)
			'manage_options', // Capability required
			self::PAGE_SLUG, // Menu slug
			[ $this, 'handle_authorization_page' ] // Callback function
		);
	}

	/**
	 * Check if we need to process the authorization form and do it early
	 * before any headers are sent.
	 */
	public function maybe_process_authorization_form() {
		// Only process if we're on our page and it's a POST request
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			$this->process_authorization_form();
		}
	}

	/**
	 * Handles the display of the authorization page.
	 */
	public function handle_authorization_page() {
		// Set global title for the admin page
		// Verify user capability early.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'zs-sync' ), 403 );
		}

		// Only render the form for GET requests
		$this->render_authorization_form();
	}

	/**
	 * Renders the HTML form for the admin on Site B to authorize the connection.
	 * Triggered by a GET request to the hidden admin page.
	 */
	private function render_authorization_form() {
		// 1. Get parameters from query string (GET request).
		$request_token   = isset( $_GET['request_token'] ) ? sanitize_key( $_GET['request_token'] ) : '';
		$source_site_url = isset( $_GET['source_site_url'] ) ? esc_url_raw( wp_unslash( $_GET['source_site_url'] ) ) : '';
		$callback_url    = isset( $_GET['callback_url'] ) ? esc_url_raw( wp_unslash( $_GET['callback_url'] ) ) : ''; // Already decoded by the time it gets here? Assume yes for now.

		// 2. Basic validation of required parameters.
		// Basic validation (request token format, URLs). Stronger validation happens on POST.
		if ( empty( $request_token ) || strlen( $request_token ) !== 32 || empty( $source_site_url ) || ! filter_var( $source_site_url, FILTER_VALIDATE_URL ) || empty( $callback_url ) || ! filter_var( $callback_url, FILTER_VALIDATE_URL ) ) {
			wp_die( __( 'Invalid or missing authorization parameters.', 'zs-sync' ), 400 );
		}

		// 3. Display the authorization confirmation page/form.
		// We are already in the admin context here.
		$title = __( 'Connection Authorization Request', 'zs-sync' );

		// Admin header is included automatically by WordPress.
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: Requesting site URL (bold), 2: Current site URL (bold) */
					esc_html__( 'The site %1$s is requesting permission to connect to this site (%2$s) for synchronization.', 'zs-sync' ),
					'<strong>' . esc_html( $source_site_url ) . '</strong>',
					'<strong>' . esc_html( home_url() ) . '</strong>'
				);
				?>
			</p>
			<p><?php echo esc_html__( 'Approving this request will allow the requesting site to perform actions via the REST API using credentials generated specifically for it.', 'zs-sync' ); ?></p>
			<p><?php echo esc_html__( 'This connection will be associated with your user account.', 'zs-sync' ); ?> (<?php echo esc_html( wp_get_current_user()->user_login ); ?>)</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
				<input type="hidden" name="request_token" value="<?php echo esc_attr( $request_token ); ?>">
				<input type="hidden" name="source_site_url" value="<?php echo esc_attr( $source_site_url ); ?>">
				<input type="hidden" name="callback_url" value="<?php echo esc_attr( $callback_url ); ?>">
				<?php // Add nonce for the confirmation step. Use the page slug and token for uniqueness. ?>
				<?php wp_nonce_field( 'zs_sync_authorize_confirm_' . $request_token, '_wpnonce_confirm_auth' ); ?>

				<p class="submit">
					<input type="submit" name="zs_sync_approve" class="button button-primary" value="<?php esc_attr_e( 'Approve Connection', 'zs-sync' ); ?>">
					<input type="submit" name="zs_sync_deny" class="button" value="<?php esc_attr_e( 'Deny Connection', 'zs-sync' ); ?>" style="margin-left: 10px; color: #a00; border-color: #a00;">
				</p>
			</form>
		</div>
		<?php
		// Admin footer is included automatically by WordPress.
	}


	/**
	 * Handle the POST submission from the authorization confirmation page.
	 * Generates credentials if approved and redirects back to Site A's callback URL.
	 * Triggered by a POST request to the hidden admin page.
	 */
	private function process_authorization_form() {
		// Verify user capability early.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'zs-sync' ), 403 );
		}

		// 1. Security checks (Nonce verification)
		$request_token = isset( $_POST['request_token'] ) ? sanitize_key( $_POST['request_token'] ) : '';
		if ( empty( $request_token ) || ! check_admin_referer( 'zs_sync_authorize_confirm_' . $request_token, '_wpnonce_confirm_auth' ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ), 403 );
		}

		// 2. Get POST data.
		$source_site_url = isset( $_POST['source_site_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_site_url'] ) ) : '';
		$callback_url    = isset( $_POST['callback_url'] ) ? esc_url_raw( wp_unslash( $_POST['callback_url'] ) ) : '';
		$is_approved     = isset( $_POST['zs_sync_approve'] );
		$is_denied       = isset( $_POST['zs_sync_deny'] );

		// 3. Validate required parameters received via POST.
		if ( empty( $source_site_url ) || ! filter_var( $source_site_url, FILTER_VALIDATE_URL ) || empty( $callback_url ) || ! filter_var( $callback_url, FILTER_VALIDATE_URL ) ) {
			// Nonce was valid, but other params are bad.
			wp_die( __( 'Invalid request parameters.', 'zs-sync' ), 400 );
		}

		// Use the actual callback URL instead of hardcoded value
		$redirect_base_url = $callback_url;

		// 4. Handle Deny action.
		if ( $is_denied ) {
			$redirect_url = add_query_arg(
				[
					'status'        => 'denied',
					'site'          => urlencode( home_url() ), // The site that denied (this site)
					'request_token' => $request_token, // Original request token.
				],
				$redirect_base_url
			);
			wp_redirect( $redirect_url );
			exit;
		}

		// 5. Handle Approve action.
		if ( $is_approved ) {			
			// If application passwords are not available
			if ( ! wp_is_application_passwords_available() ) {
				$error_code   = 'app_pass_unavailable';
				$redirect_url = add_query_arg(
					[
						'status'        => 'error',
						'code'          => $error_code,
						'site'          => urlencode( home_url() ), // This site
						'request_token' => $request_token,
					],
					$redirect_base_url
				);
				wp_redirect( $redirect_url );
				exit;
			}

			$user_id  = get_current_user_id();
			$app_name = sprintf( __( 'ZS Sync Connection: %s', 'zs-sync' ), preg_replace( '#^https?://#', '', $source_site_url ) ); // Use a slightly friendlier name.

			// Generate a new Application Password.
			$result = WP_Application_Passwords::create_new_application_password( $user_id, [ 'name' => $app_name ] );

			if ( is_wp_error( $result ) ) {
				// Failed to create app password.
				$redirect_url = add_query_arg(
					[
						'status'        => 'error',
						'code'          => 'app_pass_failed',
						'error_message' => urlencode( $result->get_error_message() ),
						'site'          => urlencode( home_url() ),
						'request_token' => $request_token,
					],
					$redirect_base_url
				);
				wp_redirect( $redirect_url );
				exit;
			}

			// $result is [ 'password' => $new_password, 'uuid' => $item['uuid'], 'name' => ..., etc ]
			list( $new_password, $item_data ) = $result;

			// Store connection details.
			$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );
			$connections[ $source_site_url ] = [
				'app_password_uuid'     => $item_data['uuid'],
				'app_password_name'     => $app_name, // Store the name used.
				'authorized_by_user_id' => $user_id,
				'authorized_at'         => time(),
				'site_url'              => $source_site_url, // Store for easier lookup/display.
				'local_user_id'         => $user_id, // User on *this* site who authorized.
			];
			update_option( self::CONNECTIONS_OPTION_NAME, $connections );

			// Generate a temporary auth code for Site A to exchange for the real password.
			$auth_code = wp_generate_password( 40, false );

			// Store the *actual* new password temporarily, associated with the auth code.
			set_transient(
				self::AUTH_CODE_TRANSIENT_PREFIX . $auth_code,
				[
					'password' => $new_password,
					'uuid'     => $item_data['uuid'],
					'site_url' => $source_site_url, // Verify the request comes from the expected site during exchange.
				],
				5 * MINUTE_IN_SECONDS // Short lifespan.
			);

			// Redirect back to Site A's callback URL with success status and the temporary auth code.
			$redirect_url = add_query_arg(
				[
					'status'        => 'approved',
					'auth_code'     => $auth_code,
					'site'          => urlencode( home_url() ), // Tell Site A which site (this one) approved.
					'request_token' => $request_token, // Include original token for correlation.
				],
				$redirect_base_url
			);
			wp_redirect( $redirect_url );
			exit;
		}

		// If neither approve nor deny was set (shouldn't happen with the form buttons).
		wp_die( __( 'Invalid action.', 'zs-sync' ), 400 );
	}
}