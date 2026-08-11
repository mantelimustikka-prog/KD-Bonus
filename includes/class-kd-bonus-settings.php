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
	 * Add submenu under Network Settings.
	 */
	public function register_menu() {
		add_submenu_page(
			'settings.php',
			__( 'KD Bonus', 'kd-bonus' ),
			__( 'KD Bonus', 'kd-bonus' ),
			'manage_network_options',
			'kd-bonus',
			array( $this, 'render_page' )
		);
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
					'name'           => 'Bronze',
					'threshold'      => 0,
					'reward_percent' => 1,
				),
				array(
					'name'           => 'Silver',
					'threshold'      => 500,
					'reward_percent' => 2.5,
				),
				array(
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
					<a class="nav-tab <?php echo $tab_key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( network_admin_url( 'settings.php?page=kd-bonus&tab=' . $tab_key ) ); ?>">
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
				break;
		}

		update_network_option( null, self::OPTION_KEY, $settings );

		if ( 'general' === $tab && ! empty( $settings['auto_create_dashboard_page'] ) ) {
			KD_Bonus_Plugin::ensure_dashboard_pages_for_all_sites();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'kd-bonus',
					'tab'     => $tab,
					'updated' => 1,
				),
				network_admin_url( 'settings.php' )
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
				<p class="description"><?php esc_html_e( 'Statuses are sorted by lifetime eligible spend. Reward percentage is applied to eligible product subtotal only.', 'kd-bonus' ); ?></p>
				<table class="widefat striped" id="kd-bonus-status-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status Name', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Lifetime Spend Threshold', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Reward Percentage', 'kd-bonus' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $index => $row ) : ?>
							<tr>
								<td><input type="text" class="regular-text" name="membership_statuses[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $row['name'] ?? '' ); ?>" /></td>
								<td><input type="number" step="0.01" min="0" name="membership_statuses[<?php echo esc_attr( $index ); ?>][threshold]" value="<?php echo esc_attr( $row['threshold'] ?? 0 ); ?>" /></td>
								<td><input type="number" step="0.01" min="0" name="membership_statuses[<?php echo esc_attr( $index ); ?>][reward_percent]" value="<?php echo esc_attr( $row['reward_percent'] ?? 0 ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td><input type="text" class="regular-text" name="membership_statuses[new][name]" value="" /></td>
							<td><input type="number" step="0.01" min="0" name="membership_statuses[new][threshold]" value="" /></td>
							<td><input type="number" step="0.01" min="0" name="membership_statuses[new][reward_percent]" value="" /></td>
						</tr>
					</tbody>
				</table>
				<p><button type="button" class="button" id="kd-bonus-add-status"><?php esc_html_e( 'Add Status Row', 'kd-bonus' ); ?></button></p>
				<script>
					(function () {
						const table = document.getElementById('kd-bonus-status-table');
						const button = document.getElementById('kd-bonus-add-status');
						if (!table || !button) {
							return;
						}
						button.addEventListener('click', function () {
							const tbody = table.querySelector('tbody');
							const index = tbody.querySelectorAll('tr').length;
							const row = document.createElement('tr');
							row.innerHTML = '<td><input type="text" class="regular-text" name="membership_statuses[' + index + '][name]" value="" /></td><td><input type="number" step="0.01" min="0" name="membership_statuses[' + index + '][threshold]" value="" /></td><td><input type="number" step="0.01" min="0" name="membership_statuses[' + index + '][reward_percent]" value="" /></td>';
							tbody.appendChild(row);
						});
					}());
				</script>
			</td>
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
				return $left['threshold'] <=> $right['threshold'];
			}
		);

		return array_values( $sanitized );
	}
}
