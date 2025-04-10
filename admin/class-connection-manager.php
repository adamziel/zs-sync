<?php

namespace Automattic\Syndication\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Connection_Manager
 *
 * Handles the admin UI and logic for site connections.
 */
class Connection_Manager {

	private const NONCE_ACTION = 'zs_sync_initiate_connection';
	private const NONCE_ACTION_REVOKE = 'zs_sync_revoke_connection';
	private const NONCE_ACTION_ENABLE_SYNC = 'zs_sync_enable_sync';
	private const NONCE_ACTION_DISABLE_SYNC = 'zs_sync_disable_sync';
	private const NONCE_ACTION_SYNC_NOW = 'zs_sync_now';
	private const NONCE_ACTION_SCAN_NOW = 'zs_sync_scan_now';
	private const TRANSIENT_PREFIX = 'zs_sync_connect_';
	private const CONNECTIONS_OPTION_NAME = 'zs_sync_connections'; // Option to store connection details

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
		add_action( 'admin_post_zs_initiate_connection', [ $this, 'handle_initiate_connection' ] );
		add_action( 'admin_post_zs_revoke_connection', [ $this, 'handle_revoke_connection' ] );
		add_action( 'admin_post_zs_enable_sync', [ $this, 'handle_enable_sync' ] );
		add_action( 'admin_post_zs_disable_sync', [ $this, 'handle_disable_sync' ] );
		add_action( 'admin_post_zs_sync_now', [ $this, 'handle_sync_now' ] );
		add_action( 'admin_post_zs_scan_now', [ $this, 'handle_scan_now' ] );
		add_action( 'admin_init', [ $this, 'handle_callback' ] );
	}

	/**
	 * Register the admin page.
	 */
	public function register_admin_page() {
		add_options_page(
			__( 'Site Connections', 'zs-sync' ), // Page title
			__( 'Site Connections', 'zs-sync' ), // Menu title
			'manage_options',                  // Capability required
			'zs-sync-connections',             // Menu slug
			[ $this, 'render_admin_page' ]       // Callback function to render the page
		);
	}

	/**
	 * Render the admin page content.
	 */
	public function render_admin_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Manage Site Connections', 'zs-sync' ); ?></h1>

			<?php settings_errors(); // Display any admin notices (e.g., from failed validation) ?>

			<h2><?php echo esc_html__( 'Connect to a New Site', 'zs-sync' ); ?></h2>
			<p><?php echo esc_html__( 'This plugin performs one-way synchronization between two sites: a Source site (authoritative) and a Destination site (receives updates).', 'zs-sync' ); ?></p>
			<p><?php echo esc_html__( 'Enter the URL of the other WordPress site and specify its role in the sync relationship. You must be an administrator on the other site to authorize the connection.', 'zs-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zs_initiate_connection">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table">
					<tr valign="top">
						<th scope="row">
							<label for="site_b_url"><?php echo esc_html__( 'Other Site URL', 'zs-sync' ); ?></label>
						</th>
						<td>
							<input type="url" id="site_b_url" name="site_b_url" class="regular-text" required placeholder="https://othersite.com">
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php echo esc_html__( 'Role of Other Site', 'zs-sync' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="sync_direction" value="source" checked>
									<?php echo esc_html__( 'Source (This site will PULL content FROM the site above)', 'zs-sync' ); ?><br>
									<small><i><?php echo esc_html__( 'The site specified above is the authoritative source. Content on this site (the Destination) will be updated based on the Source.', 'zs-sync' ); ?></i></small>
								</label><br>
								<label>
									<input type="radio" name="sync_direction" value="destination">
									<?php echo esc_html__( 'Destination (This site will PUSH content TO the site above)', 'zs-sync' ); ?><br>
									<small><i><?php echo esc_html__( 'This site is the authoritative Source. Content will be pushed to the site specified above (the Destination).', 'zs-sync' ); ?></i></small>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Initiate Connection', 'zs-sync' ) ); ?>
			</form>

			

			<hr />

			<h2><?php echo esc_html__( 'Current Connections', 'zs-sync' ); ?></h2>
			<?php
			$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );
			if ( empty( $connections ) ) {
				?>
				<p><i><?php echo esc_html__( 'No sites are currently connected.', 'zs-sync' ); ?></i></p>
				<?php
			} else {
				?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Site URL', 'zs-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Role', 'zs-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Sync Status', 'zs-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last Scan', 'zs-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Connected', 'zs-sync' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Actions', 'zs-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $connections as $site_url => $connection ) {
							$connected_by_user = get_user_by( 'id', $connection['connected_by_user_id'] );
							$connected_by_name = $connected_by_user ? $connected_by_user->display_name : __( 'Unknown User', 'zs-sync' );
							$connected_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $connection['connected_at'] );
							$role = isset( $connection['role'] ) ? $connection['role'] : 'unknown'; // 'source' or 'destination' relative to the other site
							$sync_enabled = isset( $connection['sync_enabled'] ) ? (bool) $connection['sync_enabled'] : false;

							$role_label = 'destination' === $role
								? __( 'Source (Pulling from this site)', 'zs-sync' )
								: __( 'Destination (Pushing to this site)', 'zs-sync' );

							$status_label = $sync_enabled ? __( 'Enabled', 'zs-sync' ) : __( 'Disabled', 'zs-sync' );
							
							// Get last scan time
							$last_scan_version = get_option( 'zs_sync_last_scan_time_' . md5($site_url), 0 );
							$last_scan_date = $last_scan_version ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan_version ) : __( 'Never', 'zs-sync' );
							?>
							<tr>
								<td><?php echo esc_html( $site_url ); ?></td>
								<td><?php echo esc_html( $role_label ); ?></td>
								<td><?php echo esc_html( $status_label ); ?></td>
								<td><?php echo esc_html( $last_scan_date ); ?></td>
								<td>
									<?php echo esc_html( $connected_date ); ?><br>
									<small><i><?php echo sprintf( /* translators: %s: User display name */ esc_html__( 'by %s', 'zs-sync' ), esc_html( $connected_by_name ) ); ?></i></small>
								</td>
								<td>
									<?php
									// Show Enable/Disable sync only if this site is the destination for this connection
									if ( 'destination' === $role ) {
										if ( $sync_enabled ) {
											?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-right: 5px;">
												<input type="hidden" name="action" value="zs_disable_sync">
												<input type="hidden" name="site_url" value="<?php echo esc_attr( $site_url ); ?>">
												<?php wp_nonce_field( self::NONCE_ACTION_DISABLE_SYNC ); ?>
												<?php submit_button( __( 'Disable Sync', 'zs-sync' ), 'button button-small', 'submit', false ); ?>
											</form>
											
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-right: 5px;">
												<input type="hidden" name="action" value="zs_sync_now">
												<input type="hidden" name="site_url" value="<?php echo esc_attr( $site_url ); ?>">
												<?php wp_nonce_field( self::NONCE_ACTION_SYNC_NOW ); ?>
												<?php submit_button( __( 'Sync Now', 'zs-sync' ), 'primary button-small', 'submit', false ); ?>
											</form>
											<?php
										} else {
											?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-right: 5px;" onsubmit="return confirm('<?php echo esc_js( sprintf( /* translators: %s: Site URL */ __( 'WARNING: Enabling sync will OVERWRITE this site\'s content with content from %s. This action cannot be undone. The initial sync may take some time, but content should remain browse-able during the process. Are you sure you want to continue?', 'zs-sync' ), $site_url ) ); ?>');">
												<input type="hidden" name="action" value="zs_enable_sync">
												<input type="hidden" name="site_url" value="<?php echo esc_attr( $site_url ); ?>">
												<?php wp_nonce_field( self::NONCE_ACTION_ENABLE_SYNC ); ?>
												<?php submit_button( __( 'Enable Sync', 'zs-sync' ), 'primary button-small', 'submit', false ); ?>
											</form>
											<?php
										}
									} else {
										// This is a source site (we push to it), so add a "Scan Now" button
										?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-right: 5px;">
											<input type="hidden" name="action" value="zs_scan_now">
											<input type="hidden" name="site_url" value="<?php echo esc_attr( $site_url ); ?>">
											<?php wp_nonce_field( self::NONCE_ACTION_SCAN_NOW ); ?>
											<?php submit_button( __( 'Scan Now', 'zs-sync' ), 'primary button-small', 'submit', false ); ?>
										</form>
										<?php
									}
									?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="zs_revoke_connection">
										<input type="hidden" name="site_url" value="<?php echo esc_attr( $site_url ); ?>">
										<?php wp_nonce_field( self::NONCE_ACTION_REVOKE ); ?>
										<?php submit_button( __( 'Revoke', 'zs-sync' ), 'button button-small button-link-delete', 'submit', false ); ?>
									</form>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
				<?php
			}
			?>

		</div>
		<?php
	}

	/**
	 * Handle the initiation request from Site A.
	 */
	public function handle_initiate_connection() {
		// 1. Security checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), self::NONCE_ACTION ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage site connections.', 'zs-sync' ) );
		}

		// 2. Validate Site B URL and Sync Direction
		$site_b_url = isset( $_POST['site_b_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_b_url'] ) ) : '';
		$sync_direction = isset( $_POST['sync_direction'] ) && in_array( $_POST['sync_direction'], [ 'source', 'destination' ], true ) ? sanitize_key( $_POST['sync_direction'] ) : '';

		if ( empty( $site_b_url ) || ! filter_var( $site_b_url, FILTER_VALIDATE_URL ) ) {
			add_settings_error( 'zs-sync-connections', 'invalid_url', __( 'Please enter a valid URL for the other site.', 'zs-sync' ), 'error' );
			header( 'Location: ' . admin_url( 'options-general.php?page=zs-sync-connections' ) );
			exit;
		}
		if ( empty( $sync_direction ) ) {
			add_settings_error( 'zs-sync-connections', 'invalid_direction', __( 'Please select a valid sync direction.', 'zs-sync' ), 'error' );
			header( 'Location: ' . admin_url( 'options-general.php?page=zs-sync-connections' ) );
			exit;
		}

		// Remove trailing slash for consistency
		$site_b_url = untrailingslashit( $site_b_url );

		// Prevent connecting to self
		if ( untrailingslashit( home_url() ) === $site_b_url ) {
			add_settings_error( 'zs-sync-connections', 'self_connect', __( 'You cannot connect a site to itself.', 'zs-sync' ), 'error' );
			header( 'Location: ' . admin_url( 'options-general.php?page=zs-sync-connections' ) );
			exit;
		}

		// 3. Generate request token
		$request_token = wp_generate_password( 32, false ); // 32-char secure token

		// 4. Store token, Site B URL and direction temporarily (e.g., 15 minutes)
		set_transient( self::TRANSIENT_PREFIX . $request_token, [ 
			'site_b_url' => $site_b_url,
			'sync_direction' => $sync_direction, // 'source' means site B is source, 'destination' means site B is destination
		], 15 * MINUTE_IN_SECONDS );

		// 5. Construct redirect URL for Site B's authorization *admin page*
		$authorize_page_url = trailingslashit( $site_b_url ) . 'wp-admin/admin.php'; // Base admin URL on Site B

		// Define the callback URL on this site (Site A)
		// Ensure it matches the URL expected by handle_callback()
		$callback_url = admin_url( 'options-general.php?page=zs-sync-connections&zs_action=callback' );

		$redirect_url = add_query_arg(
			[
				'page'            => 'zs-sync-authorize', // The slug of the hidden admin page on Site B
				'request_token'   => $request_token,
				'source_site_url' => home_url(),
				'callback_url'    => urlencode( $callback_url ), // URL encode the callback URL
			],
			$authorize_page_url
		);

		// 6. Redirect user to Site B's authorization page
		header( 'Location: ' . $redirect_url );
		exit;
	}

	/**
	 * Handle the callback from Site B after authorization.
	 */
	public function handle_callback() {
		// Only process if we're on our callback URL
		if ( ! isset( $_GET['zs_action'] ) || 'callback' !== $_GET['zs_action'] ) {
			return;
		}

		// Verify user is an admin
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Permission denied.', 'zs-sync' ), 403 );
		}

		// Get and validate parameters
		$request_token = isset( $_GET['request_token'] ) ? sanitize_key( $_GET['request_token'] ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$site = isset( $_GET['site'] ) ? esc_url_raw( wp_unslash( $_GET['site'] ) ) : '';
		$auth_code = isset( $_GET['auth_code'] ) ? sanitize_key( $_GET['auth_code'] ) : '';

		if ( empty( $request_token ) || empty( $status ) || empty( $site ) ) {
			add_settings_error(
				'zs-sync-connections',
				'invalid_callback',
				__( 'Invalid callback parameters.', 'zs-sync' ),
				'error'
			);
			$this->redirect_to_connections_page();
		}

		// Retrieve the original connection request details
		$request_details = get_transient( self::TRANSIENT_PREFIX . $request_token );
		if ( false === $request_details ) {
			add_settings_error(
				'zs-sync-connections',
				'expired_request',
				__( 'Connection request has expired. Please try again.', 'zs-sync' ),
				'error'
			);
			$this->redirect_to_connections_page();
		}

		// Verify the site parameter matches our stored target site
		if ( $request_details['site_b_url'] !== $site ) {
			add_settings_error(
				'zs-sync-connections',
				'site_mismatch',
				__( 'Site mismatch. Please try again.', 'zs-sync' ),
				'error'
			);
			delete_transient( self::TRANSIENT_PREFIX . $request_token );
			$this->redirect_to_connections_page();
		}

		// Handle different status responses
		switch ( $status ) {
			case 'denied':
				add_settings_error(
					'zs-sync-connections',
					'connection_denied',
					sprintf(
						/* translators: %s: Site URL */
						__( 'Connection request was denied by %s.', 'zs-sync' ),
						esc_html( $site )
					),
					'error'
				);
				break;

			case 'error':
				$error_code = isset( $_GET['code'] ) ? sanitize_key( $_GET['code'] ) : 'unknown';
				$error_message = $this->get_error_message( $error_code );
				add_settings_error(
					'zs-sync-connections',
					'connection_error',
					sprintf(
						/* translators: 1: Site URL, 2: Error message */
						__( 'Error connecting to %1$s: %2$s', 'zs-sync' ),
						esc_html( $site ),
						esc_html( $error_message )
					),
					'error'
				);
				break;

			case 'approved':
				if ( empty( $auth_code ) ) {
					add_settings_error(
						'zs-sync-connections',
						'missing_auth_code',
						__( 'Missing authorization code. Please try again.', 'zs-sync' ),
						'error'
					);
					break;
				}

				// Make a secure call to Site B to exchange the auth code for credentials
				$response = wp_remote_post(
					trailingslashit( $site ) . 'wp-json/zs-sync/v1/exchange-code',
					[
						'body' => [
							'auth_code' => $auth_code,
							'source_site_url' => home_url(),
						],
						'timeout' => 15,
					]
				);

				if ( is_wp_error( $response ) ) {
					add_settings_error(
						'zs-sync-connections',
						'exchange_failed',
						sprintf(
							/* translators: 1: Site URL, 2: Error message */
							__( 'Failed to exchange authorization code with %1$s: %2$s', 'zs-sync' ),
							esc_html( $site ),
							esc_html( $response->get_error_message() )
						),
						'error'
					);
					break;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( ! $body || ! isset( $body['success'] ) || ! $body['success'] ) {
					add_settings_error(
						'zs-sync-connections',
						'exchange_failed',
						sprintf(
							/* translators: 1: Site URL, 2: Error message */
							__( 'Failed to exchange authorization code with %1$s: %2$s', 'zs-sync' ),
							esc_html( $site ),
							isset( $body['message'] ) ? esc_html( $body['message'] ) : __( 'Unknown error', 'zs-sync' )
						),
						'error'
					);
					break;
				}

				// Store the connection details
				$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );
				// Role: 'source' if the other site is the source, 'destination' if the other site is the destination
				$role_for_this_connection = $request_details['sync_direction'] === 'source' ? 'destination' : 'source';
				$connections[ $site ] = [
					'credentials' => $body['credentials'],
					'connected_at' => time(),
					'connected_by_user_id' => get_current_user_id(),
					'site_url' => $site,
					'role' => $role_for_this_connection,
					'sync_enabled' => false, // Sync is disabled by default
				];
				update_option( self::CONNECTIONS_OPTION_NAME, $connections );

				add_settings_error(
					'zs-sync-connections',
					'connection_success',
					sprintf(
						/* translators: %s: Site URL */
						__( 'Successfully connected to %s. You can now enable synchronization.', 'zs-sync' ),
						esc_html( $site )
					),
					'success'
				);
				break;

			default:
				add_settings_error(
					'zs-sync-connections',
					'unknown_status',
					__( 'Unknown response status from remote site.', 'zs-sync' ),
					'error'
				);
		}

		// Clean up the transient
		delete_transient( self::TRANSIENT_PREFIX . $request_token );

		// Redirect back to the connections page
		$this->redirect_to_connections_page();
	}

	/**
	 * Handle connection revocation.
	 */
	public function handle_revoke_connection() {
		// Security checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), self::NONCE_ACTION_REVOKE ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage site connections.', 'zs-sync' ) );
		}

		// Get and validate site URL
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( ! $this->validate_site_url_parameter( $site_url ) ) {
			$this->redirect_to_connections_page();
		}

		// Get current connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );

		// Check if the connection exists
		if ( ! isset( $connections[ $site_url ] ) ) {
			add_settings_error(
				'zs-sync-connections',
				'connection_not_found',
				__( 'Connection not found.', 'zs-sync' ),
				'error'
			);
			$this->redirect_to_connections_page();
		}

		// Notify the remote site about the revocation
		$connection = $connections[ $site_url ];
		$response = wp_remote_post(
			trailingslashit( $site_url ) . 'wp-json/zs-sync/v1/revoke-connection',
			[
				'body' => [
					'source_site_url' => home_url(),
					'credentials' => $connection['credentials'],
				],
				'timeout' => 15,
			]
		);
		// Note: We don't necessarily care about the response here. Even if the remote
		// notification fails, we proceed with local revocation.

		// Revoke locally
		unset( $connections[ $site_url ] );
		update_option( self::CONNECTIONS_OPTION_NAME, $connections );

		add_settings_error(
			'zs-sync-connections',
			'connection_revoked',
			sprintf(
				/* translators: %s: Site URL */
				__( 'Connection to %s has been revoked.', 'zs-sync' ),
				esc_html( $site_url )
			),
			'success'
		);

		$this->redirect_to_connections_page();
	}

	/**
	 * Handle enabling synchronization for a connection.
	 */
	public function handle_enable_sync() {
		// Security checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), self::NONCE_ACTION_ENABLE_SYNC ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage site connections.', 'zs-sync' ) );
		}

		// Get and validate site URL
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( ! $this->validate_site_url_parameter( $site_url ) ) {
			$this->redirect_to_connections_page();
		}

		// Get current connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );

		// Check if the connection exists and this site is the destination
		if ( ! isset( $connections[ $site_url ] ) || 'destination' !== $connections[ $site_url ]['role'] ) {
			add_settings_error(
				'zs-sync-connections',
				'enable_sync_invalid',
				__( 'Cannot enable sync for this connection.', 'zs-sync' ),
				'error'
			);
			$this->redirect_to_connections_page();
		}

		// Enable sync
		$connections[ $site_url ]['sync_enabled'] = true;
		update_option( self::CONNECTIONS_OPTION_NAME, $connections );

		add_settings_error(
			'zs-sync-connections',
			'sync_enabled',
			sprintf(
				/* translators: %s: Site URL */
				__( 'Synchronization from %s has been enabled.', 'zs-sync' ),
				esc_html( $site_url )
			),
			'success'
		);

		// TODO: Trigger initial sync process here?

		$this->redirect_to_connections_page();
	}

	/**
	 * Handle disabling synchronization for a connection.
	 */
	public function handle_disable_sync() {
		// Security checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), self::NONCE_ACTION_DISABLE_SYNC ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage site connections.', 'zs-sync' ) );
		}

		// Get and validate site URL
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( ! $this->validate_site_url_parameter( $site_url ) ) {
			$this->redirect_to_connections_page();
		}

		// Get current connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );

		// Check if the connection exists
		if ( ! isset( $connections[ $site_url ] ) ) {
			add_settings_error(
				'zs-sync-connections',
				'disable_sync_invalid',
				__( 'Cannot disable sync for a non-existent connection.', 'zs-sync' ),
				'error'
			);
			$this->redirect_to_connections_page();
		}

		// Disable sync
		$connections[ $site_url ]['sync_enabled'] = false;
		update_option( self::CONNECTIONS_OPTION_NAME, $connections );

		add_settings_error(
			'zs-sync-connections',
			'sync_disabled',
			sprintf(
				/* translators: %s: Site URL */
				__( 'Synchronization from %s has been disabled.', 'zs-sync' ),
				esc_html( $site_url )
			),
			'success'
		);

		$this->redirect_to_connections_page();
	}

	/**
	 * Handle initiating a sync process manually.
	 */
	public function handle_sync_now() {
		// Security checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), self::NONCE_ACTION_SYNC_NOW ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage site connections.', 'zs-sync' ) );
		}

		// Get and validate site URL
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( ! $this->validate_site_url_parameter( $site_url ) ) {
			$this->redirect_to_connections_page();
		}

		// Get current connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );

		// Check if the connection exists and this site is the destination
		if ( ! isset( $connections[ $site_url ] ) || 'destination' !== $connections[ $site_url ]['role'] || ! $connections[ $site_url ]['sync_enabled'] ) {
			add_settings_error(
				'zs-sync-connections',
				'sync_not_enabled',
				__( 'Sync is not enabled for this connection.', 'zs-sync' ),
				'error'
			);
			$this->redirect_to_connections_page();
		}

		try {
			// Initialize the client for the remote site
			$remote_site_url = trailingslashit( $site_url ) . 'index.php?rest_route=%2Fzs-sync%2Fv1%2Fresources';
			$client = new \ZS_Sync_Transport_Wordpress_Rest_Api_Client(
				$remote_site_url,
				$connections[ $site_url ]['credentials']
			);

			// Set up database connection
			global $wpdb;

			// Get last processed version from wp_options
			$last_processed_version = get_option( 'zs_sync_last_processed_version', null );

			// Configure import options
			$import_options = [
				'files_output_dir' => WP_CONTENT_DIR,
				'last_processed_version' => $last_processed_version,
				'mysqli' => $wpdb->dbh,
			];

			// Create the importer
			$importer = new \ZS_Sync_Data_Importer( $client, $import_options );

			// Run the import process for a single step
			$importer->import_step();
			
			// Store the updated last processed version
			$last_processed_version = $importer->get_last_processed_version();
			update_option( 'zs_sync_last_processed_version', $last_processed_version );

			// Get stats
			$stats = $importer->get_stats();
			
			// Add success message
			add_settings_error(
				'zs-sync-connections',
				'sync_success',
				sprintf(
					/* translators: 1: Site URL, 2: Tables processed, 3: Rows processed, 4: Files processed */
					__( 'Sync from %1$s completed. Tables: %2$d, Rows: %3$d, Files: %4$d', 'zs-sync' ),
					esc_html( $site_url ),
					$stats['tables_processed'],
					$stats['rows_processed'],
					$stats['files_processed']
				),
				'success'
			);

			// If there are errors, add them as notices
			if ( ! empty( $stats['errors'] ) ) {
				foreach ( $stats['errors'] as $error ) {
					add_settings_error(
						'zs-sync-connections',
						'sync_warning',
						sprintf( 
							/* translators: %s: Error message */
							__( 'Warning during sync: %s', 'zs-sync' ), 
							esc_html( $error ) 
						),
						'warning'
					);
				}
			}

			// If more data is available for syncing, add a notice
			if ( $importer->has_more() ) {
				add_settings_error(
					'zs-sync-connections',
					'sync_more',
					__( 'More data is available for syncing. Click "Sync Now" again to continue.', 'zs-sync' ),
					'info'
				);
			}

		} catch ( \Exception $e ) {
			add_settings_error(
				'zs-sync-connections',
				'sync_failed',
				sprintf(
					/* translators: 1: Site URL, 2: Error message */
					__( 'Error syncing from %1$s: %2$s', 'zs-sync' ),
					esc_html( $site_url ),
					esc_html( $e->getMessage() )
				),
				'error'
			);
		}

		$this->redirect_to_connections_page();
	}

	/**
	 * Handle initiating a scan process manually.
	 */
	public function handle_scan_now() {
		// Security checks
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), self::NONCE_ACTION_SCAN_NOW ) ) {
			wp_die( __( 'Security check failed.', 'zs-sync' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to manage site connections.', 'zs-sync' ) );
		}

		// Get and validate site URL
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( ! $this->validate_site_url_parameter( $site_url ) ) {
			$this->redirect_to_connections_page();
		}

		// Get current connections
		$connections = get_option( self::CONNECTIONS_OPTION_NAME, [] );

		// Check if the connection exists and this site is the source
		// if ( ! isset( $connections[ $site_url ] ) || 'source' !== $connections[ $site_url ]['role'] ) {
		// 	add_settings_error(
		// 		'zs-sync-connections',
		// 		'scan_not_enabled',
		// 		__( 'Scan can only be performed for sites where this site is the source.', 'zs-sync' ),
		// 		'error'
		// 	);
		// 	$this->redirect_to_connections_page();
		// }

		try {
			// Get configuration
			$chunk_size = get_option( 'zs_sync_scanning_chunk_size', 10 ); // Default: 10 items
			
			// Get all registered scanners
			$scanners = apply_filters( 'zs_sync_registered_scanners', array() );
			

			// @TODO don't do complete scans, just one step. Also have a reusable list of scanners.
			$directory_scanner = new \ZS_Sync_Scanner_Directory(WP_CONTENT_DIR);
			while ($directory_scanner->next_chunk()) {
				// ... twiddle our thumbs ...
				// print_r($directory_scanner->get_cursor());
			}

			$directory_scanner = new \ZS_Sync_Scanner_Directory_Deletions(WP_CONTENT_DIR);
			while ($directory_scanner->next_chunk()) {
				// ... twiddle our thumbs ...
				// print_r($directory_scanner->get_cursor());
			}

			$table_scanner = new \ZS_Sync_Scanner_Table();
			while ($table_scanner->next_chunk()) {
				// ... twiddle our thumbs ...
				// print_r($table_scanner->get_cursor());
			}

			
			$items_scanned = 0;
			$scan_complete = true;
			
			// Process each scanner
			foreach ( $scanners as $scanner ) {
				$result = $scanner->next_chunk();
				if ($result) {
					$items_scanned += $result['count'] ?? 0;
					if (isset($result['more_chunks']) && $result['more_chunks']) {
						$scan_complete = false;
					}
				}
			}
			
			// Update last scan time
			update_option('zs_sync_last_scan_time_' . md5($site_url), time());
			
			// Add success message
			add_settings_error(
				'zs-sync-connections',
				'scan_success',
				sprintf(
					/* translators: %d: Number of items scanned */
					__( 'Scan completed. %d items processed.', 'zs-sync' ),
					$items_scanned
				),
				'success'
			);
			
			// If scan is not complete, add a notice
			if (!$scan_complete) {
				add_settings_error(
					'zs-sync-connections',
					'scan_more',
					__( 'More items need to be scanned. Click "Scan Now" again to continue.', 'zs-sync' ),
					'info'
				);
			}

		} catch ( \Exception $e ) {
			add_settings_error(
				'zs-sync-connections',
				'scan_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Error during scan: %s', 'zs-sync' ),
					esc_html( $e->getMessage() )
				),
				'error'
			);
		}

		$this->redirect_to_connections_page();
	}

	/**
	 * Helper function to redirect back to the connections page.
	 */
	private function redirect_to_connections_page() {
		// Preserve settings errors across redirects
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		
		header( 'Location: ' . add_query_arg( 'settings-updated', 'true', admin_url( 'options-general.php?page=zs-sync-connections' ) ) );
		exit;
	}

	/**
	 * Helper function to validate the site_url POST parameter.
	 *
	 * @param string $site_url The raw site URL.
	 * @return bool True if valid, false otherwise (and sets an error).
	 */
	private function validate_site_url_parameter( $site_url ) {
		if ( empty( $site_url ) || ! filter_var( $site_url, FILTER_VALIDATE_URL ) ) {
			add_settings_error(
				'zs-sync-connections',
				'invalid_site_url',
				__( 'Invalid site URL.', 'zs-sync' ),
				'error'
			);
			return false;
		}
		return true;
	}

	/**
	 * Get a user-friendly error message for a given error code.
	 *
	 * @param string $error_code The error code to get a message for.
	 * @return string The error message.
	 */
	private function get_error_message( $error_code ) {
		switch ( $error_code ) {
			case 'app_pass_unavailable':
				return __( 'Application Passwords feature is not available on the remote site.', 'zs-sync' );
			case 'app_pass_failed':
				return __( 'Failed to generate application password on the remote site.', 'zs-sync' );
			default:
				return __( 'Unknown error occurred.', 'zs-sync' );
		}
	}
} 