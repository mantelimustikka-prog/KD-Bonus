<?php
/**
 * Customer dashboard shortcode output.
 *
 * @package KD_Bonus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KD_Bonus_Dashboard {
	/**
	 * Settings handler.
	 *
	 * @var KD_Bonus_Settings
	 */
	private $settings;

	/**
	 * Rewards handler.
	 *
	 * @var KD_Bonus_Rewards
	 */
	private $rewards;

	/**
	 * Constructor.
	 *
	 * @param KD_Bonus_Settings $settings Settings handler.
	 * @param KD_Bonus_Rewards  $rewards Rewards handler.
	 */
	public function __construct( $settings, $rewards ) {
		$this->settings = $settings;
		$this->rewards  = $rewards;
	}

	/**
	 * WooCommerce My Account endpoint slug.
	 */
	const MY_ACCOUNT_ENDPOINT = 'my-kd';

	/**
	 * Register dashboard shortcode and WooCommerce My Account endpoint.
	 */
	public function register() {
		add_shortcode( 'kd_bonus_dashboard', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_dashboard_styles' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'init', array( $this, 'register_my_account_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::MY_ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render_my_account_endpoint' ) );
	}

	/**
	 * Register the My Account query-var / rewrite endpoint.
	 */
	public function register_my_account_endpoint() {
		add_rewrite_endpoint( self::MY_ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add "My $KD" item to the WooCommerce My Account navigation.
	 *
	 * @param array<string,string> $items Existing menu items.
	 * @return array<string,string>
	 */
	public function add_my_account_menu_item( $items ) {
		if ( ! is_user_logged_in() ) {
			return $items;
		}

		$settings    = KD_Bonus_Settings::get_settings();
		$label       = sprintf(
			/* translators: %s: reward symbol */
			__( 'My %s', 'kd-bonus' ),
			! empty( $settings['reward_symbol'] ) ? $settings['reward_symbol'] : '$KD'
		);

		// Insert before the logout link when present.
		$logout_offset = array_search( 'customer-logout', array_keys( $items ), true );
		if ( false !== $logout_offset ) {
			$items = array_slice( $items, 0, $logout_offset, true )
				+ array( self::MY_ACCOUNT_ENDPOINT => $label )
				+ array_slice( $items, $logout_offset, null, true );
		} else {
			$items[ self::MY_ACCOUNT_ENDPOINT ] = $label;
		}

		return $items;
	}

	/**
	 * Register the dashboard stylesheet so it can be enqueued on demand.
	 */
	public function register_dashboard_styles() {
		wp_register_style(
			'kd-bonus-dashboard',
			plugin_dir_url( KD_BONUS_PLUGIN_FILE ) . 'assets/css/kd-bonus-dashboard.css',
			array(),
			KD_BONUS_VERSION
		);
	}

	/**
	 * Enqueue the dashboard stylesheet.
	 * Called from within the shortcode so the style is only loaded on pages that use it.
	 */
	private function enqueue_dashboard_styles() {
		wp_enqueue_style( 'kd-bonus-dashboard' );
	}

	/**
	 * Render the membership tier progress indicator.
	 *
	 * @param array<string,mixed> $statuses  All configured membership tiers, sorted by threshold.
	 * @param string              $current_name Name of the user's current tier.
	 * @return string HTML for the tier indicator.
	 */
	private function render_tier_indicator( $statuses, $current_name ) {
		if ( empty( $statuses ) ) {
			return '';
		}

		$found_current = false;
		$html          = '<ul class="kd-bonus-tier-indicator" aria-label="' . esc_attr__( 'Membership tiers', 'kd-bonus' ) . '">' . "\n";

		foreach ( $statuses as $tier ) {
			$name      = isset( $tier['name'] ) ? (string) $tier['name'] : '';
			$threshold = isset( $tier['threshold'] ) ? (float) $tier['threshold'] : 0.0;
			$reward    = isset( $tier['reward_percent'] ) ? (float) $tier['reward_percent'] : 0.0;

			$is_current = ( $name === $current_name );

			if ( $is_current ) {
				$state_class  = 'kd-bonus-tier-indicator__pill--current';
				$badge        = '&#9679;'; // filled circle
				$found_current = true;
			} elseif ( ! $found_current ) {
				$state_class = 'kd-bonus-tier-indicator__pill--completed';
				$badge       = '&#10003;'; // checkmark
			} else {
				$state_class = 'kd-bonus-tier-indicator__pill--future';
				$badge       = '&#9675;'; // open circle
			}

			$html .= '<li class="kd-bonus-tier-indicator__pill ' . esc_attr( $state_class ) . '">';
			$html .= '<span class="kd-bonus-tier-indicator__badge" aria-hidden="true">' . $badge . '</span>';
			$html .= '<span class="kd-bonus-tier-indicator__name">' . esc_html( $name ) . '</span>';

			if ( $threshold > 0 || $reward > 0 ) {
				$meta_parts = array();
				if ( $threshold > 0 ) {
					$meta_parts[] = sprintf(
						/* translators: %s: formatted currency amount */
						__( 'From %s', 'kd-bonus' ),
						wp_kses_post( wc_price( $threshold, array( 'currency' => $this->rewards->get_base_currency() ) ) )
					);
				}
				if ( $reward > 0 ) {
					$meta_parts[] = esc_html( wc_format_decimal( $reward, 2 ) . '%' );
				}
				$html .= '<span class="kd-bonus-tier-indicator__meta">' . wp_kses_post( implode( ' &middot; ', $meta_parts ) ) . '</span>';
			}

			$html .= '</li>' . "\n";
		}

		$html .= '</ul>';

		return $html;
	}

	/**
	 * Render the customer dashboard on the My Account endpoint.
	 */
	public function render_my_account_endpoint() {
		echo $this->render_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render frontend dashboard.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="kd-bonus-dashboard"><p>' . esc_html__( 'Please log in to view your KD Bonus dashboard.', 'kd-bonus' ) . '</p></div>';
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="kd-bonus-dashboard"><p>' . esc_html__( 'WooCommerce is required before KD Bonus balances and rewards can be displayed.', 'kd-bonus' ) . '</p></div>';
		}

		$user_id           = get_current_user_id();
		$settings          = KD_Bonus_Settings::get_settings();
		$balance           = $this->rewards->get_balance( $user_id );
		$available_balance = $this->rewards->get_available_balance( $user_id );
		$lifetime_spend    = $this->rewards->get_lifetime_spend( $user_id );
		$status            = $this->rewards->get_user_status( $user_id );
		$history           = $this->rewards->get_transaction_history( $user_id, 10 );
		$expiry            = $this->rewards->get_user_expiry_data( $user_id );
		$current_currency  = get_woocommerce_currency();
		$discount_value    = $this->rewards->convert_from_base( $available_balance, $current_currency, array( 'context' => 'dashboard' ) );
		$reward_name       = $settings['reward_name'] ?: __( 'Reward Balance', 'kd-bonus' );
		$reward_symbol     = $settings['reward_symbol'] ?: '$KD';

		$this->enqueue_dashboard_styles();

		ob_start();
		?>
		<div class="kd-bonus-dashboard">
			<h2><?php esc_html_e( 'KD Bonus Dashboard', 'kd-bonus' ); ?></h2>

			<?php echo wp_kses_post( $this->render_tier_indicator( $this->rewards->get_membership_statuses(), $status['name'] ) ); ?>

			<div class="kd-bonus-dashboard__summary">
				<div class="kd-bonus-dashboard__card">
					<strong><?php echo esc_html( $reward_name ); ?></strong>
					<p><?php echo esc_html( $this->rewards->format_reward_amount( $balance ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php esc_html_e( 'Available Discount', 'kd-bonus' ); ?></strong>
					<p><?php echo wp_kses_post( wc_price( $discount_value ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php esc_html_e( 'Membership Status', 'kd-bonus' ); ?></strong>
					<p><?php echo esc_html( $status['name'] ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php esc_html_e( 'Lifetime Eligible Spend', 'kd-bonus' ); ?></strong>
					<p><?php echo wp_kses_post( wc_price( $lifetime_spend, array( 'currency' => $this->rewards->get_base_currency() ) ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php esc_html_e( 'Reward Rate', 'kd-bonus' ); ?></strong>
					<p><?php echo esc_html( wc_format_decimal( (float) $status['reward_percent'], 2 ) . '%' ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php echo esc_html( sprintf( __( 'Available %s', 'kd-bonus' ), $reward_symbol ) ); ?></strong>
					<p><?php echo esc_html( $this->rewards->format_reward_amount( $available_balance ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php esc_html_e( 'Last Reward Deposit', 'kd-bonus' ); ?></strong>
					<p><?php echo esc_html( ! empty( $expiry['last_earned_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $expiry['last_earned_at'] ) : __( 'Never', 'kd-bonus' ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card">
					<strong><?php esc_html_e( 'Reward Expiry', 'kd-bonus' ); ?></strong>
					<p>
						<?php
						if ( empty( $expiry['expiry_days'] ) ) {
							esc_html_e( 'Disabled', 'kd-bonus' );
						} elseif ( ! empty( $expiry['expires_at'] ) ) {
							echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $expiry['expires_at'] ) );
						} else {
							esc_html_e( 'Waiting for first reward deposit', 'kd-bonus' );
						}
						?>
					</p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Recent Reward Events', 'kd-bonus' ); ?></h3>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No reward activity yet.', 'kd-bonus' ); ?></p>
			<?php else : ?>
				<table class="shop_table shop_table_responsive">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Type', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Balance After', 'kd-bonus' ); ?></th>
							<th><?php esc_html_e( 'Details', 'kd-bonus' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $history as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $entry->created_at . ' UTC' ) ) ); ?></td>
								<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $entry->type ) ) ); ?></td>
								<td><?php echo esc_html( $this->rewards->format_reward_amount( (float) $entry->amount ) ); ?></td>
								<td><?php echo esc_html( $this->rewards->format_reward_amount( (float) $entry->balance_after ) ); ?></td>
								<td><?php echo esc_html( $entry->description ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Membership Statuses', 'kd-bonus' ); ?></h3>
			<?php echo wp_kses_post( $this->settings->render_membership_statuses_table_shortcode() ); ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
