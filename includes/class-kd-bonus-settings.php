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
	 * Default settings.
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
			'reward_name'                => 'Kamagra Dollar',
			'reward_symbol'              => '$KD',
			'base_currency'              => '',
			'email_notifications'        => 1,
			'upgrade_email_subject'      => 'Your KD Bonus membership status was upgraded',
			'upgrade_email_body'         => "Hi {customer_name},\n\nYour membership status is now {status_name}. Keep shopping to earn even more Kamagra Dollar rewards.\n",
			'reward_email_subject'       => 'You earned new Kamagra Dollar rewards',
			'reward_email_body'          => "Hi {customer_name},\n\nYou earned {reward_amount} {reward_symbol} from order #{order_number}. Your new balance is {balance_amount}.\n",
			'membership_statuses'        => array(
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
			),
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage KD Bonus settings.', 'kd-bonus' ) );
		}

		$settings = self::get_settings();
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
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Membership rebuild has started in background. You can keep this page open to monitor progress.', 'kd-bonus' ); ?></p></div>
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

		$settings = self::get_settings();

		switch ( $tab ) {
			case 'statuses':
				$settings['membership_statuses'] = $this->sanitize_membership_statuses( wp_unslash( $_POST['membership_statuses'] ?? array() ) );
				break;
			case 'email':
				$settings['email_notifications']   = ! empty( $_POST['email_notifications'] ) ? 1 : 0;
				$settings['upgrade_email_subject'] = sanitize_text_field( wp_unslash( $_POST['upgrade_email_subject'] ?? '' ) );
				$settings['upgrade_email_body']    = sanitize_textarea_field( wp_unslash( $_POST['upgrade_email_body'] ?? '' ) );
				$settings['reward_email_subject']  = sanitize_text_field( wp_unslash( $_POST['reward_email_subject'] ?? '' ) );
				$settings['reward_email_body']     = sanitize_textarea_field( wp_unslash( $_POST['reward_email_body'] ?? '' ) );
				break;
			case 'points':
				$settings['reward_name']   = sanitize_text_field( wp_unslash( $_POST['reward_name'] ?? '' ) );
				$settings['reward_symbol'] = sanitize_text_field( wp_unslash( $_POST['reward_symbol'] ?? '' ) );
				$settings['base_currency'] = strtoupper( sanitize_text_field( wp_unslash( $_POST['base_currency'] ?? '' ) ) );
				break;
			case 'general':
			default:
				$rebuild_requested = ! empty( $_POST['kd_bonus_rebuild_memberships'] );
				$settings['dashboard_page_slug']        = sanitize_title( wp_unslash( $_POST['dashboard_page_slug'] ?? 'kd-bonus-dashboard' ) );
				$settings['dashboard_page_slug']        = $settings['dashboard_page_slug'] ? $settings['dashboard_page_slug'] : 'kd-bonus-dashboard';
				$settings['auto_create_dashboard_page'] = ! empty( $_POST['auto_create_dashboard_page'] ) ? 1 : 0;
				$settings['checkout_redemption']        = ! empty( $_POST['checkout_redemption'] ) ? 1 : 0;
				$settings['award_order_status']         = $this->sanitize_award_order_status( wp_unslash( $_POST['award_order_status'] ?? '' ) );
				$settings['reward_expiry_days']         = max( 0, absint( wp_unslash( $_POST['reward_expiry_days'] ?? 0 ) ) );
				if ( $rebuild_requested ) {
					do_action( 'kd_bonus_request_membership_rebuild', get_current_user_id() );
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
			<th scope="row"><?php esc_html_e( 'Update Memberships from existing spends', 'kd-bonus' ); ?></th>
			<td>
				<button
					type="submit"
					name="kd_bonus_rebuild_memberships"
					value="1"
					class="button button-secondary"
					<?php disabled( $is_rebuilding ); ?>
					onclick="return window.confirm('<?php echo esc_js( __( 'This will reset all existing current membership and recalculate them from historical spend. This may take a long time.', 'kd-bonus' ) ); ?>');"
				><?php esc_html_e( 'Run Rebuild', 'kd-bonus' ); ?></button>
				<p class="description"><?php esc_html_e( 'Scans stored WooCommerce orders, recalculates customer lifetime eligible spend, and rebuilds membership statuses in background batches.', 'kd-bonus' ); ?></p>
				<?php if ( ! empty( $state_message ) ) : ?>
					<p><strong><?php echo esc_html( $state_message ); ?></strong></p>
				<?php endif; ?>
				<?php if ( ! empty( $rebuild_phase ) ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: rebuild phase, 2: reset users count, 3: total users, 4: processed orders, 5: total orders, 6: status rebuilt users, 7: total users. */
								__( 'Phase: %1$s | Reset users: %2$d/%3$d | Orders scanned: %4$d/%5$d | Statuses rebuilt: %6$d/%7$d', 'kd-bonus' ),
								$rebuild_phase,
								$reset_users,
								$total_users,
								$done_orders,
								$total_orders,
								$status_users,
								$total_users
							)
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( $is_rebuilding ) : ?>
					<script>
						setTimeout(function () {
							window.location.reload();
						}, 10000);
					</script>
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
				<p class="description"><?php esc_html_e( 'Add, edit, or delete as many statuses as needed. Rows are saved in ascending priority order, then renumbered starting from 1. Membership resolution still uses required spend first, then priority when thresholds match. Reward percentage is applied to eligible product subtotal only.', 'kd-bonus' ); ?></p>
				<table class="widefat striped" id="kd-bonus-status-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Priority (Higher Wins)', 'kd-bonus' ); ?></th>
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
			<td><label><input name="email_notifications" type="checkbox" value="1" <?php checked( ! empty( $settings['email_notifications'] ) ); ?> /> <?php esc_html_e( 'Send membership upgrade and reward issuance emails.', 'kd-bonus' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="upgrade_email_subject"><?php esc_html_e( 'Upgrade Email Subject', 'kd-bonus' ); ?></label></th>
			<td><input name="upgrade_email_subject" id="upgrade_email_subject" type="text" class="large-text" value="<?php echo esc_attr( $settings['upgrade_email_subject'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="upgrade_email_body"><?php esc_html_e( 'Upgrade Email Body', 'kd-bonus' ); ?></label></th>
			<td>
				<textarea name="upgrade_email_body" id="upgrade_email_body" class="large-text code" rows="6"><?php echo esc_textarea( $settings['upgrade_email_body'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Supported tokens: {customer_name}, {status_name}', 'kd-bonus' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_email_subject"><?php esc_html_e( 'Reward Email Subject', 'kd-bonus' ); ?></label></th>
			<td><input name="reward_email_subject" id="reward_email_subject" type="text" class="large-text" value="<?php echo esc_attr( $settings['reward_email_subject'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="reward_email_body"><?php esc_html_e( 'Reward Email Body', 'kd-bonus' ); ?></label></th>
			<td>
				<textarea name="reward_email_body" id="reward_email_body" class="large-text code" rows="6"><?php echo esc_textarea( $settings['reward_email_body'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Supported tokens: {customer_name}, {reward_amount}, {reward_symbol}, {order_number}, {balance_amount}', 'kd-bonus' ); ?></p>
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

		$table_name = KD_Bonus_Rewards::get_table_name();
		$events     = $wpdb->get_results(
			"SELECT id, user_id, site_id, order_id, type, amount, balance_after, currency, description, created_at
			FROM {$table_name}
			ORDER BY id DESC
			LIMIT 200"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<p><?php echo esc_html( sprintf( __( 'The reward event log keeps the latest %d events (older rows are pruned automatically). Showing newest 200 rows.', 'kd-bonus' ), KD_Bonus_Rewards::EVENT_LOG_LIMIT ) ); ?></p>
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
										? sprintf( '%1$s (#%2$d)', $user->user_login, (int) $event->user_id )
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
		<?php endif; ?>
		<?php
	}
}
