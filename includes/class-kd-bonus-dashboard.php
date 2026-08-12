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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

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
	 * Render the customer dashboard on the My Account endpoint.
	 */
	public function render_my_account_endpoint() {
		echo $this->render_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Enqueue frontend assets for the dashboard view.
	 */
	public function enqueue_assets() {
		if ( is_admin() || ! $this->should_enqueue_assets() ) {
			return;
		}

		$relative_path = 'assets/css/kd-bonus-dashboard.css';
		$style_path    = KD_BONUS_PLUGIN_DIR . $relative_path;

		if ( ! file_exists( $style_path ) ) {
			return;
		}

		wp_enqueue_style(
			'kd-bonus-dashboard',
			KD_BONUS_PLUGIN_URL . $relative_path,
			array(),
			(string) filemtime( $style_path )
		);
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
		$base_currency     = $this->rewards->get_base_currency();
		$discount_value    = $this->rewards->convert_from_base( $available_balance, $current_currency, array( 'context' => 'dashboard' ) );
		$membership_statuses = $this->rewards->get_membership_statuses();
		$reward_name       = $settings['reward_name'] ?: __( 'Reward Balance', 'kd-bonus' );
		$reward_symbol     = $settings['reward_symbol'] ?: '$KD';

		ob_start();
		?>
		<div class="kd-bonus-dashboard">
			<h2><?php esc_html_e( 'KD Bonus Dashboard', 'kd-bonus' ); ?></h2>

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
					<p><?php echo wp_kses_post( wc_price( $lifetime_spend, array( 'currency' => $base_currency ) ) ); ?></p>
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

			<?php echo wp_kses_post( $this->render_status_indicator( $membership_statuses, $status, $lifetime_spend, $base_currency ) ); ?>

			<h3 class="kd-bonus-dashboard__section-title"><?php esc_html_e( 'Recent Reward Events', 'kd-bonus' ); ?></h3>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No reward activity yet.', 'kd-bonus' ); ?></p>
			<?php else : ?>
				<div class="kd-bonus-dashboard__table-wrap">
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
				</div>
			<?php endif; ?>

			<h3 class="kd-bonus-dashboard__section-title"><?php esc_html_e( 'Membership Statuses', 'kd-bonus' ); ?></h3>
			<?php echo wp_kses_post( $this->render_membership_statuses_table( $membership_statuses, $base_currency ) ); ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Determine whether the current request can render the dashboard.
	 *
	 * @return bool
	 */
	private function should_enqueue_assets() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'kd_bonus_dashboard' );
	}

	/**
	 * Render the membership status indicator component.
	 *
	 * @param array<int,array<string,mixed>> $statuses       Status definitions.
	 * @param array<string,mixed>            $current_status Current user status.
	 * @param float                          $lifetime_spend Lifetime spend amount.
	 * @param string                         $base_currency  Base currency code.
	 * @return string
	 */
	private function render_status_indicator( $statuses, $current_status, $lifetime_spend, $base_currency ) {
		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			return '';
		}

		$current_index = $this->get_current_status_index( $statuses, $current_status, $lifetime_spend );
		$next_status   = isset( $statuses[ $current_index + 1 ] ) ? $statuses[ $current_index + 1 ] : null;
		$current_name  = isset( $current_status['name'] ) ? (string) $current_status['name'] : '';
		$reward_rate   = wc_format_decimal( (float) ( $current_status['reward_percent'] ?? 0 ), 2 ) . '%';
		$summary_text  = sprintf(
			/* translators: 1: current status name, 2: reward rate. */
			__( 'You are currently on %1$s and earn %2$s on eligible spend.', 'kd-bonus' ),
			$current_name,
			$reward_rate
		);

		if ( ! empty( $current_status['is_manual'] ) ) {
			$summary_text .= ' ' . __( 'This tier is currently set manually.', 'kd-bonus' );
		}

		ob_start();
		?>
		<section class="kd-bonus-dashboard__status-overview" aria-labelledby="kd-bonus-status-indicator-title">
			<div class="kd-bonus-dashboard__section-heading">
				<h3 id="kd-bonus-status-indicator-title" class="kd-bonus-dashboard__section-title"><?php esc_html_e( 'Membership Journey', 'kd-bonus' ); ?></h3>
				<p class="kd-bonus-dashboard__section-copy"><?php echo esc_html( $summary_text ); ?></p>
				<?php if ( is_array( $next_status ) ) : ?>
					<p class="kd-bonus-dashboard__section-copy">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: next status name, 2: spend threshold. */
								__( 'Next tier: %1$s at %2$s eligible spend.', 'kd-bonus' ),
								(string) ( $next_status['name'] ?? '' ),
								$this->format_threshold_text( (float) ( $next_status['threshold'] ?? 0 ), $base_currency )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>
			<ol class="kd-bonus-status-list" aria-label="<?php esc_attr_e( 'Membership status progress', 'kd-bonus' ); ?>">
				<?php foreach ( $statuses as $index => $status_item ) : ?>
					<?php
					$state = 'future';

					if ( $index < $current_index ) {
						$state = 'completed';
					} elseif ( $index === $current_index ) {
						$state = 'current';
					}

					$state_meta = $this->get_status_state_meta( $state );
					?>
					<li class="kd-bonus-status-card is-<?php echo esc_attr( $state ); ?>"<?php echo 'current' === $state ? ' aria-current="step"' : ''; ?>>
						<span class="kd-bonus-status-card__badge" aria-hidden="true"><?php echo esc_html( $state_meta['icon'] ); ?></span>
						<span class="kd-bonus-status-card__state"><?php echo esc_html( $state_meta['label'] ); ?></span>
						<strong class="kd-bonus-status-card__name"><?php echo esc_html( (string) ( $status_item['name'] ?? '' ) ); ?></strong>
						<span class="kd-bonus-status-card__detail"><?php echo esc_html( $this->format_threshold_text( (float) ( $status_item['threshold'] ?? 0 ), $base_currency ) ); ?></span>
						<span class="kd-bonus-status-card__detail">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: reward percentage. */
									__( '%s rewards', 'kd-bonus' ),
									wc_format_decimal( (float) ( $status_item['reward_percent'] ?? 0 ), 2 ) . '%'
								)
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the dashboard membership status table without inline styles.
	 *
	 * @param array<int,array<string,mixed>> $statuses      Status definitions.
	 * @param string                         $base_currency Base currency code.
	 * @return string
	 */
	private function render_membership_statuses_table( $statuses, $base_currency ) {
		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="kd-bonus-dashboard__table-wrap">
			<table class="kd-bonus-status-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Status', 'kd-bonus' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Required products spent', 'kd-bonus' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reward percentage', 'kd-bonus' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $statuses as $status_item ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $status_item['name'] ?? '' ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( (float) ( $status_item['threshold'] ?? 0 ), array( 'currency' => $base_currency ) ) ); ?></td>
							<td><?php echo esc_html( wc_format_decimal( (float) ( $status_item['reward_percent'] ?? 0 ), 2 ) . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Find the current tier index for the indicator.
	 *
	 * @param array<int,array<string,mixed>> $statuses       Status definitions.
	 * @param array<string,mixed>            $current_status Current user status.
	 * @param float                          $lifetime_spend Lifetime spend amount.
	 * @return int
	 */
	private function get_current_status_index( $statuses, $current_status, $lifetime_spend ) {
		$current_name = isset( $current_status['name'] ) ? trim( (string) $current_status['name'] ) : '';
		$fallback     = 0;

		foreach ( $statuses as $index => $status_item ) {
			$status_name = isset( $status_item['name'] ) ? trim( (string) $status_item['name'] ) : '';
			$threshold   = (float) ( $status_item['threshold'] ?? 0 );

			if ( '' !== $current_name && 0 === strcasecmp( $status_name, $current_name ) ) {
				return (int) $index;
			}

			if ( $lifetime_spend >= $threshold ) {
				$fallback = (int) $index;
			}
		}

		return $fallback;
	}

	/**
	 * Return visible status metadata for a tracker item.
	 *
	 * @param string $state Item state.
	 * @return array<string,string>
	 */
	private function get_status_state_meta( $state ) {
		if ( 'completed' === $state ) {
			return array(
				'icon'  => '✓',
				'label' => __( 'Completed', 'kd-bonus' ),
			);
		}

		if ( 'current' === $state ) {
			return array(
				'icon'  => '★',
				'label' => __( 'Current', 'kd-bonus' ),
			);
		}

		return array(
			'icon'  => '○',
			'label' => __( 'Future', 'kd-bonus' ),
		);
	}

	/**
	 * Format a status threshold as plain text.
	 *
	 * @param float  $threshold     Threshold amount.
	 * @param string $base_currency Base currency code.
	 * @return string
	 */
	private function format_threshold_text( $threshold, $base_currency ) {
		if ( $threshold <= 0 ) {
			return __( 'No spend required', 'kd-bonus' );
		}

		return sprintf(
			/* translators: %s: spend threshold. */
			__( 'Spend %s+', 'kd-bonus' ),
			wp_strip_all_tags( wc_price( $threshold, array( 'currency' => $base_currency ) ) )
		);
	}
}
