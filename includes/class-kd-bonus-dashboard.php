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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'init', array( $this, 'register_my_account_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
		add_action( 'woocommerce_account_' . self::MY_ACCOUNT_ENDPOINT . '_endpoint', array( $this, 'render_my_account_endpoint' ) );
	}

	/**
	 * Enqueue frontend styles for the dashboard.
	 */
	public function enqueue_styles() {
		wp_register_style( 'kd-bonus-dashboard', false, array(), false, false );
		wp_enqueue_style( 'kd-bonus-dashboard' );
		wp_add_inline_style( 'kd-bonus-dashboard', $this->get_tier_progress_css() );
	}

	/**
	 * Return the CSS for the membership tier progress bar.
	 *
	 * @return string
	 */
	private function get_tier_progress_css() {
		return '
			.kd-bonus-tier-progress__bar{display:flex;align-items:flex-start;position:relative;padding:12px 0 32px;}
			.kd-bonus-tier-progress__bar::before{content:\'\';position:absolute;top:16px;left:0;right:0;height:4px;background:#dcdcde;z-index:0;}
			.kd-bonus-tier-progress__step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;}
			.kd-bonus-tier-progress__dot{width:32px;height:32px;border-radius:50%;border:3px solid #dcdcde;background:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;transition:background .2s,border-color .2s;}
			.kd-bonus-tier-progress__step--completed .kd-bonus-tier-progress__dot{background:#4caf50;border-color:#4caf50;color:#fff;}
			.kd-bonus-tier-progress__step--current .kd-bonus-tier-progress__dot{background:#f5a623;border-color:#f5a623;color:#fff;box-shadow:0 0 0 4px rgba(245,166,35,.25);}
			.kd-bonus-tier-progress__step--future .kd-bonus-tier-progress__dot{background:#fff;border-color:#dcdcde;color:#aaa;}
			.kd-bonus-tier-progress__label{margin-top:8px;font-size:11px;text-align:center;word-break:break-word;max-width:80px;line-height:1.3;}
			.kd-bonus-tier-progress__step--current .kd-bonus-tier-progress__label{font-weight:700;color:#f5a623;}
			.kd-bonus-tier-progress__step--completed .kd-bonus-tier-progress__label{color:#4caf50;}
			.kd-bonus-tier-progress__step--future .kd-bonus-tier-progress__label{color:#aaa;}
			.kd-bonus-tier-progress__connector{position:absolute;top:16px;left:50%;right:-50%;height:4px;z-index:0;}
			.kd-bonus-tier-progress__step--completed .kd-bonus-tier-progress__connector{background:#4caf50;}
			.kd-bonus-tier-progress__step--current .kd-bonus-tier-progress__connector{background:#dcdcde;}
			.kd-bonus-tier-progress__step--future .kd-bonus-tier-progress__connector{background:#dcdcde;}
			@media(max-width:480px){
				.kd-bonus-tier-progress__label{font-size:9px;max-width:56px;}
				.kd-bonus-tier-progress__dot{width:24px;height:24px;font-size:10px;}
			}
		';
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
	 * Render the customer dashboard on the My Account endpoint.
	 */
	public function render_my_account_endpoint() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output is sanitized internally.
		echo $this->render_shortcode();
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

		// Prepare sorted membership tier list for the progress bar.
		// Sort ascending by threshold; break ties with DESCENDING priority so that
		// a numerically lower priority number (= higher rank) appears further right,
		// matching how get_status_for_spend() resolves the active tier.
		$all_statuses = $this->rewards->get_membership_statuses();
		usort(
			$all_statuses,
			static function ( $a, $b ) {
				$threshold_compare = (float) ( $a['threshold'] ?? 0 ) <=> (float) ( $b['threshold'] ?? 0 );
				if ( 0 !== $threshold_compare ) {
					return $threshold_compare;
				}
				// Lower priority number = higher rank → sort descending so highest rank is rightmost.
				return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
			}
		);
		$current_status_name = isset( $status['name'] ) ? (string) $status['name'] : '';

		ob_start();
		?>
		<div class="kd-bonus-dashboard">
			<h2><?php esc_html_e( 'KD Bonus Dashboard', 'kd-bonus' ); ?></h2>

			<?php if ( ! empty( $all_statuses ) ) : ?>
			<div class="kd-bonus-tier-progress" style="margin-bottom:24px;">
				<div class="kd-bonus-tier-progress__bar">
					<?php
					$total_steps   = count( $all_statuses );
					$found_current = false;
					// Pre-scan to check whether any tier matches the current status name.
					// If none matches (e.g. synthetic fallback "Member" not stored as a tier),
					// all steps should render as future rather than all-completed.
					$any_match = false;
					foreach ( $all_statuses as $tier ) {
						if ( 0 === strcasecmp( isset( $tier['name'] ) ? (string) $tier['name'] : '', $current_status_name ) ) {
							$any_match = true;
							break;
						}
					}
					foreach ( $all_statuses as $step_index => $tier ) :
						$tier_name  = isset( $tier['name'] ) ? (string) $tier['name'] : '';
						$is_current = $any_match && ( 0 === strcasecmp( $tier_name, $current_status_name ) );
						if ( $is_current ) {
							$step_class    = 'kd-bonus-tier-progress__step--current';
							$found_current = true;
						} elseif ( ! $found_current ) {
							$step_class = $any_match ? 'kd-bonus-tier-progress__step--completed' : 'kd-bonus-tier-progress__step--future';
						} else {
							$step_class = 'kd-bonus-tier-progress__step--future';
						}
						$is_last = ( $step_index === $total_steps - 1 );
					?>
					<div class="kd-bonus-tier-progress__step <?php echo esc_attr( $step_class ); ?>">
						<?php if ( ! $is_last ) : ?>
						<div class="kd-bonus-tier-progress__connector"></div>
						<?php endif; ?>
						<div class="kd-bonus-tier-progress__dot">
							<?php if ( 'kd-bonus-tier-progress__step--completed' === $step_class ) : ?>
							&#10003;
							<?php elseif ( 'kd-bonus-tier-progress__step--current' === $step_class ) : ?>
							&#9733;
							<?php else : ?>
							<?php echo esc_html( (string) ( $step_index + 1 ) ); ?>
							<?php endif; ?>
						</div>
						<div class="kd-bonus-tier-progress__label"><?php echo esc_html( $tier_name ); ?></div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="kd-bonus-dashboard__summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php echo esc_html( $reward_name ); ?></strong>
					<p><?php echo esc_html( $this->rewards->format_reward_amount( $balance ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php esc_html_e( 'Available Discount', 'kd-bonus' ); ?></strong>
					<p><?php echo wp_kses_post( wc_price( $discount_value ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php esc_html_e( 'Membership Status', 'kd-bonus' ); ?></strong>
					<p><?php echo esc_html( $status['name'] ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php esc_html_e( 'Lifetime Eligible Spend', 'kd-bonus' ); ?></strong>
					<p><?php echo wp_kses_post( wc_price( $lifetime_spend, array( 'currency' => $this->rewards->get_base_currency() ) ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php esc_html_e( 'Reward Rate', 'kd-bonus' ); ?></strong>
					<p><?php echo esc_html( wc_format_decimal( (float) $status['reward_percent'], 2 ) . '%' ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php echo esc_html( sprintf( __( 'Available %s', 'kd-bonus' ), $reward_symbol ) ); ?></strong>
					<p><?php echo esc_html( $this->rewards->format_reward_amount( $available_balance ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
					<strong><?php esc_html_e( 'Last Reward Deposit', 'kd-bonus' ); ?></strong>
					<p><?php echo esc_html( ! empty( $expiry['last_earned_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $expiry['last_earned_at'] ) : __( 'Never', 'kd-bonus' ) ); ?></p>
				</div>
				<div class="kd-bonus-dashboard__card" style="padding:16px;border:1px solid #dcdcde;border-radius:8px;">
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

			<h3 style="margin-top:24px;"><?php esc_html_e( 'Recent Reward Events', 'kd-bonus' ); ?></h3>
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

			<h3 style="margin-top:24px;"><?php esc_html_e( 'Membership Statuses', 'kd-bonus' ); ?></h3>
			<?php echo wp_kses_post( $this->settings->render_membership_statuses_table_shortcode() ); ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
