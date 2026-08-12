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
	}

	/**
	 * Redirect top-level menu requests to the Settings submenu page.
	 */
	public function render_menu_landing() {
		wp_safe_redirect( network_admin_url( 'admin.php?page=' . self::SETTINGS_SUBMENU_SLUG ) );
		exit;
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
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs     = array(
			'general'  => __( 'General Settings', 'kd-bonus' ),
			'statuses' => __( 'Membership Statuses', 'kd-bonus' ),
			'email'    => __( 'Email Settings', 'kd-bonus' ),
			'points'   => __( 'Points & Reward Settings', 'kd-bonus' ),
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
				$settings['dashboard_page_slug']        = sanitize_title( wp_unslash( $_POST['dashboard_page_slug'] ?? 'kd-bonus-dashboard' ) );
				$settings['dashboard_page_slug']        = $settings['dashboard_page_slug'] ? $settings['dashboard_page_slug'] : 'kd-bonus-dashboard';
				$settings['auto_create_dashboard_page'] = ! empty( $_POST['auto_create_dashboard_page'] ) ? 1 : 0;
				$settings['checkout_redemption']        = ! empty( $_POST['checkout_redemption'] ) ? 1 : 0;
				$settings['award_order_status']         = $this->sanitize_award_order_status( wp_unslash( $_POST['award_order_status'] ?? '' ) );
				$settings['reward_expiry_days']         = max( 0, absint( wp_unslash( $_POST['reward_expiry_days'] ?? 0 ) ) );
				break;
		}

		update_network_option( null, self::OPTION_KEY, $settings );

		if ( 'general' === $tab && ! empty( $settings['auto_create_dashboard_page'] ) ) {
			KD_Bonus_Plugin::ensure_dashboard_pages_for_all_sites();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SETTINGS_SUBMENU_SLUG,
					'tab'     => $tab,
					'updated' => 1,
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
				<p class="description"><?php esc_html_e( 'Add, edit, or delete as many statuses as needed. Rows are evaluated by required spend first, then by priority when multiple statuses share the same threshold. Higher priority numbers win ties. Reward percentage is applied to eligible product subtotal only.', 'kd-bonus' ); ?></p>
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
				$threshold_compare = $left['threshold'] <=> $right['threshold'];

				if ( 0 !== $threshold_compare ) {
					return $threshold_compare;
				}

				return $left['priority'] <=> $right['priority'];
			}
		);

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
}
