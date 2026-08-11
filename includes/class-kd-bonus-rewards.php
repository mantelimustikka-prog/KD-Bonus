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
		add_action( 'template_redirect', array( $this, 'handle_checkout_redemption_request' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_redemption_ui' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_checkout_redemption' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_checkout_redemption_on_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_completion' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completion' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_order_reversal' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_order_reversal' ) );
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
		$reward_percent = (float) $status_after['reward_percent'];
		$earned_amount  = round( $eligible_subtotal * ( $reward_percent / 100 ), wc_get_price_decimals() );

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
	private function adjust_balance( $user_id, $delta, $type, $context = array() ) {
		$new_balance = $this->atomic_adjust_user_meta_decimal( $user_id, self::BALANCE_META, (float) $delta );

		$this->insert_transaction(
			array(
				'user_id'       => $user_id,
				'site_id'       => isset( $context['site_id'] ) ? (int) $context['site_id'] : get_current_blog_id(),
				'order_id'      => isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
				'type'          => $type,
				'amount'        => (float) $delta,
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
		global $wpdb;

		$lock_key      = 'kd_bonus_meta_' . md5( $user_id . '|' . $meta_key );
		$lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_key ) );

		if ( 1 !== $lock_acquired ) {
			return (float) get_user_meta( $user_id, $meta_key, true );
		}

		if ( ! metadata_exists( 'user', $user_id, $meta_key ) ) {
			add_user_meta( $user_id, $meta_key, '0', true );
		}

		$delta_sql  = number_format( (float) $delta, 4, '.', '' );
		$expression = $allow_negative ? 'ROUND(CAST(meta_value AS DECIMAL(18,4)) + %s, 4)' : 'GREATEST(0, ROUND(CAST(meta_value AS DECIMAL(18,4)) + %s, 4))';
		$query      = $wpdb->prepare(
			"UPDATE {$wpdb->usermeta}
			SET meta_value = {$expression}
			WHERE user_id = %d
				AND meta_key = %s",
			$delta_sql,
			$user_id,
			$meta_key
		);

		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$value = (float) get_user_meta( $user_id, $meta_key, true );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );

		return $value;
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
				'amount'        => wc_format_decimal( $row['amount'], 4 ),
				'balance_after' => wc_format_decimal( $row['balance_after'], 4 ),
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
}
