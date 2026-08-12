<?php
/**
 * Reward engine and WooCommerce integration.
 *
 * @package KD_Bonus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KD_Bonus_Rewards {
	/**
	 * Lifetime spend meta key.
	 */
	const LIFETIME_SPEND_META = 'kd_bonus_lifetime_spend';

	/**
	 * Balance meta key.
	 */
	const BALANCE_META = 'kd_bonus_balance';

	/**
	 * Membership name meta key.
	 */
	const STATUS_META = 'kd_bonus_membership_status';

	/**
	 * Last reward deposit timestamp meta key.
	 */
	const LAST_EARNED_AT_META = 'kd_bonus_last_earned_at';

	/**
	 * Session key for base redemption amount.
	 */
	const SESSION_REDEMPTION_KEY = 'kd_bonus_redemption_base';

	/**
	 * Settings handler.
	 *
	 * @var KD_Bonus_Settings
	 */
	private $settings;

	/**
	 * Users already checked for expiry during the current request.
	 *
	 * @var array<int,bool>
	 */
	private $expiry_checked_users = array();

	/**
	 * Constructor.
	 *
	 * @param KD_Bonus_Settings $settings Settings handler.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register WooCommerce hooks.
	 */
	public function register() {
		add_filter( 'wpmu_users_columns', array( $this, 'add_network_users_bonus_status_column' ) );
		add_filter( 'manage_users-network_custom_column', array( $this, 'render_network_users_bonus_status_column' ), 10, 3 );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'template_redirect', array( $this, 'handle_checkout_redemption_request' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_redemption_ui' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_checkout_redemption' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_checkout_redemption_on_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_change' ), 20, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_order_reversal' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_order_reversal' ) );
	}

	/**
	 * Add the bonus status column to the Network Admin users table.
	 *
	 * @param array<string,string> $columns Users table columns.
	 * @return array<string,string>
	 */
	public function add_network_users_bonus_status_column( $columns ) {
		$columns['kd_bonus_status'] = __( 'Bonus Status', 'kd-bonus' );

		return $columns;
	}

	/**
	 * Render the bonus status column for Network Admin users.
	 *
	 * @param string $output      Existing column output.
	 * @param string $column_name Column key.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function render_network_users_bonus_status_column( $output, $column_name, $user_id ) {
		if ( 'kd_bonus_status' !== $column_name || ! is_network_admin() ) {
			return $output;
		}

		return $this->get_network_user_bonus_status_label( $user_id );
	}

	/**
	 * Create the global transaction table.
	 */
	public static function create_transaction_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(50) NOT NULL,
			amount decimal(18,4) NOT NULL DEFAULT 0.0000,
			balance_after decimal(18,4) NOT NULL DEFAULT 0.0000,
			currency varchar(12) NOT NULL DEFAULT '',
			description text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY order_id (order_id),
			KEY type (type)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Get transaction table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . 'kd_bonus_transactions';
	}

	/**
	 * Get global reward balance.
	 *
	 * @param int $user_id User ID.
	 * @return float
	 */
	public function get_balance( $user_id ) {
		$this->maybe_expire_user_balance( $user_id );

		return (float) get_user_meta( $user_id, self::BALANCE_META, true );
	}

	/**
	 * Get available reward balance for checkout use.
	 *
	 * @param int $user_id User ID.
	 * @return float
	 */
	public function get_available_balance( $user_id ) {
		return max( 0, $this->get_balance( $user_id ) );
	}

	/**
	 * Get lifetime eligible spend.
	 *
	 * @param int $user_id User ID.
	 * @return float
	 */
	public function get_lifetime_spend( $user_id ) {
		return (float) get_user_meta( $user_id, self::LIFETIME_SPEND_META, true );
	}

	/**
	 * Get active reward settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_reward_settings() {
		return KD_Bonus_Settings::get_settings();
	}

	/**
	 * Get the configured WooCommerce order status that triggers reward awarding.
	 *
	 * @return string
	 */
	public function get_award_order_status() {
		$settings = $this->get_reward_settings();
		$status   = isset( $settings['award_order_status'] ) ? sanitize_key( $settings['award_order_status'] ) : '';

		return $status ? $status : 'wc-processing';
	}

	/**
	 * Get the configured number of days before unused points expire.
	 *
	 * @return int
	 */
	public function get_reward_expiry_days() {
		$settings = $this->get_reward_settings();

		return max( 0, absint( $settings['reward_expiry_days'] ?? 0 ) );
	}

	/**
	 * Get membership statuses sorted by threshold.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_membership_statuses() {
		$settings = $this->get_reward_settings();
		$rows     = $settings['membership_statuses'];

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Resolve membership status by lifetime spend.
	 *
	 * @param float $lifetime_spend Lifetime spend.
	 * @return array<string,mixed>
	 */
	public function get_status_for_spend( $lifetime_spend ) {
		$current = array(
			'name'           => __( 'Member', 'kd-bonus' ),
			'threshold'      => 0,
			'reward_percent' => 0,
		);

		foreach ( $this->get_membership_statuses() as $status ) {
			if ( $lifetime_spend >= (float) $status['threshold'] ) {
				$current = $status;
			}
		}

		return $current;
	}

	/**
	 * Get current membership status for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public function get_user_status( $user_id ) {
		$lifetime_spend = $this->get_lifetime_spend( $user_id );

		return $this->get_status_for_spend( $lifetime_spend );
	}

	/**
	 * Get the network users table label for a user's bonus status.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function get_network_user_bonus_status_label( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return sprintf(
				/* translators: 1: membership status label, 2: reward points with symbol. */
				__( '%1$s (%2$s)', 'kd-bonus' ),
				__( 'No status', 'kd-bonus' ),
				$this->format_network_reward_amount( 0 )
			);
		}

		$balance         = $this->get_balance( $user_id );
		$lifetime_spend  = $this->get_lifetime_spend( $user_id );
		$stored_status   = trim( (string) get_user_meta( $user_id, self::STATUS_META, true ) );
		$computed_status = $this->get_status_for_spend( $lifetime_spend );
		$status_name     = '';

		if ( $lifetime_spend > 0 ) {
			$status_name = ! empty( $computed_status['name'] ) ? (string) $computed_status['name'] : '';
		} elseif ( '' !== $stored_status ) {
			$status_name = $stored_status;
		}

		if ( '' === $status_name ) {
			$status_name = __( 'No status', 'kd-bonus' );
		}

		return sprintf(
			/* translators: 1: membership status label, 2: reward points with symbol. */
			__( '%1$s (%2$s)', 'kd-bonus' ),
			$status_name,
			$this->format_network_reward_amount( $balance )
		);
	}

	/**
	 * Format reward amount with symbol.
	 *
	 * @param float $amount Amount in base reward currency.
	 * @return string
	 */
	public function format_reward_amount( $amount ) {
		$settings = $this->get_reward_settings();
		$symbol   = $settings['reward_symbol'] ?: '$KD';
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return sprintf( '%1$s %2$s', $symbol, number_format_i18n( (float) $amount, $decimals ) );
	}

	/**
	 * Format reward amount for the Network Admin users column.
	 *
	 * @param float $amount Amount in base reward currency.
	 * @return string
	 */
	private function format_network_reward_amount( $amount ) {
		$settings = $this->get_reward_settings();
		$symbol   = $settings['reward_symbol'] ?? '$KD';
		$symbol   = '' !== $symbol ? $symbol : '$KD';
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$amount   = (float) $amount;
		$scale    = abs( $amount - round( $amount ) ) < 0.00001 ? 0 : $decimals;

		return sprintf( '%1$s %2$s', number_format_i18n( $amount, $scale ), $symbol );
	}

	/**
	 * Convert a base reward amount to another currency using filters for multi-currency implementations.
	 *
	 * @param float  $amount  Base amount.
	 * @param string $to_code Currency code.
	 * @param array  $context Conversion context.
	 * @return float
	 */
	public function convert_from_base( $amount, $to_code, $context = array() ) {
		$base_code = $this->get_base_currency();
		$to_code   = strtoupper( (string) $to_code );

		if ( '' === $to_code || $base_code === $to_code ) {
			return (float) $amount;
		}

		$rate = (float) apply_filters( 'kd_bonus_currency_conversion_rate', 1, $base_code, $to_code, $context );
		$rate = $rate > 0 ? $rate : 1;

		return (float) $amount * $rate;
	}

	/**
	 * Convert checkout currency amount into base reward units.
	 *
	 * @param float  $amount    Target amount.
	 * @param string $from_code Source currency.
	 * @param array  $context   Conversion context.
	 * @return float
	 */
	public function convert_to_base( $amount, $from_code, $context = array() ) {
		$base_code = $this->get_base_currency();
		$from_code = strtoupper( (string) $from_code );

		if ( '' === $from_code || $base_code === $from_code ) {
			return (float) $amount;
		}

		$rate = (float) apply_filters( 'kd_bonus_currency_conversion_rate', 1, $base_code, $from_code, $context );
		$rate = $rate > 0 ? $rate : 1;

		return (float) $amount / $rate;
	}

	/**
	 * Get configured base currency.
	 *
	 * @return string
	 */
	public function get_base_currency() {
		$settings      = $this->get_reward_settings();
		$base_currency = strtoupper( (string) $settings['base_currency'] );

		if ( '' !== $base_currency ) {
			return $base_currency;
		}

		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
	}

	/**
	 * Read transaction history for dashboard output.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Maximum number of rows.
	 * @return array<int,object>
	 */
	public function get_transaction_history( $user_id, $limit = 20 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$query      = $wpdb->prepare(
			"SELECT id, site_id, order_id, type, amount, balance_after, currency, description, created_at
			FROM {$table_name}
			WHERE user_id = %d
			ORDER BY id DESC
			LIMIT %d",
			$user_id,
			max( 1, (int) $limit )
		);

		return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Handle reward application POSTs from checkout.
	 */
	public function handle_checkout_redemption_request() {
		if ( ! function_exists( 'WC' ) || ! is_user_logged_in() || ! isset( $_POST['kd_bonus_checkout_action'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'kd_bonus_checkout_redemption' ) ) {
			return;
		}

		if ( ! is_checkout() || is_admin() || ! WC()->session ) {
			return;
		}

		$action          = sanitize_key( wp_unslash( $_POST['kd_bonus_checkout_action'] ) );
		$current_currency = get_woocommerce_currency();

		if ( 'remove' === $action ) {
			WC()->session->__unset( self::SESSION_REDEMPTION_KEY );
			wc_add_notice( __( 'KD Bonus credit removed from checkout.', 'kd-bonus' ), 'notice' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$requested_amount = wc_format_decimal( wp_unslash( $_POST['kd_bonus_redeem_amount'] ?? 0 ) );
		$requested_amount = max( 0, (float) $requested_amount );
		$user_id          = get_current_user_id();
		$available_base   = $this->get_available_balance( $user_id );
		$available_local  = $this->convert_from_base( $available_base, $current_currency, array( 'context' => 'checkout_available' ) );
		$cart_cap         = $this->get_checkout_redemption_cap();
		$apply_amount     = min( $requested_amount, $available_local, $cart_cap );

		if ( $apply_amount <= 0 ) {
			WC()->session->__unset( self::SESSION_REDEMPTION_KEY );
			wc_add_notice( __( 'Enter a valid KD Bonus redemption amount.', 'kd-bonus' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$base_amount = $this->convert_to_base( $apply_amount, $current_currency, array( 'context' => 'checkout_redeem' ) );
		$base_amount = min( $available_base, $base_amount );

		WC()->session->set( self::SESSION_REDEMPTION_KEY, $base_amount );
		wc_add_notice( sprintf( __( 'Applied %s in KD Bonus credit.', 'kd-bonus' ), wp_strip_all_tags( wc_price( $apply_amount ) ) ), 'success' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Render checkout balance and redemption form.
	 */
	public function render_checkout_redemption_ui() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$settings = $this->get_reward_settings();
		if ( empty( $settings['checkout_redemption'] ) ) {
			return;
		}

		$user_id           = get_current_user_id();
		$available_base    = $this->get_available_balance( $user_id );
		$current_currency  = get_woocommerce_currency();
		$available_current = $this->convert_from_base( $available_base, $current_currency, array( 'context' => 'checkout_display' ) );
		$applied_base      = $this->get_session_redemption_base();
		$applied_current   = $this->convert_from_base( $applied_base, $current_currency, array( 'context' => 'checkout_display' ) );
		$cap               = $this->get_checkout_redemption_cap();
		$max_input         = min( $available_current, $cap );

		if ( $available_base <= 0 ) {
			return;
		}
		?>
		<div class="woocommerce-info kd-bonus-checkout-panel">
			<p><strong><?php esc_html_e( 'KD Bonus available balance', 'kd-bonus' ); ?>:</strong> <?php echo esc_html( $this->format_reward_amount( $available_base ) ); ?><?php if ( $current_currency !== $this->get_base_currency() ) : ?> (<?php echo wp_kses_post( wc_price( $available_current ) ); ?>)<?php endif; ?></p>
			<?php if ( $applied_base > 0 ) : ?>
				<p><strong><?php esc_html_e( 'Currently applied', 'kd-bonus' ); ?>:</strong> <?php echo wp_kses_post( wc_price( $applied_current ) ); ?></p>
			<?php endif; ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'kd_bonus_checkout_redemption' ); ?>
				<p>
					<label for="kd_bonus_redeem_amount"><?php esc_html_e( 'Use KD Bonus at checkout', 'kd-bonus' ); ?></label><br />
					<input id="kd_bonus_redeem_amount" name="kd_bonus_redeem_amount" type="number" min="0" step="0.01" max="<?php echo esc_attr( wc_format_decimal( $max_input ) ); ?>" value="<?php echo esc_attr( $applied_current > 0 ? wc_format_decimal( $applied_current ) : '' ); ?>" />
					<span class="description"><?php echo esc_html( sprintf( __( 'Maximum usable now: %s', 'kd-bonus' ), wp_strip_all_tags( wc_price( $max_input ) ) ) ); ?></span>
				</p>
				<p>
					<button class="button" type="submit" name="kd_bonus_checkout_action" value="apply"><?php esc_html_e( 'Apply Balance', 'kd-bonus' ); ?></button>
					<?php if ( $applied_base > 0 ) : ?>
						<button class="button button-secondary" type="submit" name="kd_bonus_checkout_action" value="remove"><?php esc_html_e( 'Remove Balance', 'kd-bonus' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Apply negative cart fee for the active redemption amount.
	 *
	 * @param WC_Cart $cart WooCommerce cart.
	 */
	public function apply_checkout_redemption( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart instanceof WC_Cart || ! is_user_logged_in() ) {
			return;
		}

		$base_amount = $this->get_session_redemption_base();
		if ( $base_amount <= 0 ) {
			return;
		}

		$current_currency = get_woocommerce_currency();
		$applied_amount   = $this->convert_from_base( $base_amount, $current_currency, array( 'context' => 'cart_fee' ) );
		$applied_amount   = min( $applied_amount, $this->get_checkout_redemption_cap() );

		if ( $applied_amount <= 0 ) {
			return;
		}

		$cart->add_fee( __( 'KD Bonus Credit', 'kd-bonus' ), -1 * $applied_amount, false );
	}

	/**
	 * Award rewards only when an order reaches the configured trigger status.
	 *
	 * @param int            $order_id Order ID.
	 * @param string         $from Previous status slug without wc- prefix.
	 * @param string         $to New status slug without wc- prefix.
	 * @param WC_Order|mixed $order Order object when available.
	 */
	public function handle_order_status_change( $order_id, $from, $to, $order ) {
		if ( 'wc-' . sanitize_key( $to ) !== $this->get_award_order_status() ) {
			return;
		}

		$this->handle_order_completion( $order_id );
	}

	/**
	 * Persist redemption metadata on orders.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Posted checkout data.
	 */
	public function store_checkout_redemption_on_order( $order, $data = array() ) {
		if ( ! $order instanceof WC_Order || ! is_user_logged_in() ) {
			return;
		}

		$base_amount = $this->get_session_redemption_base();
		if ( $base_amount <= 0 ) {
			return;
		}

		$current_currency = $order->get_currency();
		$order->update_meta_data( '_kd_bonus_redemption_base', $base_amount );
		$order->update_meta_data( '_kd_bonus_redemption_amount', $this->convert_from_base( $base_amount, $current_currency, array( 'context' => 'order_meta' ) ) );
		$order->update_meta_data( '_kd_bonus_redemption_currency', $current_currency );
	}

	/**
	 * Process order completion for reward accrual and redemption settlement.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order_completion( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order->get_meta( '_kd_bonus_processed', true ) ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			$order->update_meta_data( '_kd_bonus_processed', 1 );
			$order->save();
			return;
		}

		$eligible_subtotal = $this->get_order_eligible_subtotal( $order );
		$lifetime_before   = $this->get_lifetime_spend( $user_id );
		$status_before     = $this->get_status_for_spend( $lifetime_before );

		$this->settle_redemption_for_order( $order, $user_id );

		$lifetime_after = $this->adjust_lifetime_spend( $user_id, $eligible_subtotal );

		$status_after   = $this->get_status_for_spend( $lifetime_after );
		$earned_amount  = 0.0;

		if ( ! $this->order_has_coupon_discount( $order ) ) {
			$reward_percent = (float) $status_after['reward_percent'];
			$earned_amount  = round( $eligible_subtotal * ( $reward_percent / 100 ), wc_get_price_decimals() );
		} else {
			$order->update_meta_data( '_kd_bonus_coupon_excluded', 1 );
		}

		if ( $earned_amount > 0 ) {
			$new_balance = $this->adjust_balance(
				$user_id,
				$earned_amount,
				'earn',
				array(
					'site_id'     => get_current_blog_id(),
					'order_id'    => $order->get_id(),
					'currency'    => $this->get_base_currency(),
					'description' => sprintf(
						/* translators: 1: amount, 2: order number */
						__( 'Earned %1$s from order #%2$s', 'kd-bonus' ),
						$this->format_reward_amount( $earned_amount ),
						$order->get_order_number()
					),
				)
			);

			$order->update_meta_data( '_kd_bonus_earned_amount', $earned_amount );
			$this->maybe_send_reward_email( $order, $user_id, $earned_amount, $new_balance );
		}

		update_user_meta( $user_id, self::STATUS_META, sanitize_text_field( $status_after['name'] ) );

		if ( $status_before['name'] !== $status_after['name'] ) {
			$this->maybe_send_status_email( $order, $user_id, $status_after );
		}

		$order->update_meta_data( '_kd_bonus_processed', 1 );
		$order->update_meta_data( '_kd_bonus_eligible_subtotal', $eligible_subtotal );
		$order->update_meta_data( '_kd_bonus_membership_status', $status_after['name'] );
		$order->save();

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( self::SESSION_REDEMPTION_KEY );
		}

		do_action( 'kd_bonus_order_rewards_processed', $order->get_id(), $user_id, $eligible_subtotal, $earned_amount, $status_after );
	}

	/**
	 * Reverse previous accruals on cancellation/refund.
	 *
	 * @param int $order_id Order ID.
	 */
	public function handle_order_reversal( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! $order->get_meta( '_kd_bonus_processed', true ) ) {
			return;
		}

		if ( $order->get_meta( '_kd_bonus_reversed', true ) ) {
			return;
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$eligible_subtotal = (float) $order->get_meta( '_kd_bonus_eligible_subtotal', true );
		if ( $eligible_subtotal > 0 ) {
			$this->adjust_lifetime_spend( $user_id, -1 * $eligible_subtotal, false );
		}

		$earned_amount = (float) $order->get_meta( '_kd_bonus_earned_amount', true );
		if ( $earned_amount > 0 ) {
			$this->adjust_balance(
				$user_id,
				-1 * $earned_amount,
				'earn_reversal',
				array(
					'site_id'     => get_current_blog_id(),
					'order_id'    => $order->get_id(),
					'currency'    => $this->get_base_currency(),
					'description' => sprintf(
						__( 'Reversed reward accrual from order #%s', 'kd-bonus' ),
						$order->get_order_number()
					),
				)
			);
		}

		$redeemed_base = (float) $order->get_meta( '_kd_bonus_redemption_base', true );
		if ( $redeemed_base > 0 && $order->get_meta( '_kd_bonus_redemption_processed', true ) ) {
			$this->adjust_balance(
				$user_id,
				$redeemed_base,
				'redeem_reversal',
				array(
					'site_id'     => get_current_blog_id(),
					'order_id'    => $order->get_id(),
					'currency'    => $this->get_base_currency(),
					'description' => sprintf(
						__( 'Returned redeemed KD Bonus from order #%s', 'kd-bonus' ),
						$order->get_order_number()
					),
				)
			);
		}

		update_user_meta( $user_id, self::STATUS_META, sanitize_text_field( $this->get_user_status( $user_id )['name'] ) );

		$order->update_meta_data( '_kd_bonus_reversed', 1 );
		$order->save();
	}

	/**
	 * Get the order subtotal eligible for membership and reward calculations.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return float
	 */
	public function get_order_eligible_subtotal( $order ) {
		$eligible_subtotal = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$line_total = (float) $item->get_total();
			$eligible_subtotal += (float) apply_filters( 'kd_bonus_eligible_line_total', $line_total, $item, $order );
		}

		return max( 0, $eligible_subtotal );
	}

	/**
	 * Settle any redemption amount stored on the order.
	 *
	 * @param WC_Order $order   Order object.
	 * @param int      $user_id User ID.
	 */
	private function settle_redemption_for_order( $order, $user_id ) {
		$redeemed_base = (float) $order->get_meta( '_kd_bonus_redemption_base', true );
		if ( $redeemed_base <= 0 || $order->get_meta( '_kd_bonus_redemption_processed', true ) ) {
			return;
		}

		$this->adjust_balance(
			$user_id,
			-1 * $redeemed_base,
			'redeem',
			array(
				'site_id'     => get_current_blog_id(),
				'order_id'    => $order->get_id(),
				'currency'    => $this->get_base_currency(),
				'description' => sprintf(
					__( 'Redeemed KD Bonus on order #%s', 'kd-bonus' ),
					$order->get_order_number()
				),
			)
		);

		$order->update_meta_data( '_kd_bonus_redemption_processed', 1 );
	}

	/**
	 * Adjust a user's balance and write a transaction row.
	 *
	 * @param int    $user_id User ID.
	 * @param float  $delta Delta amount.
	 * @param string $type Transaction type.
	 * @param array  $context Transaction context.
	 * @return float
	 */
	private function adjust_balance( $user_id, $delta, $type, $context = array(), $allow_negative = true ) {
		$balance_change = $this->atomic_adjust_user_meta_decimal_with_details(
			$user_id,
			self::BALANCE_META,
			(float) $delta,
			$allow_negative,
			array(
				'touch_last_earned_at' => 'earn' === $type,
			)
		);
		$actual_delta   = (float) $balance_change['delta'];
		$new_balance    = (float) $balance_change['current'];

		if ( 0.0 === $actual_delta ) {
			return $new_balance;
		}

		$this->insert_transaction(
			array(
				'user_id'       => $user_id,
				'site_id'       => isset( $context['site_id'] ) ? (int) $context['site_id'] : get_current_blog_id(),
				'order_id'      => isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
				'type'          => $type,
				'amount'        => $actual_delta,
				'balance_after' => $new_balance,
				'currency'      => isset( $context['currency'] ) ? (string) $context['currency'] : $this->get_base_currency(),
				'description'   => isset( $context['description'] ) ? (string) $context['description'] : '',
			)
		);

		return $new_balance;
	}

	/**
	 * Atomically adjust lifetime spend.
	 *
	 * @param int   $user_id User ID.
	 * @param float $delta Spend delta.
	 * @param bool  $allow_negative Whether negative totals are allowed.
	 * @return float
	 */
	private function adjust_lifetime_spend( $user_id, $delta, $allow_negative = true ) {
		return $this->atomic_adjust_user_meta_decimal( $user_id, self::LIFETIME_SPEND_META, (float) $delta, $allow_negative );
	}

	/**
	 * Atomically adjust a decimal user meta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Meta key.
	 * @param float  $delta Delta amount.
	 * @param bool   $allow_negative Whether the resulting total may be negative.
	 * @return float
	 */
	private function atomic_adjust_user_meta_decimal( $user_id, $meta_key, $delta, $allow_negative = true ) {
		$details = $this->atomic_adjust_user_meta_decimal_with_details( $user_id, $meta_key, $delta, $allow_negative );

		return (float) $details['current'];
	}

	/**
	 * Atomically adjust a decimal user meta value and return the applied change details.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Meta key.
	 * @param float  $delta Delta amount.
	 * @param bool   $allow_negative Whether the resulting total may be negative.
	 * @param array  $options Optional behavior flags.
	 * @return array<string,float>
	 */
	private function atomic_adjust_user_meta_decimal_with_details( $user_id, $meta_key, $delta, $allow_negative = true, $options = array() ) {
		global $wpdb;

		$lock_key      = 'kd_bonus_meta_' . md5( $user_id . '|' . $meta_key );
		$lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key ) );

		if ( 1 !== $lock_acquired ) {
			$current = (float) get_user_meta( $user_id, $meta_key, true );

			return array(
				'previous' => $current,
				'current'  => $current,
				'delta'    => 0.0,
			);
		}

		try {
			$current   = $this->get_user_meta_decimal_direct( $user_id, $meta_key );
			$new_value = $current + (float) $delta;
			if ( ! $allow_negative ) {
				$new_value = max( 0, $new_value );
			}

			$this->upsert_user_meta_value(
				$user_id,
				$meta_key,
				$this->format_decimal( $new_value, 4 )
			);

			// Keep the latest earn timestamp in sync before releasing the balance lock so
			// expiry re-checks observe the refreshed deposit time together with the new balance.
			if ( ! empty( $options['touch_last_earned_at'] ) && ( $new_value - $current ) > 0 ) {
				$this->upsert_user_meta_value( $user_id, self::LAST_EARNED_AT_META, (string) time() );
			}

			wp_cache_delete( $user_id, 'user_meta' );
			clean_user_cache( $user_id );

			return array(
				'previous' => $current,
				'current'  => $new_value,
				'delta'    => $new_value - $current,
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		}
	}

	/**
	 * Insert a transaction row.
	 *
	 * @param array<string,mixed> $row Row data.
	 */
	private function insert_transaction( $row ) {
		global $wpdb;

		$wpdb->insert(
			self::get_table_name(),
			array(
				'user_id'       => (int) $row['user_id'],
				'site_id'       => (int) $row['site_id'],
				'order_id'      => (int) $row['order_id'],
				'type'          => sanitize_key( $row['type'] ),
				'amount'        => $this->format_decimal( $row['amount'], 4 ),
				'balance_after' => $this->format_decimal( $row['balance_after'], 4 ),
				'currency'      => sanitize_text_field( $row['currency'] ),
				'description'   => sanitize_text_field( $row['description'] ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%f', '%f', '%s', '%s', '%s' )
		);
	}

	/**
	 * Maybe send reward issuance email.
	 *
	 * @param WC_Order $order Order object.
	 * @param int      $user_id User ID.
	 * @param float    $earned_amount Earned amount.
	 * @param float    $balance Balance after earn.
	 */
	private function maybe_send_reward_email( $order, $user_id, $earned_amount, $balance ) {
		$settings = $this->get_reward_settings();
		if ( empty( $settings['email_notifications'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$replacements = array(
			'{customer_name}' => $user->display_name ?: $user->user_login,
			'{reward_amount}' => $this->format_reward_amount( $earned_amount ),
			'{reward_symbol}' => $settings['reward_symbol'],
			'{order_number}'  => $order->get_order_number(),
			'{balance_amount}' => $this->format_reward_amount( $balance ),
		);

		wp_mail(
			$user->user_email,
			strtr( $settings['reward_email_subject'], $replacements ),
			strtr( $settings['reward_email_body'], $replacements )
		);
	}

	/**
	 * Maybe send membership upgrade email.
	 *
	 * @param WC_Order              $order Order object.
	 * @param int                   $user_id User ID.
	 * @param array<string,mixed>   $status New status.
	 */
	private function maybe_send_status_email( $order, $user_id, $status ) {
		$settings = $this->get_reward_settings();
		if ( empty( $settings['email_notifications'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$replacements = array(
			'{customer_name}' => $user->display_name ?: $user->user_login,
			'{status_name}'   => $status['name'],
		);

		wp_mail(
			$user->user_email,
			strtr( $settings['upgrade_email_subject'], $replacements ),
			strtr( $settings['upgrade_email_body'], $replacements )
		);
	}

	/**
	 * Read current session redemption amount in base reward units.
	 *
	 * @return float
	 */
	private function get_session_redemption_base() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 0;
		}

		return max( 0, (float) WC()->session->get( self::SESSION_REDEMPTION_KEY, 0 ) );
	}

	/**
	 * Get the maximum order value the customer can cover right now.
	 *
	 * @return float
	 */
	private function get_checkout_redemption_cap() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$cart = WC()->cart;
		$cap  = (float) $cart->get_subtotal() + (float) $cart->get_cart_contents_tax() + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();

		return max( 0, $cap );
	}

	/**
	 * Check whether the order used coupon-based discounts.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	private function order_has_coupon_discount( $order ) {
		$coupon_codes = method_exists( $order, 'get_coupon_codes' ) ? $order->get_coupon_codes() : array();
		if ( ! empty( $coupon_codes ) ) {
			return true;
		}

		return ! empty( $order->get_items( 'coupon' ) );
	}

	/**
	 * Expire a customer's unused balance when the configured threshold has passed.
	 *
	 * @param int $user_id User ID.
	 */
	private function maybe_expire_user_balance( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		if ( isset( $this->expiry_checked_users[ $user_id ] ) ) {
			return;
		}

		$this->expiry_checked_users[ $user_id ] = true;

		$expiry_days = $this->get_reward_expiry_days();
		if ( $expiry_days <= 0 ) {
			return;
		}

		$last_earned_at = $this->get_last_earned_timestamp( $user_id );
		if ( $last_earned_at <= 0 ) {
			return;
		}

		$expiry_seconds = $expiry_days * DAY_IN_SECONDS;
		if ( ( $last_earned_at + $expiry_seconds ) > time() ) {
			return;
		}

		// This is only a fast-path check. expire_stale_balance() repeats the expiry test
		// under the balance lock before it zeroes any stored points.
		$expiry_result = $this->expire_stale_balance( $user_id, $expiry_seconds );
		if ( $expiry_result['expired'] <= 0 ) {
			return;
		}

		$this->insert_transaction(
			array(
				'user_id'       => $user_id,
				'site_id'       => get_current_blog_id(),
				'order_id'      => 0,
				'type'          => 'expire',
				'amount'        => -1 * $expiry_result['expired'],
				'balance_after' => $expiry_result['current'],
				'currency'      => $this->get_base_currency(),
				'description'   => __( 'Expired unused KD Bonus balance.', 'kd-bonus' ),
			)
		);
	}

	/**
	 * Resolve the timestamp of the user's last reward deposit.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function get_last_earned_timestamp( $user_id ) {
		global $wpdb;

		$timestamp = (int) get_user_meta( $user_id, self::LAST_EARNED_AT_META, true );
		if ( $timestamp > 0 ) {
			return $timestamp;
		}

		$table_name = self::get_table_name();
		$created_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at
				FROM {$table_name}
				WHERE user_id = %d
					AND type = %s
					AND amount > 0
				ORDER BY id DESC
				LIMIT 1",
				$user_id,
				'earn'
			)
		);

		if ( empty( $created_at ) ) {
			return 0;
		}

		$datetime = date_create_immutable( $created_at, new DateTimeZone( 'UTC' ) );
		if ( false === $datetime ) {
			return 0;
		}

		$timestamp = $datetime->getTimestamp();

		$this->upsert_user_meta_value( $user_id, self::LAST_EARNED_AT_META, (string) $timestamp );
		wp_cache_delete( $user_id, 'user_meta' );
		clean_user_cache( $user_id );

		return (int) $timestamp;
	}

	/**
	 * Expire stale balance under lock using the live stored values.
	 *
	 * @param int $user_id User ID.
	 * @param int $expiry_seconds Expiry age in seconds.
	 * @return array<string,float>
	 */
	private function expire_stale_balance( $user_id, $expiry_seconds ) {
		global $wpdb;

		$lock_key      = 'kd_bonus_meta_' . md5( $user_id . '|' . self::BALANCE_META );
		$lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key ) );

		if ( 1 !== $lock_acquired ) {
			return array(
				'expired' => 0.0,
				'current' => (float) get_user_meta( $user_id, self::BALANCE_META, true ),
			);
		}

		try {
			$current_balance = $this->get_user_meta_decimal_direct( $user_id, self::BALANCE_META );
			$last_earned_at  = (int) $this->get_user_meta_value_direct( $user_id, self::LAST_EARNED_AT_META, '0' );

			if ( $current_balance <= 0 || $last_earned_at <= 0 || ( $last_earned_at + $expiry_seconds ) > time() ) {
				return array(
					'expired' => 0.0,
					'current' => $current_balance,
				);
			}

			$this->upsert_user_meta_value( $user_id, self::BALANCE_META, $this->format_decimal( 0, 4 ) );
			wp_cache_delete( $user_id, 'user_meta' );
			clean_user_cache( $user_id );

			return array(
				'expired' => $current_balance,
				'current' => 0.0,
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		}
	}

	/**
	 * Read a user meta value directly from the database.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Meta key.
	 * @param string $default Default value.
	 * @return string
	 */
	private function get_user_meta_value_direct( $user_id, $meta_key, $default = '' ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value
				FROM {$wpdb->usermeta}
				WHERE user_id = %d
					AND meta_key = %s
				ORDER BY umeta_id ASC
				LIMIT 1",
				$user_id,
				$meta_key
			)
		);

		return null === $value ? (string) $default : (string) $value;
	}

	/**
	 * Read a decimal user meta value directly from the database.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Meta key.
	 * @return float
	 */
	private function get_user_meta_decimal_direct( $user_id, $meta_key ) {
		return (float) $this->get_user_meta_value_direct( $user_id, $meta_key, '0' );
	}

	/**
	 * Insert or update a user meta value directly.
	 *
	 * @param int    $user_id User ID.
	 * @param string $meta_key Meta key.
	 * @param string $meta_value Meta value.
	 */
	private function upsert_user_meta_value( $user_id, $meta_key, $meta_value ) {
		update_user_meta( $user_id, $meta_key, $meta_value );
	}

	/**
	 * Format decimals without requiring WooCommerce helpers.
	 *
	 * @param float $value Value to format.
	 * @param int   $decimals Number of decimals.
	 * @return string
	 */
	private function format_decimal( $value, $decimals ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return wc_format_decimal( $value, $decimals );
		}

		return number_format( (float) $value, (int) $decimals, '.', '' );
	}
}
