<?php
/**
 * Network settings management.
 *
 * @package KD_Bonus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KD_Bonus_Settings {
	/**
	 * Network option key.
	 */
	const OPTION_KEY = 'kd_bonus_network_settings';

	/**
	 * Network admin menu slug.
	 */
	const MENU_SLUG = 'kd-bonus';

	/**
	 * Network admin settings submenu slug.
	 */
	const SETTINGS_SUBMENU_SLUG = 'kd-bonus-settings';

	/**
	 * Register settings hooks.
	 */
	public function register() {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'network_admin_edit_kd_bonus_save_settings', array( $this, 'save_settings' ) );
		add_action( 'network_admin_edit_kd_bonus_continue_rebuild', array( $this, 'continue_rebuild' ) );
		add_action( 'network_admin_edit_kd_bonus_revoke_rebuild', array( $this, 'revoke_rebuild' ) );
	}

	/**
	 * Register plugin shortcodes.
	 *
	 * Registers [kd_bonus_membership_statuses_table] which renders the current
	 * Membership Statuses table. Admins can insert this shortcode into any email
	 * body from the Email Settings screen and it will be expanded before sending.
	 */
	public function register_shortcodes() {
		add_shortcode( 'kd_bonus_membership_statuses_table', array( $this, 'render_membership_statuses_table_shortcode' ) );
	}

	/**
	 * Shortcode callback: render the Membership Statuses table from saved settings.
	 *
	 * @return string HTML table.
	 */
	public function render_membership_statuses_table_shortcode() {
		$settings = self::get_settings();
		$statuses = is_array( $settings['membership_statuses'] ) ? $settings['membership_statuses'] : array();

		if ( empty( $statuses ) ) {
			return '';
		}

		$rows = '';
		foreach ( $statuses as $tier ) {
			$name    = isset( $tier['name'] ) ? $tier['name'] : '';
			$thresh  = isset( $tier['threshold'] ) ? $tier['threshold'] : 0;
			$percent = isset( $tier['reward_percent'] ) ? $tier['reward_percent'] : 0;
			$rows   .= '<tr>'
				. '<td style="background-color:#fff9c4;">' . esc_html( (string) $name ) . '</td>'
				. '<td style="background-color:#fff9c4;">' . esc_html( (string) $thresh ) . '</td>'
				. '<td style="background-color:#fff9c4;">' . esc_html( (string) $percent ) . '%</td>'
				. '</tr>';
		}

		return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;border-collapse:collapse;">'
			. '<tr><td align="center" valign="middle">'
			. '<table role="presentation" cellspacing="0" cellpadding="8" border="1" '
			. 'style="border-collapse:collapse;width:100%;max-width:600px;background-color:#fff9c4;">'
			. '<thead><tr>'
			. '<th style="text-align:left;background-color:#fff9c4;">' . esc_html__( 'Status', 'kd-bonus' ) . '</th>'
			. '<th style="text-align:left;background-color:#fff9c4;">' . esc_html__( 'Required products spent', 'kd-bonus' ) . '</th>'
			. '<th style="text-align:left;background-color:#fff9c4;">' . esc_html__( 'Reward percentage', 'kd-bonus' ) . '</th>'
			. '</tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table>'
			. '</td></tr>'
			. '</table>';
	}

	/**
	 * Add menu and settings submenu in Network Admin.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'KD Bonus', 'kd-bonus' ),
			__( 'KD Bonus', 'kd-bonus' ),
			'manage_network_options',
			self::MENU_SLUG,
			array( $this, 'render_menu_landing' ),
			'dashicons-awards',
			60
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'kd-bonus' ),
			__( 'Settings', 'kd-bonus' ),
			'manage_network_options',
			self::SETTINGS_SUBMENU_SLUG,
			array( $this, 'render_page' )
		);

		remove_submenu_page( self::MENU_SLUG, self::MENU_SLUG );
	}

	/**
	 * Render a top-level landing that forwards to the Settings submenu.
	 */
	public function render_menu_landing() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage KD Bonus settings.', 'kd-bonus' ) );
		}

		$settings_url = network_admin_url( 'admin.php?page=' . self::SETTINGS_SUBMENU_SLUG );
		?>
		<div class="wrap">
			<p><a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Continue to KD Bonus settings.', 'kd-bonus' ); ?></a></p>
		</div>
		<script>
			window.location.href = <?php echo wp_json_encode( $settings_url ); ?>;
		</script>
		<?php
	}

	/**
	 * Ensure settings exist.
	 */
	public static function ensure_defaults() {
		if ( false === get_network_option( null, self::OPTION_KEY, false ) ) {
			update_network_option( null, self::OPTION_KEY, self::defaults() );
		}
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings() {
		$settings = get_network_option( null, self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, self::defaults() );
	}

	/**
	 * Return the default membership status tiers.
	 *
	 * Kept as a separate method so it can be referenced by both {@see defaults()} and
	 * {@see get_membership_statuses_table_html()} without creating a circular dependency.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function default_membership_statuses() {
		return array(
			array(
				'priority'       => 10,
				'name'           => 'Bronze',
				'threshold'      => 0,
				'reward_percent' => 1,
			),
			array(
				'priority'       => 20,
				'name'           => 'Silver',
				'threshold'      => 500,
				'reward_percent' => 2.5,
			),
			array(
				'priority'       => 30,
				'name'           => 'Gold',
				'threshold'      => 1500,
				'reward_percent' => 5,
			),
		);
	}

	/**
	 * Return plugin default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'dashboard_page_slug'        => 'kd-bonus-dashboard',
			'auto_create_dashboard_page' => 1,
			'checkout_redemption'        => 1,
			'award_order_status'         => 'wc-processing',
			'reward_expiry_days'         => 0,
			'reward_expiry_notification_days' => 0,
			'reward_new_user'            => 0,
			'new_user_reward_amount'     => 0,
			'reward_name'                => 'Kamagra Dollar',
			'reward_symbol'              => '$KD',
			'base_currency'              => '',
			'email_notifications'        => 1,
			'upgrade_email_subject'      => 'Your KD Bonus membership status was upgraded',
			'upgrade_email_body'         => "<p>Hi {customer_name},</p>\n<p>Your membership status is now {status_name}. Keep shopping to earn even more Kamagra Dollar rewards.</p>\n[kd_bonus_membership_statuses_table]",
			'reward_email_subject'       => 'You earned new Kamagra Dollar rewards',
			'reward_email_body'          => "<p>Hi {customer_name},</p>\n<p>You earned {reward_amount} {reward_symbol} from order #{order_number}. Your new balance is {balance_amount}.</p>\n[kd_bonus_membership_statuses_table]",
			'reward_expiry_notification_email_subject' => 'Your KD Bonus rewards expire soon',
			'reward_expiry_notification_email_body'    => "<p>Hi {customer_name},</p>\n<p>Your current KD Bonus balance of {balance_amount} will expire on {expiry_date}.</p>\n<p>Please use your rewards within the next {days_until_expiry} day(s).</p>\n[kd_bonus_membership_statuses_table]",
			'new_user_reward_email_subject' => 'Welcome! Your new account reward is ready',
			'new_user_reward_email_body'    => "<p>Hi {customer_name},</p>\n<p>Welcome! You received {reward_amount} {reward_symbol} as a new account reward. Your balance is now {balance_amount}.</p>\n[kd_bonus_membership_statuses_table]",
			'membership_statuses'        => self::default_membership_statuses(),
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage KD Bonus settings.', 'kd-bonus' ) );
		}

		$settings          = self::get_settings();
		$rebuild_requested = false;
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs     = array(
			'general'  => __( 'General Settings', 'kd-bonus' ),
			'statuses' => __( 'Membership Statuses', 'kd-bonus' ),
			'email'    => __( 'Email Settings', 'kd-bonus' ),
			'points'   => __( 'Points & Reward Settings', 'kd-bonus' ),
			'events'   => __( 'Reward Event Log', 'kd-bonus' ),
		);

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KD Bonus Network Settings', 'kd-bonus' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $label ) : ?>
					<a class="nav-tab <?php echo esc_attr( $tab_key === $tab ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . self::SETTINGS_SUBMENU_SLUG . '&tab=' . $tab_key ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'KD Bonus settings updated.', 'kd-bonus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rebuild'] ) && 'started' === sanitize_key( wp_unslash( $_GET['rebuild'] ) ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Membership rebuild has started. Click "Continue rebuild" to process one batch at a time.', 'kd-bonus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rebuild'] ) && 'continued' === sanitize_key( wp_unslash( $_GET['rebuild'] ) ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Processed one rebuild batch. Click "Continue rebuild" again to process the next batch.', 'kd-bonus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rebuild'] ) && 'completed' === sanitize_key( wp_unslash( $_GET['rebuild'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Membership rebuild is already complete. Start a new rebuild to run it again.', 'kd-bonus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rebuild'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['rebuild'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Membership rebuild failed. Review the rebuild message below and start a new rebuild if needed.', 'kd-bonus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['revoke'] ) && 'done' === sanitize_key( wp_unslash( $_GET['revoke'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Membership rebuild has been cancelled and all rebuild state has been cleared. You can now start a new rebuild.', 'kd-bonus' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'events' === $tab ) : ?>
				<?php $this->render_event_log_tab(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=kd_bonus_save_settings' ) ); ?>">
					<?php wp_nonce_field( 'kd_bonus_save_settings_' . $tab ); ?>
					<input type="hidden" name="kd_bonus_tab" value="<?php echo esc_attr( $tab ); ?>" />
					<table class="form-table" role="presentation">
						<tbody>
							<?php $this->render_fields( $tab, $settings ); ?>
						</tbody>
					</table>
					<?php submit_button( __( 'Save Settings', 'kd-bonus' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save network settings.
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage KD Bonus settings.', 'kd-bonus' ) );
		}

		$tab = isset( $_POST['kd_bonus_tab'] ) ? sanitize_key( wp_unslash( $_POST['kd_bonus_tab'] ) ) : 'general';
		check_admin_referer( 'kd_bonus_save_settings_' . $tab );

		$settings          = self::get_settings();
		$rebuild_requested = false;

		switch ( $tab ) {
			case 'statuses':
				$settings['membership_statuses'] = $this->sanitize_membership_statuses( wp_unslash( $_POST['membership_statuses'] ?? array() ) );
				break;
			case 'email':
				$settings['email_notifications']   = ! empty( $_POST['email_notifications'] ) ? 1 : 0;
				$settings['upgrade_email_subject'] = sanitize_text_field( wp_unslash( $_POST['upgrade_email_subject'] ?? '' ) );
				$settings['upgrade_email_body']    = wp_kses_post( wp_unslash( $_POST['upgrade_email_body'] ?? '' ) );
				$settings['reward_email_subject']  = sanitize_text_field( wp_unslash( $_POST['reward_email_subject'] ?? '' ) );
				$settings['reward_email_body']     = wp_kses_post( wp_unslash( $_POST['reward_email_body'] ?? '' ) );
				$settings['reward_expiry_notification_email_subject'] = sanitize_text_field( wp_unslash( $_POST['reward_expiry_notification_email_subject'] ?? '' ) );
				$settings['reward_expiry_notification_email_body']    = wp_kses_post( wp_unslash( $_POST['reward_expiry_notification_email_body'] ?? '' ) );
				$settings['new_user_reward_email_subject'] = sanitize_text_field( wp_unslash( $_POST['new_user_reward_email_subject'] ?? '' ) );
				$settings['new_user_reward_email_body']    = wp_kses_post( wp_unslash( $_POST['new_user_reward_email_body'] ?? '' ) );
				break;
			case 'points':
				$settings['reward_name']   = sanitize_text_field( wp_unslash( $_POST['reward_name'] ?? '' ) );
				$settings['reward_symbol'] = sanitize_text_field( wp_unslash( $_POST['reward_symbol'] ?? '' ) );
				$settings['base_currency'] = strtoupper( sanitize_text_field( wp_unslash( $_POST['base_currency'] ?? '' ) ) );
				break;
			case 'general':
			default:
				$rebuild_requested = ! empty( $_POST['kd_bonus_rebuild_memberships'] );
				$reset_enabled     = ! empty( $_POST['kd_bonus_rebuild_reset_users'] ) ? 1 : 0;
				$settings['dashboard_page_slug']        = sanitize_title( wp_unslash( $_POST['dashboard_page_slug'] ?? 'kd-bonus-dashboard' ) );
				$settings['dashboard_page_slug']        = $settings['dashboard_page_slug'] ? $settings['dashboard_page_slug'] : 'kd-bonus-dashboard';
				$settings['auto_create_dashboard_page'] = ! empty( $_POST['auto_create_dashboard_page'] ) ? 1 : 0;
				$settings['checkout_redemption']        = ! empty( $_POST['checkout_redemption'] ) ? 1 : 0;
				$settings['award_order_status']         = $this->sanitize_award_order_status( wp_unslash( $_POST['award_order_status'] ?? '' ) );
				$settings['reward_expiry_days']         = max( 0, absint( wp_unslash( $_POST['reward_expiry_days'] ?? 0 ) ) );
				$settings['reward_expiry_notification_days'] = max( 0, absint( wp_unslash( $_POST['reward_expiry_notification_days'] ?? 0 ) ) );
				$settings['reward_new_user']            = ! empty( $_POST['reward_new_user'] ) ? 1 : 0;
				$settings['new_user_reward_amount']     = max( 0, (float) wp_unslash( $_POST['new_user_reward_amount'] ?? 0 ) );
				if ( $rebuild_requested ) {
					do_action( 'kd_bonus_request_membership_rebuild', get_current_user_id(), $reset_enabled );
				}
				break;
		}

		update_network_option( null, self::OPTION_KEY, $settings );

		if ( 'general' === $tab && ! empty( $settings['auto_create_dashboard_page'] ) ) {
			KD_Bonus_Plugin::ensure_dashboard_pages_for_all_sites();
		}

		$redirect_args = array(
			'page'    => self::SETTINGS_SUBMENU_SLUG,
			'tab'     => $tab,
			'updated' => 1,
		);

		if ( $rebuild_requested ) {
			$redirect_args['rebuild'] = 'started';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, network_admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Cancel and clean up a running or stalled membership rebuild.
	 */
	public function revoke_rebuild() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage KD Bonus settings.', 'kd-bonus' ) );
		}

		check_admin_referer( 'kd_bonus_revoke_rebuild' );

		if ( class_exists( 'KD_Bonus_Rewards' ) ) {
			do_action( 'kd_bonus_revoke_membership_rebuild' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SETTINGS_SUBMENU_SLUG,
					'tab'    => 'general',
					'revoke' => 'done',
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Process one explicit rebuild batch from Network Admin.
	 */
	public function continue_rebuild() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage KD Bonus settings.', 'kd-bonus' ) );
		}

		check_admin_referer( 'kd_bonus_continue_rebuild' );

		if ( class_exists( 'KD_Bonus_Rewards' ) ) {
			do_action( 'kd_bonus_continue_membership_rebuild' );
		}

		$rebuild_state = class_exists( 'KD_Bonus_Rewards' ) ? KD_Bonus_Rewards::get_membership_rebuild_state() : array();
		$is_running    = ! empty( $rebuild_state['running'] );
		$is_failed     = ! $is_running && ( ( isset( $rebuild_state['status'] ) && 'failed' === sanitize_key( (string) $rebuild_state['status'] ) ) || ( isset( $rebuild_state['phase'] ) && 'failed' === sanitize_key( (string) $rebuild_state['phase'] ) ) );
		$rebuild_query = 'completed';
		if ( $is_running ) {
			$rebuild_query = 'continued';
		} elseif ( $is_failed ) {
			$rebuild_query = 'failed';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SETTINGS_SUBMENU_SLUG,
					'tab'     => 'general',
					'rebuild' => $rebuild_query,
				),
				network_admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render tab fields.
	 *
	 * @param string               $tab Current tab.
	 * @param array<string,mixed>  $settings Saved settings.
	 */
	private function render_fields( $tab, $settings ) {
		switch ( $tab ) {
			case 'statuses':
				$this->render_membership_status_rows( $settings );
				break;
			case 'email':
				$this->render_email_fields( $settings );
				break;
			case 'points':
				$this->render_points_fields( $settings );
				break;
			case 'general':
			default:
				$this->render_general_fields( $settings );
				break;
		}
	}

	/**
	 * Render general fields.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 */
	private function render_general_fields( $settings ) {
		$rebuild_state = class_exists( 'KD_Bonus_Rewards' ) ? KD_Bonus_Rewards::get_membership_rebuild_state() : array();
		$is_rebuilding = ! empty( $rebuild_state['running'] );
		$rebuild_phase = isset( $rebuild_state['phase'] ) ? (string) $rebuild_state['phase'] : '';
		$total_orders  = isset( $rebuild_state['total_orders'] ) ? (int) $rebuild_state['total_orders'] : 0;
		$done_orders   = isset( $rebuild_state['processed_orders'] ) ? (int) $rebuild_state['processed_orders'] : 0;
		$total_users   = isset( $rebuild_state['total_users'] ) ? (int) $rebuild_state['total_users'] : 0;
		$reset_users   = isset( $rebuild_state['user_reset_processed'] ) ? (int) $rebuild_state['user_reset_processed'] : 0;
		$status_users  = isset( $rebuild_state['status_rebuild_processed'] ) ? (int) $rebuild_state['status_rebuild_processed'] : 0;
		$state_message = isset( $rebuild_state['message'] ) ? (string) $rebuild_state['message'] : '';
		$recent_logs   = isset( $rebuild_state['recent_logs'] ) && is_array( $rebuild_state['recent_logs'] ) ? array_values( array_map( 'sanitize_text_field', $rebuild_state['recent_logs'] ) ) : array();
		$is_completed  = ! $is_rebuilding && 'completed' === $rebuild_phase;
		?>
		<tr>
			<th scope="row"><label for="dashboard_page_slug"><?php esc_html_e( 'Dashboard Page Slug', 'kd-bonus' ); ?></label></th>
			<td>
				<input name="dashboard_page_slug" id="dashboard_page_slug" type="text" class="regular-text" value="<?php echo esc_attr( $settings['dashboard_page_slug'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Used when automatically creating the customer dashboard page on each site.', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Auto-create Dashboard Page', 'kd-bonus' ); ?></th>
			<td><label><input name="auto_create_dashboard_page" type="checkbox" value="1" <?php checked( ! empty( $settings['auto_create_dashboard_page'] ) ); ?> /> <?php esc_html_e( 'Create a frontend page containing the [kd_bonus_dashboard] shortcode for each site.', 'kd-bonus' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Checkout Redemption', 'kd-bonus' ); ?></th>
			<td><label><input name="checkout_redemption" type="checkbox" value="1" <?php checked( ! empty( $settings['checkout_redemption'] ) ); ?> /> <?php esc_html_e( 'Allow customers to apply part or all of their available balance during WooCommerce checkout.', 'kd-bonus' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="award_order_status"><?php esc_html_e( 'Reward Awarding Status', 'kd-bonus' ); ?></label></th>
			<td>
				<select name="award_order_status" id="award_order_status">
					<?php foreach ( $this->get_available_order_statuses() as $status_key => $status_label ) : ?>
						<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $settings['award_order_status'], $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Reward points are awarded automatically when an order reaches the selected WooCommerce status.', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_expiry_days"><?php esc_html_e( 'Reward Expiry (Days)', 'kd-bonus' ); ?></label></th>
			<td>
				<input name="reward_expiry_days" id="reward_expiry_days" type="number" class="small-text" min="0" step="1" value="<?php echo esc_attr( (int) $settings['reward_expiry_days'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Set to 0 to disable expiry. Any unused KD Bonus balance expires this many days after the customer last received reward points.', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_expiry_notification_days"><?php esc_html_e( 'Reward Expiry Reminder (Days Before)', 'kd-bonus' ); ?></label></th>
			<td>
				<input name="reward_expiry_notification_days" id="reward_expiry_notification_days" type="number" class="small-text" min="0" step="1" value="<?php echo esc_attr( (int) $settings['reward_expiry_notification_days'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Set to 0 to disable reminder emails. Customers are notified once when their reward expiry date falls within this many days.', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Reward new user', 'kd-bonus' ); ?></th>
			<td>
				<label><input name="reward_new_user" type="checkbox" value="1" <?php checked( ! empty( $settings['reward_new_user'] ) ); ?> /> <?php esc_html_e( 'Automatically grant a reward when a new user account is created.', 'kd-bonus' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="new_user_reward_amount"><?php esc_html_e( 'New User Reward Amount', 'kd-bonus' ); ?></label></th>
			<td>
				<input name="new_user_reward_amount" id="new_user_reward_amount" type="number" class="small-text" min="0" step="0.01" value="<?php echo esc_attr( (float) $settings['new_user_reward_amount'] ); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Update Memberships from existing spends', 'kd-bonus' ); ?></th>
			<td>
				<label>
					<input name="kd_bonus_rebuild_reset_users" type="checkbox" value="1" checked />
					<?php esc_html_e( 'Reset existing Bonus Status values before rebuild', 'kd-bonus' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'When checked, all stored Bonus Status values are cleared before recalculating. Uncheck to skip the reset phase and go directly to order scanning (safe when no status data exists).', 'kd-bonus' ); ?></p>
				<br />
				<button
					type="submit"
					name="kd_bonus_rebuild_memberships"
					value="1"
					class="button button-secondary"
					<?php disabled( $is_rebuilding ); ?>
					onclick="return window.confirm('<?php echo esc_js( __( 'This will recalculate all membership statuses from historical spend. If the reset checkbox is checked, existing status values will be cleared first. This may take a long time.', 'kd-bonus' ) ); ?>');"
				><?php esc_html_e( 'Run Rebuild', 'kd-bonus' ); ?></button>
				<p class="description"><?php esc_html_e( 'Initializes a resumable rebuild state. Then use "Continue rebuild" to process one small batch per click until complete.', 'kd-bonus' ); ?></p>
				<?php if ( $is_rebuilding ) : ?>
					<p>
						<a
							href="<?php echo esc_url( wp_nonce_url( network_admin_url( 'edit.php?action=kd_bonus_continue_rebuild' ), 'kd_bonus_continue_rebuild' ) ); ?>"
							class="button button-secondary"
						><?php esc_html_e( 'Continue rebuild', 'kd-bonus' ); ?></a>
					</p>
				<?php elseif ( $is_completed ) : ?>
					<p>
						<button type="button" class="button button-secondary" disabled><?php esc_html_e( 'Continue rebuild (completed)', 'kd-bonus' ); ?></button>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $state_message ) ) : ?>
					<p><strong><?php echo esc_html( $state_message ); ?></strong></p>
				<?php endif; ?>
				<?php if ( ! empty( $rebuild_phase ) ) : ?>
					<p id="kd-bonus-rebuild-summary">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: rebuild phase, 2: reset users label (e.g. "skipped" or "N/M"), 3: processed orders, 4: total orders, 5: status rebuilt users, 6: total users. */
								__( 'Phase: %1$s | Reset users: %2$s | Orders scanned: %3$d/%4$d | Statuses rebuilt: %5$d/%6$d', 'kd-bonus' ),
								$rebuild_phase,
								! empty( $rebuild_state['reset_skipped'] ) ? __( 'skipped', 'kd-bonus' ) : sprintf( '%d/%d', $reset_users, $total_users ),
								$done_orders,
								$total_orders,
								$status_users,
								$total_users
							)
						);
						?>
					</p>
				<?php endif; ?>
				<div id="kd-bonus-rebuild-progress-log-wrap">
					<p><strong><?php esc_html_e( 'Live Bonus Status updates', 'kd-bonus' ); ?></strong></p>
					<div id="kd-bonus-rebuild-progress-log" style="max-height:180px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:8px;">
						<?php if ( ! empty( $recent_logs ) ) : ?>
							<?php foreach ( $recent_logs as $recent_log ) : ?>
								<div><?php echo esc_html( $recent_log ); ?></div>
							<?php endforeach; ?>
						<?php else : ?>
							<div><?php esc_html_e( 'No per-user updates yet.', 'kd-bonus' ); ?></div>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( $is_rebuilding ) : ?>
					<p>
						<a href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . self::SETTINGS_SUBMENU_SLUG . '&tab=general' ) ); ?>" class="button button-link"><?php esc_html_e( 'Refresh progress', 'kd-bonus' ); ?></a>
						&nbsp;
						<a
							href="<?php echo esc_url( wp_nonce_url( network_admin_url( 'edit.php?action=kd_bonus_revoke_rebuild' ), 'kd_bonus_revoke_rebuild' ) ); ?>"
							class="button button-link-delete"
							onclick="return window.confirm('<?php echo esc_js( __( 'This will cancel the running rebuild and clear all rebuild state. The Run Rebuild button will become active again. Are you sure?', 'kd-bonus' ) ); ?>');"
						><?php esc_html_e( 'Revoke Rebuild', 'kd-bonus' ); ?></a>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render membership settings fields.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 */
	private function render_membership_status_rows( $settings ) {
		$rows = is_array( $settings['membership_statuses'] ) ? $settings['membership_statuses'] : array();
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Membership Status Rules', 'kd-bonus' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'Add, edit, or delete as many statuses as needed. Rows are saved in ascending priority order (1 = first), then renumbered starting from 1. When thresholds match, the row with the lower priority number wins. Reward percentage is applied to eligible product subtotal only.', 'kd-bonus' ); ?></p>
				<table class="widefat striped" id="kd-bonus-status-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Priority', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Status Name', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Required Product Spend', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Reward Percentage', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'kd-bonus' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $index => $row ) : ?>
							<?php $this->render_membership_status_row( $index, $row ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<template id="kd-bonus-status-row-template">
					<?php $this->render_membership_status_row( '__INDEX__', array() ); ?>
				</template>
				<p><button type="button" class="button" id="kd-bonus-add-status"><?php esc_html_e( 'Add Membership Status', 'kd-bonus' ); ?></button></p>
				<script>
					(function () {
						const table = document.getElementById('kd-bonus-status-table');
						const button = document.getElementById('kd-bonus-add-status');
						const template = document.getElementById('kd-bonus-status-row-template');
						if (!table || !button || !template) {
							return;
						}
						const tbody = table.querySelector('tbody');
						let nextIndex = tbody ? tbody.querySelectorAll('tr').length : 0;
						button.addEventListener('click', function () {
							tbody.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(nextIndex)));
							nextIndex += 1;
						});
						table.addEventListener('click', function (event) {
							if (!event.target.classList.contains('kd-bonus-remove-status')) {
								return;
							}
							const row = event.target.closest('tr');
							if (row) {
								row.remove();
							}
						});
					}());
				</script>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render one membership status row.
	 *
	 * @param int|string          $index Row index.
	 * @param array<string,mixed> $row Row data.
	 */
	private function render_membership_status_row( $index, $row ) {
		?>
		<tr>
			<td><input type="number" step="1" min="0" name="membership_statuses[<?php echo esc_attr( $index ); ?>][priority]" value="<?php echo esc_attr( $row['priority'] ?? 0 ); ?>" /></td>
			<td><input type="text" class="regular-text" name="membership_statuses[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $row['name'] ?? '' ); ?>" /></td>
			<td><input type="number" step="0.01" min="0" name="membership_statuses[<?php echo esc_attr( $index ); ?>][threshold]" value="<?php echo esc_attr( $row['threshold'] ?? 0 ); ?>" /></td>
			<td><input type="number" step="0.01" min="0" name="membership_statuses[<?php echo esc_attr( $index ); ?>][reward_percent]" value="<?php echo esc_attr( $row['reward_percent'] ?? 0 ); ?>" /></td>
			<td><button type="button" class="button-link-delete kd-bonus-remove-status"><?php esc_html_e( 'Delete', 'kd-bonus' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Render email fields.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 */
	private function render_email_fields( $settings ) {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Email Notifications', 'kd-bonus' ); ?></th>
			<td><label><input name="email_notifications" type="checkbox" value="1" <?php checked( ! empty( $settings['email_notifications'] ) ); ?> /> <?php esc_html_e( 'Send membership upgrade, reward issuance, new account reward, and reward expiry reminder emails.', 'kd-bonus' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="upgrade_email_subject"><?php esc_html_e( 'Upgrade Email Subject', 'kd-bonus' ); ?></label></th>
			<td><input name="upgrade_email_subject" id="upgrade_email_subject" type="text" class="large-text" value="<?php echo esc_attr( $settings['upgrade_email_subject'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="upgrade_email_body"><?php esc_html_e( 'Upgrade Email Body', 'kd-bonus' ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$settings['upgrade_email_body'],
					'kd_bonus_upgrade_email_body',
					array(
						'textarea_name' => 'upgrade_email_body',
						'textarea_rows' => 8,
						'media_buttons' => false,
						'teeny'         => true,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Supported tokens: {customer_name}, {status_name}', 'kd-bonus' ); ?> &mdash; <?php esc_html_e( 'Shortcode: [kd_bonus_membership_statuses_table]', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_email_subject"><?php esc_html_e( 'Reward Email Subject', 'kd-bonus' ); ?></label></th>
			<td><input name="reward_email_subject" id="reward_email_subject" type="text" class="large-text" value="<?php echo esc_attr( $settings['reward_email_subject'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_email_body"><?php esc_html_e( 'Reward Email Body', 'kd-bonus' ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$settings['reward_email_body'],
					'kd_bonus_reward_email_body',
					array(
						'textarea_name' => 'reward_email_body',
						'textarea_rows' => 8,
						'media_buttons' => false,
						'teeny'         => true,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Supported tokens: {customer_name}, {reward_amount}, {reward_symbol}, {order_number}, {balance_amount}', 'kd-bonus' ); ?> &mdash; <?php esc_html_e( 'Shortcode: [kd_bonus_membership_statuses_table]', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_expiry_notification_email_subject"><?php esc_html_e( 'Reward Expiry Reminder Email Subject', 'kd-bonus' ); ?></label></th>
			<td><input name="reward_expiry_notification_email_subject" id="reward_expiry_notification_email_subject" type="text" class="large-text" value="<?php echo esc_attr( $settings['reward_expiry_notification_email_subject'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_expiry_notification_email_body"><?php esc_html_e( 'Reward Expiry Reminder Email Body', 'kd-bonus' ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$settings['reward_expiry_notification_email_body'],
					'kd_bonus_reward_expiry_notification_email_body',
					array(
						'textarea_name' => 'reward_expiry_notification_email_body',
						'textarea_rows' => 8,
						'media_buttons' => false,
						'teeny'         => true,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Supported tokens: {customer_name}, {balance_amount}, {reward_symbol}, {expiry_date}, {days_until_expiry}', 'kd-bonus' ); ?> &mdash; <?php esc_html_e( 'Shortcode: [kd_bonus_membership_statuses_table]', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="new_user_reward_email_subject"><?php esc_html_e( 'New Account Reward Email Subject', 'kd-bonus' ); ?></label></th>
			<td><input name="new_user_reward_email_subject" id="new_user_reward_email_subject" type="text" class="large-text" value="<?php echo esc_attr( $settings['new_user_reward_email_subject'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="new_user_reward_email_body"><?php esc_html_e( 'New Account Reward Email Body', 'kd-bonus' ); ?></label></th>
			<td>
				<?php
				wp_editor(
					$settings['new_user_reward_email_body'],
					'kd_bonus_new_user_reward_email_body',
					array(
						'textarea_name' => 'new_user_reward_email_body',
						'textarea_rows' => 8,
						'media_buttons' => false,
						'teeny'         => true,
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Supported tokens: {customer_name}, {reward_amount}, {reward_symbol}, {balance_amount}', 'kd-bonus' ); ?> &mdash; <?php esc_html_e( 'Shortcode: [kd_bonus_membership_statuses_table]', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render points fields.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 */
	private function render_points_fields( $settings ) {
		?>
		<tr>
			<th scope="row"><label for="reward_name"><?php esc_html_e( 'Reward Name', 'kd-bonus' ); ?></label></th>
			<td><input name="reward_name" id="reward_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['reward_name'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_symbol"><?php esc_html_e( 'Reward Symbol', 'kd-bonus' ); ?></label></th>
			<td><input name="reward_symbol" id="reward_symbol" type="text" class="regular-text" value="<?php echo esc_attr( $settings['reward_symbol'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="base_currency"><?php esc_html_e( 'Base Currency', 'kd-bonus' ); ?></label></th>
			<td>
				<input name="base_currency" id="base_currency" type="text" class="regular-text" value="<?php echo esc_attr( $settings['base_currency'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Leave blank to use the active WooCommerce store currency as the 1 point = 1 currency base.', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitize membership status rows.
	 *
	 * @param array<int|string,mixed> $rows Raw rows.
	 * @return array<int,array<string,float|string>>
	 */
	private function sanitize_membership_statuses( $rows ) {
		$sanitized = array();

		if ( ! is_array( $rows ) ) {
			return self::defaults()['membership_statuses'];
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = sanitize_text_field( $row['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$sanitized[] = array(
				'priority'       => max( 0, (int) ( $row['priority'] ?? 0 ) ),
				'name'           => $name,
				'threshold'      => max( 0, (float) ( $row['threshold'] ?? 0 ) ),
				'reward_percent' => max( 0, (float) ( $row['reward_percent'] ?? 0 ) ),
			);
		}

		if ( empty( $sanitized ) ) {
			return self::defaults()['membership_statuses'];
		}

		usort(
			$sanitized,
			static function ( $left, $right ) {
				$priority_compare = $left['priority'] <=> $right['priority'];

				if ( 0 !== $priority_compare ) {
					return $priority_compare;
				}

				return $left['threshold'] <=> $right['threshold'];
			}
		);

		foreach ( $sanitized as $index => $row ) {
			$sanitized[ $index ]['priority'] = $index + 1;
		}

		return array_values( $sanitized );
	}

	/**
	 * Get available order statuses for the General Settings dropdown.
	 *
	 * @return array<string,string>
	 */
	private function get_available_order_statuses() {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$statuses = wc_get_order_statuses();
			if ( ! empty( $statuses ) && is_array( $statuses ) ) {
				return $statuses;
			}
		}

		return array(
			'wc-processing' => __( 'Processing', 'kd-bonus' ),
			'wc-completed'  => __( 'Completed', 'kd-bonus' ),
		);
	}

	/**
	 * Sanitize the selected awarding status.
	 *
	 * @param string $status Raw status key.
	 * @return string
	 */
	private function sanitize_award_order_status( $status ) {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return 'wc-processing';
		}

		$statuses = $this->get_available_order_statuses();

		return isset( $statuses[ $status ] ) ? $status : 'wc-processing';
	}

	/**
	 * Render the read-only reward event log tab.
	 */
	private function render_event_log_tab() {
		global $wpdb;

		$table_name       = KD_Bonus_Rewards::get_table_name();
		$allowed_per_page = array( 10, 20, 40, 80, 160, 300, 500 );
		$per_page         = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 300; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $per_page, $allowed_per_page, true ) ) {
			$per_page = 300;
		}
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$offset      = ( $page - 1 ) * $per_page;
		$events      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, site_id, order_id, type, amount, balance_after, currency, description, created_at
				FROM {$table_name}
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<p><?php echo esc_html( sprintf( __( 'The reward event log keeps the latest %d events (older rows are pruned automatically). Showing %d per page.', 'kd-bonus' ), KD_Bonus_Rewards::MAX_EVENT_LOG_ROWS, $per_page ) ); ?></p>

		<form method="get" style="margin: 12px 0 16px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
			<label for="kd-bonus-per-page-tab"><?php esc_html_e( 'Logs per page:', 'kd-bonus' ); ?></label>
			<select id="kd-bonus-per-page-tab" name="per_page" onchange="this.form.submit()">
				<?php foreach ( $allowed_per_page as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $per_page, $option ); ?>>
						<?php echo esc_html( number_format_i18n( $option ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<input type="hidden" name="paged" value="1">
		</form>

		<?php if ( empty( $events ) ) : ?>
			<p><?php esc_html_e( 'No reward events recorded yet.', 'kd-bonus' ); ?></p>
		<?php else : ?>
			<div style="max-height:640px;overflow:auto;border:1px solid #ccd0d4;background:#fff;">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Date', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'User', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Site', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Order', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Type', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Balance After', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Currency', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Details', 'kd-bonus' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<?php $user = get_userdata( (int) $event->user_id ); ?>
							<tr>
								<td><?php echo esc_html( (string) $event->id ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->created_at . ' UTC' ) ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										$user
										? sprintf( '%1$s (%2$s)', $user->user_login, $user->user_email )
										: sprintf( __( 'User #%d', 'kd-bonus' ), (int) $event->user_id )
									);
									?>
								</td>
								<td><?php echo esc_html( (string) $event->site_id ); ?></td>
								<td><?php echo esc_html( (string) $event->order_id ); ?></td>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $event->type ) ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $event->amount, 2 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $event->balance_after, 2 ) ); ?></td>
								<td><?php echo esc_html( $event->currency ); ?></td>
								<td><?php echo esc_html( $event->description ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages" style="margin: 16px 0;">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( array( 'per_page' => $per_page, 'paged' => '%#%' ) ),
									'format'    => '',
									'current'   => $page,
									'total'     => $total_pages,
									'prev_text' => __( '&laquo;', 'kd-bonus' ),
									'next_text' => __( '&raquo;', 'kd-bonus' ),
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Return the default HTML for the "Membership Statuses" table used in email templates.
	 *
	 * The table is vertically centred and uses a light-yellow background so it stands
	 * out in the email body. It is injected as a default only; admins can edit or remove
	 * it freely via the GUI (wp_editor) on the Email Settings screen.
	 *
	 * @return string
	 */
	public static function get_membership_statuses_table_html() {
		$rows = '';
		foreach ( self::default_membership_statuses() as $tier ) {
			$name    = isset( $tier['name'] ) ? $tier['name'] : '';
			$thresh  = isset( $tier['threshold'] ) ? $tier['threshold'] : 0;
			$percent = isset( $tier['reward_percent'] ) ? $tier['reward_percent'] : 0;
			$rows   .= '<tr>'
				. '<td style="background-color:#fff9c4;">' . esc_html( (string) $name ) . '</td>'
				. '<td style="background-color:#fff9c4;">' . esc_html( (string) $thresh ) . '</td>'
				. '<td style="background-color:#fff9c4;">' . esc_html( (string) $percent ) . '%</td>'
				. '</tr>';
		}

		return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;border-collapse:collapse;">'
			. '<tr><td align="center" valign="middle">'
			. '<table role="presentation" cellspacing="0" cellpadding="8" border="1" '
			. 'style="border-collapse:collapse;width:100%;max-width:600px;background-color:#fff9c4;">'
			. '<thead><tr>'
			. '<th style="text-align:left;background-color:#fff9c4;">Status</th>'
			. '<th style="text-align:left;background-color:#fff9c4;">Required products spent</th>'
			. '<th style="text-align:left;background-color:#fff9c4;">Reward percentage</th>'
			. '</tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '</table>'
			. '</td></tr>'
			. '</table>';
	}
}
