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
	 * Network option key for membership rebuild progress/state.
	 */
	const MEMBERSHIP_REBUILD_STATE_OPTION = 'kd_bonus_membership_rebuild_state';

	/**
	 * Legacy cron hook key kept only to clear stale scheduled events from older versions.
	 */
	const MEMBERSHIP_REBUILD_CRON_HOOK = 'kd_bonus_process_membership_rebuild_batch';

	/**
	 * Orders to process in one batch.
	 */
	const MEMBERSHIP_REBUILD_ORDER_BATCH = 100;

	/**
	 * Users to process in one batch.
	 */
	const MEMBERSHIP_REBUILD_USER_BATCH = 200;

	/**
	 * Maximum number of recent rebuild progress log lines stored in state.
	 */
	const MEMBERSHIP_REBUILD_RECENT_LOG_LIMIT = 80;

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
	 * Manual membership override meta key.
	 */
	const STATUS_OVERRIDE_META = 'kd_bonus_membership_status_override';

	/**
	 * Last reward deposit timestamp meta key.
	 */
	const LAST_EARNED_AT_META = 'kd_bonus_last_earned_at';

	/**
	 * Maximum number of retained reward event rows.
	 */
	const MAX_EVENT_LOG_ROWS = 5000;

	/**
	 * Network admin submenu slug for the users with rewards page.
	 */
	const USERS_WITH_REWARDS_SUBMENU_SLUG = 'kd-bonus-users-with-rewards';

	/**
	 * Users shown per page on the network rewards users list.
	 */
	const USERS_WITH_REWARDS_PER_PAGE = 50;

	/**
	 * Session key for base redemption amount.
	 */
	const SESSION_REDEMPTION_KEY = 'kd_bonus_redemption_base';

	/**
	 * User meta key marking that the one-time new-account reward has been applied.
	 */
	const NEW_ACCOUNT_REWARD_AWARDED_META = 'kd_bonus_new_account_reward_awarded';

	/**
	 * User meta key storing the expiry timestamp already reminded for.
	 */
	const EXPIRY_REMINDER_SENT_FOR_META = 'kd_bonus_expiry_reminder_sent_for';

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
		add_action( 'show_user_profile', array( $this, 'render_user_profile_rewards_section' ), 1 );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_rewards_section' ), 1 );
		add_action( 'personal_options_update', array( $this, 'save_user_profile_rewards_section' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_profile_rewards_section' ) );
		add_action( 'user_profile_update_errors', array( $this, 'validate_user_profile_rewards_section' ), 10, 3 );
		add_action( 'wp_ajax_kd_bonus_save_profile_rewards_section', array( $this, 'ajax_save_user_profile_rewards_section' ) );
		add_action( 'network_admin_menu', array( $this, 'register_event_log_submenu' ) );
		add_action( 'kd_bonus_request_membership_rebuild', array( $this, 'start_membership_rebuild' ), 10, 2 );
		add_action( 'kd_bonus_continue_membership_rebuild', array( $this, 'continue_membership_rebuild' ) );
		add_action( 'kd_bonus_revoke_membership_rebuild', array( $this, 'revoke_membership_rebuild' ) );
		add_action( 'wp_ajax_kd_bonus_membership_rebuild_progress', array( $this, 'ajax_membership_rebuild_progress' ) );
		add_action( 'user_register', array( $this, 'handle_new_user_reward' ) );

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
	 * Read membership rebuild state from network options.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_membership_rebuild_state() {
		$state = get_network_option( null, self::MEMBERSHIP_REBUILD_STATE_OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Cancel and clean up a running or stalled membership rebuild.
	 *
	 * Unschedules the rebuild cron hook and deletes the rebuild state so the
	 * Run Rebuild button becomes active again.
	 */
	public function revoke_membership_rebuild() {
		$main_site_id = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 0;
		$current_id   = get_current_blog_id();
		$switched     = $main_site_id > 0 && $main_site_id !== $current_id;

		if ( $switched ) {
			switch_to_blog( $main_site_id );
		}

		wp_clear_scheduled_hook( self::MEMBERSHIP_REBUILD_CRON_HOOK );

		if ( $switched ) {
			restore_current_blog();
		}

		delete_network_option( null, self::MEMBERSHIP_REBUILD_STATE_OPTION );
	}

	/**
	 * Start or resume membership rebuild from historical order spend.
	 *
	 * @param int $initiator_user_id Administrator user ID.
	 * @param int $reset_enabled     1 to reset existing Bonus Status values before rebuild, 0 to skip.
	 */
	public function start_membership_rebuild( $initiator_user_id = 0, $reset_enabled = 1 ) {
		$state = self::get_membership_rebuild_state();
		if ( ! empty( $state['running'] ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			$this->mark_membership_rebuild_failed(
				__( 'WooCommerce is required to rebuild memberships from historical spend.', 'kd-bonus' ),
				array()
			);
			return;
		}

		global $wpdb;

		$site_ids = array_map( 'absint', get_sites( array( 'fields' => 'ids' ) ) );
		if ( empty( $site_ids ) ) {
			$site_ids = array( get_current_blog_id() );
		}

		$rebuild_statuses = $this->get_membership_rebuild_order_statuses();
		$total_orders = 0;
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$page_data    = $this->get_orders_page_for_rebuild( $rebuild_statuses, 1, 1 );
			$total_orders += (int) $page_data['total'];
			restore_current_blog();
		}

		$reset_enabled      = (int) $reset_enabled;
		$has_stored_status  = $this->users_have_stored_bonus_status();
		$do_reset           = $reset_enabled && $has_stored_status;

		if ( $do_reset ) {
			$initial_phase   = 'reset_users';
			$initial_message = __( 'Rebuild initialized. Click "Continue rebuild" to process the next batch (reset users phase).', 'kd-bonus' );
		} elseif ( $reset_enabled && ! $has_stored_status ) {
			$initial_phase   = 'scan_orders';
			$initial_message = __( 'Rebuild initialized. Reset skipped automatically: no existing Bonus Status data found. Click "Continue rebuild" to process order scanning batches.', 'kd-bonus' );
		} else {
			$initial_phase   = 'scan_orders';
			$initial_message = __( 'Rebuild initialized. Reset phase skipped. Click "Continue rebuild" to process order scanning batches.', 'kd-bonus' );
		}

		$state = array(
			'running'                  => 1,
			'status'                   => 'running',
			'phase'                    => $initial_phase,
			'message'                  => $initial_message,
			'reset_enabled'            => $do_reset ? 1 : 0,
			'reset_skipped'            => $do_reset ? 0 : 1,
			'initiator_user_id'        => absint( $initiator_user_id ),
			'started_at'               => time(),
			'updated_at'               => time(),
			'finished_at'              => 0,
			'total_users'              => (int) $wpdb->get_var( "SELECT COUNT(1) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'user_reset_last_id'       => 0,
			'user_reset_processed'     => 0,
			'status_rebuild_last_id'   => 0,
			'status_rebuild_processed' => 0,
			'site_ids'                 => $site_ids,
			'site_index'               => 0,
			'order_page'               => 1,
			'eligible_order_statuses'  => $rebuild_statuses,
			'total_orders'             => $total_orders,
			'processed_orders'         => 0,
			'recent_logs'              => array(),
		);

		$this->save_membership_rebuild_state( $state );
	}

	/**
	 * Continue one rebuild batch from persisted state.
	 */
	public function continue_membership_rebuild() {
		$state = self::get_membership_rebuild_state();
		if ( empty( $state['running'] ) ) {
			return;
		}

		$this->process_membership_rebuild_batch();
	}

	/**
	 * Process one batch of the membership rebuild pipeline.
	 */
	public function process_membership_rebuild_batch() {
		$state = self::get_membership_rebuild_state();
		if ( empty( $state['running'] ) ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->mark_membership_rebuild_failed( __( 'WooCommerce is required to rebuild memberships from historical spend.', 'kd-bonus' ), $state );
			return;
		}

		$phase = isset( $state['phase'] ) ? (string) $state['phase'] : 'reset_users';

		switch ( $phase ) {
			case 'reset_users':
				$this->process_membership_rebuild_user_reset_batch( $state );
				break;
			case 'scan_orders':
				$this->process_membership_rebuild_order_batch( $state );
				break;
			case 'rebuild_statuses':
				$this->process_membership_rebuild_status_batch( $state );
				break;
			default:
				$this->mark_membership_rebuild_complete( $state );
				break;
		}
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
	 * Register the network admin reward event log submenu.
	 */
	public function register_event_log_submenu() {
		add_submenu_page(
			KD_Bonus_Settings::MENU_SLUG,
			__( 'Reward Event Log', 'kd-bonus' ),
			__( 'Reward Event Log', 'kd-bonus' ),
			'manage_network_options',
			'kd-bonus-events',
			array( $this, 'render_event_log_page' )
		);

		add_submenu_page(
			KD_Bonus_Settings::MENU_SLUG,
			__( 'Accounts with Rewards', 'kd-bonus' ),
			__( 'Accounts with Rewards', 'kd-bonus' ),
			'manage_network_options',
			self::USERS_WITH_REWARDS_SUBMENU_SLUG,
			array( $this, 'render_users_with_rewards_page' )
		);
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
	 * Get the configured reminder lead time before unused points expire.
	 *
	 * @return int
	 */
	public function get_reward_expiry_notification_days() {
		$settings = $this->get_reward_settings();

		return max( 0, absint( $settings['reward_expiry_notification_days'] ?? 0 ) );
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
			'priority'       => PHP_INT_MAX,
		);

		foreach ( $this->get_membership_statuses() as $status ) {
			$threshold = isset( $status['threshold'] ) ? (float) $status['threshold'] : 0.0;
			$priority  = isset( $status['priority'] ) ? (int) $status['priority'] : 0;

			if ( $lifetime_spend < $threshold ) {
				continue;
			}

			$current_threshold = isset( $current['threshold'] ) ? (float) $current['threshold'] : 0.0;
			$current_priority  = isset( $current['priority'] ) ? (int) $current['priority'] : PHP_INT_MAX;

			if ( $threshold > $current_threshold || ( $threshold === $current_threshold && $priority < $current_priority ) ) {
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
		$override_status = $this->get_status_by_name( $this->get_manual_status_override( $user_id ) );
		if ( ! empty( $override_status ) ) {
			$override_status['is_manual'] = true;

			return $override_status;
		}

		$lifetime_spend = $this->get_lifetime_spend( $user_id );
		$status         = $this->get_status_for_spend( $lifetime_spend );
		$status['is_manual'] = false;

		return $status;
	}

	/**
	 * Get a user's stored manual membership override.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function get_manual_status_override( $user_id ) {
		return trim( (string) get_user_meta( $user_id, self::STATUS_OVERRIDE_META, true ) );
	}

	/**
	 * Get a membership status definition by name.
	 *
	 * @param string $status_name Status name.
	 * @return array<string,mixed>
	 */
	public function get_status_by_name( $status_name ) {
		$status_name = trim( (string) $status_name );
		if ( '' === $status_name ) {
			return array();
		}

		foreach ( $this->get_membership_statuses() as $status ) {
			if ( isset( $status['name'] ) && 0 === strcasecmp( (string) $status['name'], $status_name ) ) {
				return $status;
			}
		}

		return array();
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
		$manual_status   = $this->get_manual_status_override( $user_id );
		$computed_status = $this->get_status_for_spend( $lifetime_spend );
		$status_name     = '';

		if ( '' !== $manual_status ) {
			$status_name = $manual_status;
		} elseif ( $lifetime_spend > 0 ) {
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
	 * Format a reward event type label for display.
	 *
	 * @param string $type Event type key.
	 * @return string
	 */
	private function format_reward_event_type_label( $type ) {
		return ucwords( str_replace( '_', ' ', (string) $type ) );
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
		$amount   = (float) $amount;
		$scale    = abs( $amount - round( $amount ) ) < 0.00001 ? 0 : ( function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );

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
		return $this->get_reward_event_log( $limit, $user_id );
	}

	/**
	 * Read reward event log rows.
	 *
	 * @param int $limit   Maximum number of rows.
	 * @param int $user_id Optional user filter.
	 * @param int $offset  Number of rows to skip (for pagination).
	 * @return array<int,object>
	 */
	public function get_reward_event_log( $limit = 20, $user_id = 0, $offset = 0 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$limit      = max( 1, (int) $limit );
		$offset     = max( 0, (int) $offset );

		if ( $user_id > 0 ) {
			$query = $wpdb->prepare(
				"SELECT id, user_id, site_id, order_id, type, amount, balance_after, currency, description, created_at
				FROM {$table_name}
				WHERE user_id = %d
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT id, user_id, site_id, order_id, type, amount, balance_after, currency, description, created_at
				FROM {$table_name}
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
		}

		return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count reward event log rows.
	 *
	 * @param int $user_id Optional user filter.
	 * @return int
	 */
	public function get_reward_event_log_count( $user_id = 0 ) {
		global $wpdb;

		$table_name = self::get_table_name();

		if ( $user_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
					$user_id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Read the latest reward activity row for each requested user.
	 *
	 * @param array<int,int> $user_ids User IDs.
	 * @return array<int,object>
	 */
	private function get_latest_reward_activity_for_users( $user_ids ) {
		global $wpdb;

		$user_ids = array_values( array_filter( array_map( 'absint', $user_ids ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		$table_name   = self::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
		$query        = $wpdb->prepare(
			"SELECT event.user_id, event.type, event.created_at
			FROM {$table_name} AS event
			INNER JOIN (
				SELECT user_id, MAX(id) AS latest_id
				FROM {$table_name}
				WHERE user_id IN ({$placeholders})
				GROUP BY user_id
			) AS latest ON latest.latest_id = event.id",
			$user_ids
		);
		$rows         = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$activities   = array();

		foreach ( $rows as $row ) {
			$activities[ (int) $row->user_id ] = $row;
		}

		return $activities;
	}

	/**
	 * Get expiry information for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,int|bool>
	 */
	public function get_user_expiry_data( $user_id ) {
		$expiry_days    = $this->get_reward_expiry_days();
		$last_earned_at = $this->get_last_reward_deposit_timestamp( $user_id );
		$expires_at     = 0;

		if ( $expiry_days > 0 && $last_earned_at > 0 ) {
			$expires_at = $last_earned_at + ( $expiry_days * DAY_IN_SECONDS );
		}

		return array(
			'expiry_days'    => $expiry_days,
			'last_earned_at' => $last_earned_at,
			'expires_at'     => $expires_at,
			'is_expired'     => $expires_at > 0 && $expires_at <= time(),
		);
	}

	/**
	 * Get the last reward deposit timestamp for a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_last_reward_deposit_timestamp( $user_id ) {
		return $this->get_last_earned_timestamp( $user_id );
	}

	/**
	 * Get stored KD Bonus metadata for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public function get_bonus_metadata( $user_id ) {
		$metadata = array();
		$user_meta = get_user_meta( $user_id );

		foreach ( $user_meta as $meta_key => $meta_values ) {
			if ( 0 !== strpos( (string) $meta_key, 'kd_bonus_' ) ) {
				continue;
			}

			if ( 1 === count( $meta_values ) ) {
				$metadata[ $meta_key ] = maybe_unserialize( $meta_values[0] );
			} else {
				$metadata[ $meta_key ] = array_map( 'maybe_unserialize', $meta_values );
			}
		}

		ksort( $metadata );

		return $metadata;
	}

	/**
	 * Validate profile reward section submissions.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @param bool     $update Whether this is a user update.
	 * @param stdClass $user User object.
	 */
	public function validate_user_profile_rewards_section( $errors, $update, $user ) {
		if ( ! $update || empty( $_POST['kd_bonus_profile_rewards_nonce'] ) ) {
			return;
		}

		if ( ! $this->can_manage_user_bonus( $user->ID ) ) {
			$errors->add( 'kd_bonus_permissions', __( 'You do not have permission to manage KD Bonus rewards for this user.', 'kd-bonus' ) );
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kd_bonus_profile_rewards_nonce'] ) ), 'kd_bonus_profile_rewards_' . $user->ID ) ) {
			$errors->add( 'kd_bonus_nonce', __( 'The KD Bonus profile update could not be verified.', 'kd-bonus' ) );
			return;
		}

		$amount = $this->sanitize_decimal_input( wp_unslash( $_POST['kd_bonus_adjustment_amount'] ?? '' ) );
		$action = sanitize_key( wp_unslash( $_POST['kd_bonus_adjustment_action'] ?? '' ) );
		$note   = trim( sanitize_textarea_field( wp_unslash( $_POST['kd_bonus_adjustment_note'] ?? '' ) ) );

		if ( $amount > 0 && ! in_array( $action, array( 'add', 'deduct' ), true ) ) {
			$errors->add( 'kd_bonus_adjustment_action', __( 'Choose whether to add or deduct KD Bonus rewards.', 'kd-bonus' ) );
		}

		if ( $amount > 0 && '' === $note ) {
			$errors->add( 'kd_bonus_adjustment_note', __( 'A note or remark is required for every manual KD Bonus adjustment.', 'kd-bonus' ) );
		}

		$status_choice = sanitize_text_field( wp_unslash( $_POST['kd_bonus_membership_status'] ?? '' ) );
		if ( '' !== $status_choice && '__automatic__' !== $status_choice && empty( $this->get_status_by_name( $status_choice ) ) ) {
			$errors->add( 'kd_bonus_membership_status', __( 'The selected KD Bonus membership status is not valid.', 'kd-bonus' ) );
		}
	}

	/**
	 * Save profile reward section updates.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_user_profile_rewards_section( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || empty( $_POST['kd_bonus_profile_rewards_nonce'] ) ) {
			return;
		}

		if ( ! $this->can_manage_user_bonus( $user_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kd_bonus_profile_rewards_nonce'] ) ), 'kd_bonus_profile_rewards_' . $user_id ) ) {
			return;
		}

		$note            = trim( sanitize_textarea_field( wp_unslash( $_POST['kd_bonus_adjustment_note'] ?? '' ) ) );
		$status_choice    = sanitize_text_field( wp_unslash( $_POST['kd_bonus_membership_status'] ?? '' ) );
		$amount           = $this->sanitize_decimal_input( wp_unslash( $_POST['kd_bonus_adjustment_amount'] ?? '' ) );
		$action           = sanitize_key( wp_unslash( $_POST['kd_bonus_adjustment_action'] ?? '' ) );

		$this->process_user_profile_rewards_section_update( $user_id, $note, $status_choice, $amount, $action );
	}

	/**
	 * Save reward section values from AJAX without a full profile-page submit.
	 */
	public function ajax_save_user_profile_rewards_section() {
		$user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( $user_id <= 0 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Missing user context.', 'kd-bonus' ),
				),
				400
			);
		}

		if ( ! $this->can_manage_user_bonus( $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to manage KD Bonus rewards for this user.', 'kd-bonus' ),
				),
				403
			);
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kd_bonus_profile_rewards_nonce'] ?? '' ) ), 'kd_bonus_profile_rewards_' . $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The KD Bonus profile update could not be verified.', 'kd-bonus' ),
				),
				403
			);
		}

		$amount = $this->sanitize_decimal_input( wp_unslash( $_POST['kd_bonus_adjustment_amount'] ?? '' ) );
		$action = sanitize_key( wp_unslash( $_POST['kd_bonus_adjustment_action'] ?? '' ) );
		$note   = trim( sanitize_textarea_field( wp_unslash( $_POST['kd_bonus_adjustment_note'] ?? '' ) ) );
		if ( $amount > 0 && ! in_array( $action, array( 'add', 'deduct' ), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Choose whether to add or deduct KD Bonus rewards.', 'kd-bonus' ),
				),
				400
			);
		}

		if ( $amount > 0 && '' === $note ) {
			wp_send_json_error(
				array(
					'message' => __( 'A note or remark is required for every manual KD Bonus adjustment.', 'kd-bonus' ),
				),
				400
			);
		}

		$status_choice = sanitize_text_field( wp_unslash( $_POST['kd_bonus_membership_status'] ?? '' ) );
		if ( '' !== $status_choice && '__automatic__' !== $status_choice && empty( $this->get_status_by_name( $status_choice ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'The selected KD Bonus membership status is not valid.', 'kd-bonus' ),
				),
				400
			);
		}

		$this->process_user_profile_rewards_section_update( $user_id, $note, $status_choice, $amount, $action );

		wp_send_json_success(
			array(
				'message' => __( 'KD Rewards and Bonuses table saved.', 'kd-bonus' ),
			)
		);
	}

	/**
	 * Process and persist profile reward section values.
	 *
	 * @param int    $user_id User ID.
	 * @param string $note Adjustment note.
	 * @param string $status_choice Membership override choice.
	 * @param float  $amount Adjustment amount.
	 * @param string $action Adjustment action.
	 */
	private function process_user_profile_rewards_section_update( $user_id, $note, $status_choice, $amount, $action ) {
		$effective_before = $this->get_user_status( $user_id );
		$effective_after  = $effective_before;

		if ( '__automatic__' === $status_choice ) {
			delete_user_meta( $user_id, self::STATUS_OVERRIDE_META );
		} elseif ( '' !== $status_choice && ! empty( $this->get_status_by_name( $status_choice ) ) ) {
			update_user_meta( $user_id, self::STATUS_OVERRIDE_META, $status_choice );
		}

		$effective_after = $this->get_user_status( $user_id );
		update_user_meta( $user_id, self::STATUS_META, sanitize_text_field( $effective_after['name'] ?? '' ) );

		if ( ( $effective_before['name'] ?? '' ) !== ( $effective_after['name'] ?? '' ) ) {
			$this->record_status_change_event(
				$user_id,
				$effective_before['name'] ?? '',
				$effective_after['name'] ?? '',
				sprintf(
					/* translators: 1: previous membership status, 2: new membership status, 3: optional administrator note sentence. */
					__( 'Administrator updated membership status from %1$s to %2$s.%3$s', 'kd-bonus' ),
					$effective_before['name'] ?: __( 'No status', 'kd-bonus' ),
					$effective_after['name'] ?: __( 'No status', 'kd-bonus' ),
					'' !== $note ? ' ' . sprintf( __( 'Note: %s', 'kd-bonus' ), $note ) : ''
				),
				array(
					'site_id' => get_current_blog_id(),
				)
			);
		}

		if ( $amount > 0 && in_array( $action, array( 'add', 'deduct' ), true ) && '' !== $note ) {
			$this->apply_manual_adjustment( $user_id, $action, $amount, $note );
		}
	}

	/**
	 * Render the user profile reward management section.
	 *
	 * @param WP_User $user User being edited.
	 */
	public function render_user_profile_rewards_section( $user ) {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$balance          = $this->get_balance( $user->ID );
		$available_balance = $this->get_available_balance( $user->ID );
		$lifetime_spend   = $this->get_lifetime_spend( $user->ID );
		$effective_status = $this->get_user_status( $user->ID );
		$computed_status  = $this->get_status_for_spend( $lifetime_spend );
		$override_status  = $this->get_manual_status_override( $user->ID );
		$override_status  = ! empty( $this->get_status_by_name( $override_status ) ) ? $override_status : '';
		$history          = $this->get_transaction_history( $user->ID, 12 );
		$expiry           = $this->get_user_expiry_data( $user->ID );
		$metadata         = $this->get_bonus_metadata( $user->ID );
		$can_manage       = $this->can_manage_user_bonus( $user->ID );
		$computed_rate    = $this->format_decimal( (float) ( $computed_status['reward_percent'] ?? 0 ), 2 );
		?>
		<div id="kd-bonus-profile-section">
		<h2><?php esc_html_e( 'KD Rewards and Bonuses', 'kd-bonus' ); ?></h2>
		<table class="form-table" role="presentation" style="background:#fff8c5;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Current Balance', 'kd-bonus' ); ?></th>
					<td><?php echo esc_html( $this->format_reward_amount( $balance ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Available Balance', 'kd-bonus' ); ?></th>
					<td><?php echo esc_html( $this->format_reward_amount( $available_balance ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Membership Status', 'kd-bonus' ); ?></th>
					<td>
						<strong><?php echo esc_html( $effective_status['name'] ?? __( 'No status', 'kd-bonus' ) ); ?></strong>
						<?php if ( ! empty( $effective_status['is_manual'] ) ) : ?>
							<span class="description"><?php esc_html_e( 'Manual override', 'kd-bonus' ); ?></span>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Automatic', 'kd-bonus' ); ?></span>
						<?php endif; ?>
						<br />
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: computed status, 2: reward percent. */
									__( 'Computed status: %1$s (%2$s reward rate)', 'kd-bonus' ),
									$computed_status['name'] ?? __( 'No status', 'kd-bonus' ),
									$computed_rate . '%'
								)
							);
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last Reward Deposit', 'kd-bonus' ); ?></th>
					<td>
						<?php echo esc_html( $expiry['last_earned_at'] > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $expiry['last_earned_at'] ) : __( 'Never', 'kd-bonus' ) ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Reward Expiry', 'kd-bonus' ); ?></th>
					<td>
						<?php
						if ( empty( $expiry['expiry_days'] ) ) {
							esc_html_e( 'Disabled', 'kd-bonus' );
						} elseif ( ! empty( $expiry['expires_at'] ) ) {
							echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $expiry['expires_at'] ) );
							echo ! empty( $expiry['is_expired'] ) ? ' — ' . esc_html__( 'Expired', 'kd-bonus' ) : ' — ' . esc_html__( 'Scheduled', 'kd-bonus' );
						} else {
							esc_html_e( 'Waiting for first reward deposit', 'kd-bonus' );
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Lifetime Eligible Spend', 'kd-bonus' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $lifetime_spend, 2 ) . ' ' . $this->get_base_currency() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Stored Bonus Metadata', 'kd-bonus' ); ?></th>
					<td>
						<?php if ( empty( $metadata ) ) : ?>
							<p><?php esc_html_e( 'No KD Bonus metadata stored for this user yet.', 'kd-bonus' ); ?></p>
						<?php else : ?>
							<table class="widefat striped" style="max-width:100%;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Meta Key', 'kd-bonus' ); ?></th>
										<th><?php esc_html_e( 'Value', 'kd-bonus' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $metadata as $meta_key => $meta_value ) : ?>
										<tr>
											<td><code><?php echo esc_html( $meta_key ); ?></code></td>
											<td><code><?php echo esc_html( is_scalar( $meta_value ) ? (string) $meta_value : wp_json_encode( $meta_value ) ); ?></code></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $can_manage ) : ?>
					<tr>
						<th><label for="kd_bonus_adjustment_action"><?php esc_html_e( 'Manual Reward Adjustment', 'kd-bonus' ); ?></label></th>
						<td>
							<?php wp_nonce_field( 'kd_bonus_profile_rewards_' . $user->ID, 'kd_bonus_profile_rewards_nonce' ); ?>
							<select name="kd_bonus_adjustment_action" id="kd_bonus_adjustment_action">
								<option value=""><?php esc_html_e( 'No adjustment', 'kd-bonus' ); ?></option>
								<option value="add"><?php esc_html_e( 'Add rewards', 'kd-bonus' ); ?></option>
								<option value="deduct"><?php esc_html_e( 'Deduct rewards', 'kd-bonus' ); ?></option>
							</select>
							<input type="number" min="0" step="0.01" class="small-text" name="kd_bonus_adjustment_amount" id="kd_bonus_adjustment_amount" value="" />
							<p class="description"><?php esc_html_e( 'Enter a positive amount. A note/remark is required whenever rewards are added or deducted.', 'kd-bonus' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="kd_bonus_adjustment_note"><?php esc_html_e( 'Adjustment Note / Remark', 'kd-bonus' ); ?></label></th>
						<td>
							<textarea name="kd_bonus_adjustment_note" id="kd_bonus_adjustment_note" rows="3" class="large-text"></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="kd_bonus_membership_status"><?php esc_html_e( 'Membership Status Override', 'kd-bonus' ); ?></label></th>
						<td>
							<select name="kd_bonus_membership_status" id="kd_bonus_membership_status">
								<option value="__automatic__" <?php selected( $override_status, '' ); ?>><?php esc_html_e( 'Automatic (use lifetime spend)', 'kd-bonus' ); ?></option>
								<?php foreach ( $this->get_membership_statuses() as $status ) : ?>
									<option value="<?php echo esc_attr( $status['name'] ); ?>" <?php selected( $override_status, $status['name'] ); ?>><?php echo esc_html( $status['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose a manual override or leave the user on automatic membership progression.', 'kd-bonus' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Save KD Rewards & Bonuses', 'kd-bonus' ); ?></th>
						<td>
							<button type="button" class="button button-primary" id="kd_bonus_save_rb_table" data-user-id="<?php echo esc_attr( $user->ID ); ?>"><?php esc_html_e( 'Save KD Rewards & Bonuses', 'kd-bonus' ); ?></button>
							<span id="kd_bonus_save_rb_status" style="margin-left:8px;"></span>
						</td>
					</tr>
					<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Recent Reward Events', 'kd-bonus' ); ?></th>
					<td>
						<?php if ( empty( $history ) ) : ?>
							<p><?php esc_html_e( 'No reward activity has been recorded for this user yet.', 'kd-bonus' ); ?></p>
						<?php else : ?>
							<table class="widefat striped" style="max-width:100%;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Date', 'kd-bonus' ); ?></th>
										<th><?php esc_html_e( 'Type', 'kd-bonus' ); ?></th>
										<th><?php esc_html_e( 'Amount', 'kd-bonus' ); ?></th>
										<th><?php esc_html_e( 'Balance After', 'kd-bonus' ); ?></th>
										<th><?php esc_html_e( 'Order', 'kd-bonus' ); ?></th>
										<th><?php esc_html_e( 'Details', 'kd-bonus' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $history as $entry ) : ?>
										<tr>
											<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $entry->created_at . ' UTC' ) ) ); ?></td>
											<td><?php echo esc_html( $this->format_reward_event_type_label( $entry->type ) ); ?></td>
											<td><?php echo esc_html( $this->format_reward_amount( (float) $entry->amount ) ); ?></td>
											<td><?php echo esc_html( $this->format_reward_amount( (float) $entry->balance_after ) ); ?></td>
											<td>
												<?php if ( ! empty( $entry->order_id ) && in_array( (string) $entry->type, array( 'earn', 'order_earn' ), true ) ) : ?>
													<a href="<?php echo esc_url( get_admin_url( (int) $entry->site_id, 'post.php?post=' . (int) $entry->order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( (string) absint( $entry->order_id ) ); ?></a>
												<?php elseif ( ! empty( $entry->order_id ) ) : ?>
													<?php echo esc_html( '#' . absint( $entry->order_id ) ); ?>
												<?php else : ?>
													&mdash;
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( $entry->description ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		</div>
		<script>
			(function () {
				function initKDBonusProfileSection() {
					const section = document.getElementById('kd-bonus-profile-section');
					const profileForm = document.getElementById('your-profile');
					if (section && profileForm && profileForm.firstChild) {
						profileForm.insertBefore(section, profileForm.firstChild);
					}

					const saveButton = document.getElementById('kd_bonus_save_rb_table');
					const statusNode = document.getElementById('kd_bonus_save_rb_status');
					if (!saveButton || !profileForm || typeof ajaxurl === 'undefined') {
						return;
					}

					saveButton.addEventListener('click', function () {
						const formData = new FormData(profileForm);
						formData.append('action', 'kd_bonus_save_profile_rewards_section');
						formData.append('user_id', saveButton.getAttribute('data-user-id') || '');

						saveButton.disabled = true;
						if (statusNode) {
							statusNode.style.color = '#50575e';
							statusNode.textContent = '<?php echo esc_js( __( 'Saving…', 'kd-bonus' ) ); ?>';
						}

						fetch(ajaxurl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						})
							.then(function (response) {
								return response.json();
							})
							.then(function (payload) {
								if (payload && payload.success) {
									if (statusNode) {
										statusNode.style.color = '#008a20';
										statusNode.textContent = (payload.data && payload.data.message) ? payload.data.message : '<?php echo esc_js( __( 'Saved.', 'kd-bonus' ) ); ?>';
									}
									return;
								}

								const message = payload && payload.data && payload.data.message ? payload.data.message : '<?php echo esc_js( __( 'Could not save the table.', 'kd-bonus' ) ); ?>';
								throw new Error(message);
							})
							.catch(function (error) {
								if (statusNode) {
									statusNode.style.color = '#d63638';
									statusNode.textContent = error && error.message ? error.message : '<?php echo esc_js( __( 'Could not save the table.', 'kd-bonus' ) ); ?>';
								}
							})
							.finally(function () {
								saveButton.disabled = false;
							});
					});
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initKDBonusProfileSection);
				} else {
					initKDBonusProfileSection();
				}
			})();
		</script>
		<?php
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
			WC()->cart->calculate_totals();
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
						<button class="button button-secondary" type="submit" name="kd_bonus_checkout_action" value="remove"><?php esc_html_e( 'Remove $KD', 'kd-bonus' ); ?></button>
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

		// Deduct balance and log the usage event immediately so checkout redemptions
		// appear in the Reward Event Log as negative entries even before the order
		// reaches the award status. settle_redemption_for_order() reads meta from the
		// in-memory order object, so the values set above via update_meta_data() are
		// available without a prior save().
		$user_id = (int) $order->get_user_id();
		if ( $user_id > 0 ) {
			$this->settle_redemption_for_order( $order, $user_id );
		}
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
		$status_before     = $this->get_user_status( $user_id );

		$this->settle_redemption_for_order( $order, $user_id );

		$this->adjust_lifetime_spend( $user_id, $eligible_subtotal );

		$status_after  = $this->get_user_status( $user_id );
		$earned_amount = 0.0;

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
			$this->record_status_change_event(
				$user_id,
				$status_before['name'],
				$status_after['name'],
				sprintf(
					/* translators: 1: previous status, 2: new status, 3: order number. */
					__( 'Membership status changed from %1$s to %2$s after order #%3$s.', 'kd-bonus' ),
					$status_before['name'],
					$status_after['name'],
					$order->get_order_number()
				),
				array(
					'site_id'  => get_current_blog_id(),
					'order_id' => $order->get_id(),
				)
			);
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

		$status_before = $this->get_user_status( $user_id );

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

		$status_after = $this->get_user_status( $user_id );
		update_user_meta( $user_id, self::STATUS_META, sanitize_text_field( $status_after['name'] ) );

		if ( ( $status_before['name'] ?? '' ) !== ( $status_after['name'] ?? '' ) ) {
			$this->record_status_change_event(
				$user_id,
				$status_before['name'] ?? '',
				$status_after['name'] ?? '',
				sprintf(
					/* translators: 1: previous status, 2: new status, 3: order number. */
					__( 'Membership status changed from %1$s to %2$s after order #%3$s was reversed.', 'kd-bonus' ),
					$status_before['name'] ?: __( 'No status', 'kd-bonus' ),
					$status_after['name'] ?: __( 'No status', 'kd-bonus' ),
					$order->get_order_number()
				),
				array(
					'site_id'  => get_current_blog_id(),
					'order_id' => $order->get_id(),
				)
			);
		}

		$order->update_meta_data( '_kd_bonus_reversed', 1 );
		$order->save();
	}

	/**
	 * Award configured one-time rewards when a user account is created.
	 *
	 * @param int $user_id User ID.
	 */
	public function handle_new_user_reward( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return;
		}

		$settings = $this->get_reward_settings();
		if ( empty( $settings['reward_new_user'] ) ) {
			return;
		}

		$reward_amount = $this->sanitize_decimal_input( $settings['new_user_reward_amount'] ?? 0 );
		if ( $reward_amount <= 0 ) {
			return;
		}

		if ( ! add_user_meta( $user_id, self::NEW_ACCOUNT_REWARD_AWARDED_META, 1, true ) ) {
			return;
		}

		$previous_balance = $this->get_balance( $user_id );
		$new_balance      = $this->adjust_balance(
			$user_id,
			$reward_amount,
			'new_account_reward',
			array(
				'site_id'              => get_current_blog_id(),
				'currency'             => $this->get_base_currency(),
				'description'          => __( 'New account Reward', 'kd-bonus' ),
				'touch_last_earned_at' => true,
			)
		);

		if ( $new_balance <= $previous_balance ) {
			delete_user_meta( $user_id, self::NEW_ACCOUNT_REWARD_AWARDED_META );
			return;
		}

		$this->maybe_send_new_account_reward_email( $user_id, $reward_amount, $new_balance );
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
				'touch_last_earned_at' => ! empty( $context['touch_last_earned_at'] ) || 'earn' === $type,
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

		$this->prune_reward_event_log();
	}

	/**
	 * Keep only the latest reward event rows.
	 */
	private function prune_reward_event_log() {
		global $wpdb;

		$table_name = self::get_table_name();
		$row_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $row_count <= self::MAX_EVENT_LOG_ROWS ) {
			return;
		}

		$delete_count = $row_count - self::MAX_EVENT_LOG_ROWS;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} ORDER BY id ASC LIMIT %d", $delete_count ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Apply a manual administrator reward adjustment.
	 *
	 * @param int    $user_id User ID.
	 * @param string $action Adjustment action.
	 * @param float  $amount Requested amount.
	 * @param string $note Administrator note.
	 * @return float
	 */
	private function apply_manual_adjustment( $user_id, $action, $amount, $note ) {
		$action = sanitize_key( $action );
		$amount = max( 0, (float) $amount );

		if ( $amount <= 0 || '' === $note ) {
			return $this->get_balance( $user_id );
		}

		if ( 'deduct' === $action ) {
			$amount = min( $amount, $this->get_balance( $user_id ) );
			if ( $amount <= 0 ) {
				return $this->get_balance( $user_id );
			}

			return $this->adjust_balance(
				$user_id,
				-1 * $amount,
				'manual_deduct',
				array(
					'site_id'     => get_current_blog_id(),
					'currency'    => $this->get_base_currency(),
					'description' => sprintf( __( 'Administrator deducted rewards. Note: %s', 'kd-bonus' ), $note ),
				),
				false
			);
		}

		return $this->adjust_balance(
			$user_id,
			$amount,
			'manual_add',
			array(
				'site_id'              => get_current_blog_id(),
				'currency'             => $this->get_base_currency(),
				'description'          => sprintf( __( 'Administrator added rewards. Note: %s', 'kd-bonus' ), $note ),
				'touch_last_earned_at' => true,
			)
		);
	}

	/**
	 * Record a status change event in the reward log.
	 *
	 * @param int    $user_id User ID.
	 * @param string $from_status Previous status.
	 * @param string $to_status New status.
	 * @param string $description Event description.
	 * @param array  $context Optional event context.
	 */
	private function record_status_change_event( $user_id, $from_status, $to_status, $description, $context = array() ) {
		if ( $from_status === $to_status ) {
			return;
		}

		$this->insert_transaction(
			array(
				'user_id'       => $user_id,
				'site_id'       => isset( $context['site_id'] ) ? (int) $context['site_id'] : get_current_blog_id(),
				'order_id'      => isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
				'type'          => 'status_change',
				'amount'        => 0,
				'balance_after' => $this->get_balance( $user_id ),
				'currency'      => $this->get_base_currency(),
				'description'   => $description,
			)
		);
	}

	/**
	 * Render the network-wide reward event log page.
	 */
	public function render_event_log_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the KD Bonus reward event log.', 'kd-bonus' ) );
		}

		$allowed_per_page = array( 10, 20, 40, 80, 160, 300, 500 );
		$per_page         = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 300; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $per_page, $allowed_per_page, true ) ) {
			$per_page = 300;
		}
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total       = $this->get_reward_event_log_count();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$offset      = ( $page - 1 ) * $per_page;
		$events      = $this->get_reward_event_log( $per_page, 0, $offset );
		$event_users = array();

		if ( ! empty( $events ) ) {
			$user_ids = array_values( array_unique( array_map( 'absint', wp_list_pluck( $events, 'user_id' ) ) ) );
			// Use blog_id => 0 to fetch users from all sites in the network so the
			// User column always resolves login + email consistently.
			foreach ( get_users( array( 'include' => $user_ids, 'blog_id' => 0 ) ) as $event_user ) {
				if ( $event_user instanceof WP_User ) {
					$event_users[ $event_user->ID ] = $event_user;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KD Bonus Reward Event Log', 'kd-bonus' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'The plugin keeps the latest %d reward events and prunes older records automatically. Showing %d per page.', 'kd-bonus' ), self::MAX_EVENT_LOG_ROWS, $per_page ) ); ?></p>

			<form method="get" style="margin: 12px 0 16px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
				<label for="kd-bonus-per-page"><?php esc_html_e( 'Logs per page:', 'kd-bonus' ); ?></label>
				<select id="kd-bonus-per-page" name="per_page" onchange="this.form.submit()">
					<?php foreach ( $allowed_per_page as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $per_page, $option ); ?>>
							<?php echo esc_html( number_format_i18n( $option ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="hidden" name="paged" value="1">
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'User', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Type', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Balance After', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Order', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Details', 'kd-bonus' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $events ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No reward events have been recorded yet.', 'kd-bonus' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $events as $event ) : ?>
							<?php $event_user = $event_users[ (int) $event->user_id ] ?? null; ?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->created_at . ' UTC' ) ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										$event_user instanceof WP_User
											? sprintf( '%1$s (%2$s)', $event_user->user_login, $event_user->user_email )
											: sprintf( '#%d', $event->user_id )
									);
									?>
								</td>
								<td><?php echo esc_html( $this->format_reward_event_type_label( $event->type ) ); ?></td>
								<td><?php echo esc_html( $this->format_reward_amount( (float) $event->amount ) ); ?></td>
								<td><?php echo esc_html( $this->format_reward_amount( (float) $event->balance_after ) ); ?></td>
								<td><?php echo esc_html( $event->order_id ? '#' . (int) $event->order_id : '—' ); ?></td>
								<td>
								<?php
								if ( ! empty( $event->order_id ) ) {
									$order_url = get_admin_url(
										(int) $event->site_id,
										'post.php?post=' . (int) $event->order_id . '&action=edit'
									);
									echo wp_kses(
										sprintf(
											'%s <a href="%s">#%d</a>',
											esc_html( $event->description ),
											esc_url( $order_url ),
											(int) $event->order_id
										),
										array( 'a' => array( 'href' => array() ) )
									);
								} else {
									echo esc_html( $event->description );
								}
								?>
							</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
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
		</div>
		<?php
	}

	/**
	 * Render the network-wide accounts with rewards page.
	 */
	public function render_users_with_rewards_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view accounts with rewards.', 'kd-bonus' ) );
		}

		$page        = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page    = self::USERS_WITH_REWARDS_PER_PAGE;
		$users_query = new WP_User_Query(
			array(
				'number'      => $per_page,
				'offset'      => ( $page - 1 ) * $per_page,
				'orderby'     => 'meta_value_num',
				'order'       => 'DESC',
				'meta_key'    => self::BALANCE_META,
				'meta_type'   => 'DECIMAL',
				'blog_id'     => 0,
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => self::BALANCE_META,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'DECIMAL',
					),
					array(
						'key'     => self::BALANCE_META,
						'value'   => 0,
						'compare' => '<',
						'type'    => 'DECIMAL',
					),
				),
				'count_total' => true,
			)
		);
		$query_results = $users_query->get_results();
		$user_ids      = wp_list_pluck( $query_results, 'ID' );
		if ( ! empty( $user_ids ) ) {
			update_meta_cache( 'user', $user_ids );
		}
		$latest_activity_rows = $this->get_latest_reward_activity_for_users( $user_ids );
		$users                = array();
		foreach ( $query_results as $user ) {
			$balance = $this->get_balance( $user->ID );

			$users[] = array(
				'user'           => $user,
				'balance'        => $balance,
				'status'         => $this->get_user_status( $user->ID ),
				'lifetime_spend' => $this->get_lifetime_spend( $user->ID ),
				'last_activity'  => $latest_activity_rows[ $user->ID ] ?? null,
			);
		}
		$total_users      = (int) $users_query->get_total();
		$total_pages      = max( 1, (int) ceil( $total_users / $per_page ) );
		$balance_decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$base_currency    = $this->get_base_currency();
		$date_time_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Accounts with Rewards', 'kd-bonus' ); ?></h1>
			<p><?php esc_html_e( 'Showing network users with reward balances above or below zero. Positive balances are listed first.', 'kd-bonus' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Membership Status', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Lifetime Eligible Spend', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Last Reward Activity Date', 'kd-bonus' ); ?></th>
						<th><?php esc_html_e( 'Last Reward Event Type', 'kd-bonus' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $users ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No users currently have a non-zero reward balance.', 'kd-bonus' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $users as $user_data ) : ?>
							<?php
							$user              = $user_data['user'];
							$balance           = $user_data['balance'];
							$status            = $user_data['status'];
							$status_name       = is_array( $status ) && isset( $status['name'] ) ? $status['name'] : __( 'No status', 'kd-bonus' );
							$lifetime_spend    = (float) $user_data['lifetime_spend'];
							$last_activity     = $user_data['last_activity'];
							$first_name        = trim( (string) $user->first_name );
							$last_name         = trim( (string) $user->last_name );
							$user_name         = trim( implode( ' ', array_filter( array( $first_name, $last_name ) ) ) );
							if ( '' === $user_name ) {
								$user_name = '' !== $user->display_name ? $user->display_name : sprintf( __( 'User #%d', 'kd-bonus' ), $user->ID );
							}
							$edit_profile_link = network_admin_url( 'user-edit.php?user_id=' . $user->ID );
							?>
							<tr>
								<td><a href="<?php echo esc_url( $edit_profile_link ); ?>"><?php echo esc_html( $user_name ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( $balance, $balance_decimals ) ); ?></td>
								<td><?php echo esc_html( $status_name ); ?></td>
								<td>
									<?php
									echo esc_html(
										number_format_i18n( $lifetime_spend, 2 ) . ( '' !== $base_currency ? ' ' . $base_currency : '' )
									);
									?>
								</td>
								<td>
									<?php
									echo esc_html(
										$last_activity
											? wp_date( $date_time_format, strtotime( $last_activity->created_at . ' UTC' ) )
											: '—'
									);
									?>
								</td>
								<td><?php echo esc_html( $last_activity ? $this->format_reward_event_type_label( $last_activity->type ) : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages" style="margin: 16px 0;">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
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
		</div>
		<?php
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

		$this->send_reward_email(
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

		$this->send_reward_email(
			$user->user_email,
			strtr( $settings['upgrade_email_subject'], $replacements ),
			strtr( $settings['upgrade_email_body'], $replacements )
		);
	}

	/**
	 * Maybe send a new-account reward issuance email.
	 *
	 * @param int   $user_id User ID.
	 * @param float $reward_amount Reward amount.
	 * @param float $balance Balance after reward grant.
	 */
	private function maybe_send_new_account_reward_email( $user_id, $reward_amount, $balance ) {
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
			'{reward_amount}' => $this->format_reward_amount( $reward_amount ),
			'{reward_symbol}' => $settings['reward_symbol'],
			'{balance_amount}' => $this->format_reward_amount( $balance ),
		);

		$this->send_reward_email(
			$user->user_email,
			strtr( $settings['new_user_reward_email_subject'], $replacements ),
			strtr( $settings['new_user_reward_email_body'], $replacements )
		);
	}

	/**
	 * Maybe send a reward expiry reminder email.
	 *
	 * @param int   $user_id User ID.
	 * @param int   $expires_at Expiry timestamp.
	 * @param float $balance Current reward balance.
	 */
	private function maybe_send_expiry_reminder_email( $user_id, $expires_at, $balance ) {
		$settings = $this->get_reward_settings();
		if ( empty( $settings['email_notifications'] ) ) {
			return;
		}

		$lead_days = $this->get_reward_expiry_notification_days();
		if ( $lead_days <= 0 || $expires_at <= time() || $balance <= 0 ) {
			return;
		}

		$window_opens_at = $expires_at - ( $lead_days * DAY_IN_SECONDS );
		if ( time() < $window_opens_at ) {
			return;
		}

		if ( (int) get_user_meta( $user_id, self::EXPIRY_REMINDER_SENT_FOR_META, true ) === $expires_at ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$replacements = array(
			'{customer_name}'     => $user->display_name ?: $user->user_login,
			'{balance_amount}'    => $this->format_reward_amount( $balance ),
			'{reward_symbol}'     => $settings['reward_symbol'],
			'{expiry_date}'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires_at ),
			'{days_until_expiry}' => (string) max( 1, (int) ceil( ( $expires_at - time() ) / DAY_IN_SECONDS ) ),
		);

		$sent = $this->send_reward_email(
			$user->user_email,
			strtr( $settings['reward_expiry_notification_email_subject'], $replacements ),
			strtr( $settings['reward_expiry_notification_email_body'], $replacements )
		);

		if ( $sent ) {
			$this->upsert_user_meta_value( $user_id, self::EXPIRY_REMINDER_SENT_FOR_META, (string) $expires_at );
			wp_cache_delete( $user_id, 'user_meta' );
			clean_user_cache( $user_id );
		}
	}

	/**
	 * Send a reward email using HTML content generated from stored templates.
	 *
	 * @param string $to Recipient email.
	 * @param string $subject Subject line.
	 * @param string $body Email body template output.
	 * @return bool
	 */
	private function send_reward_email( $to, $subject, $body ) {
		return wp_mail(
			$to,
			wp_strip_all_tags( $subject ),
			do_shortcode( (string) $body ),
			array( 'Content-Type: text/html; charset=UTF-8' )
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
		$expires_at     = $last_earned_at + $expiry_seconds;
		$current_balance = (float) get_user_meta( $user_id, self::BALANCE_META, true );

		$this->maybe_send_expiry_reminder_email( $user_id, $expires_at, $current_balance );

		if ( $expires_at > time() ) {
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

	/**
	 * Determine whether the current administrator may manage a user's KD Bonus account.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function can_manage_user_bonus( $user_id ) {
		$user_id = absint( $user_id );

		return $user_id > 0 && current_user_can( 'edit_user', $user_id ) && ( current_user_can( 'manage_network_users' ) || current_user_can( 'edit_users' ) || current_user_can( 'promote_users' ) );
	}

	/**
	 * Sanitize a decimal input value without requiring WooCommerce.
	 *
	 * @param mixed $raw Raw input.
	 * @return float
	 */
	private function sanitize_decimal_input( $raw ) {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $raw );
		}

		$raw = is_scalar( $raw ) ? (string) $raw : '0';
		$raw = preg_replace( '/[^0-9,.\-]/', '', $raw );
		$raw = str_replace( ',', '.', $raw );
		if ( in_array( $raw, array( '', '-', '.', '-.' ), true ) || ! preg_match( '/^-?\d*(?:\.\d*)?$/', $raw ) ) {
			return 0.0;
		}

		return (float) $raw;
	}

	/**
	 * Persist membership rebuild state.
	 *
	 * @param array<string,mixed> $state State payload.
	 */
	private function save_membership_rebuild_state( $state ) {
		$state['updated_at'] = time();
		update_network_option( null, self::MEMBERSHIP_REBUILD_STATE_OPTION, $state );
	}

	/**
	 * Process one user-reset batch.
	 *
	 * @param array<string,mixed> $state State payload.
	 */
	private function process_membership_rebuild_user_reset_batch( $state ) {
		$user_ids = $this->get_user_ids_batch( (int) $state['user_reset_last_id'], self::MEMBERSHIP_REBUILD_USER_BATCH );
		if ( empty( $user_ids ) ) {
			$state['phase']         = 'scan_orders';
			$state['message']       = __( 'Scanning WooCommerce orders to rebuild lifetime spend.', 'kd-bonus' );
			$state['site_index']    = 0;
			$state['order_page']    = 1;
			$this->save_membership_rebuild_state( $state );
			return;
		}

		foreach ( $user_ids as $user_id ) {
			$this->upsert_user_meta_value( $user_id, self::LIFETIME_SPEND_META, $this->format_decimal( 0, 4 ) );
			delete_user_meta( $user_id, self::STATUS_META );
		}

		$state['user_reset_last_id']   = (int) end( $user_ids );
		$state['user_reset_processed'] = (int) $state['user_reset_processed'] + count( $user_ids );
		$this->save_membership_rebuild_state( $state );
	}

	/**
	 * Process one order-scanning batch.
	 *
	 * @param array<string,mixed> $state State payload.
	 */
	private function process_membership_rebuild_order_batch( $state ) {
		$site_ids   = isset( $state['site_ids'] ) && is_array( $state['site_ids'] ) ? array_values( array_map( 'absint', $state['site_ids'] ) ) : array();
		$site_index = (int) ( $state['site_index'] ?? 0 );

		if ( empty( $site_ids ) || $site_index >= count( $site_ids ) ) {
			$state['phase']                  = 'rebuild_statuses';
			$state['message']                = __( 'Updating membership statuses from rebuilt lifetime spend.', 'kd-bonus' );
			$state['status_rebuild_last_id'] = 0;
			$this->save_membership_rebuild_state( $state );
			return;
		}

		$site_id = (int) $site_ids[ $site_index ];
		$statuses = isset( $state['eligible_order_statuses'] ) && is_array( $state['eligible_order_statuses'] ) ? array_values( array_map( 'sanitize_key', $state['eligible_order_statuses'] ) ) : array();
		if ( empty( $statuses ) ) {
			$statuses = $this->get_membership_rebuild_order_statuses();
		}
		$order_page   = max( 1, (int) ( $state['order_page'] ?? 1 ) );
		$spend_deltas = array();
		$orders       = array();
		$max_pages    = 0;

		switch_to_blog( $site_id );
		try {
			$page_data = $this->get_orders_page_for_rebuild( $statuses, $order_page, self::MEMBERSHIP_REBUILD_ORDER_BATCH );
			$orders    = isset( $page_data['orders'] ) && is_array( $page_data['orders'] ) ? $page_data['orders'] : array();
			$max_pages = isset( $page_data['max_pages'] ) ? (int) $page_data['max_pages'] : 0;

			if ( ! empty( $orders ) ) {
				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order ) {
						$order = wc_get_order( $order );
					}
					if ( ! $order instanceof WC_Order ) {
						continue;
					}

					$user_id = (int) $order->get_user_id();
					if ( $user_id <= 0 ) {
						continue;
					}

					$eligible_subtotal = $this->get_order_eligible_subtotal( $order );
					if ( $eligible_subtotal <= 0 ) {
						continue;
					}

					if ( ! isset( $spend_deltas[ $user_id ] ) ) {
						$spend_deltas[ $user_id ] = 0.0;
					}
					$spend_deltas[ $user_id ] += (float) $eligible_subtotal;
				}
			}
		} finally {
			restore_current_blog();
		}

		if ( empty( $orders ) ) {
			$state['site_index']    = $site_index + 1;
			$state['order_page']    = 1;
			$this->save_membership_rebuild_state( $state );
			return;
		}

		foreach ( $spend_deltas as $user_id => $delta ) {
			$this->adjust_lifetime_spend( (int) $user_id, (float) $delta );
		}

		$state['processed_orders'] = (int) $state['processed_orders'] + count( $orders );
		if ( $max_pages > 0 && $order_page < $max_pages ) {
			$state['order_page'] = $order_page + 1;
		} else {
			$state['site_index'] = $site_index + 1;
			$state['order_page'] = 1;
		}
		$this->save_membership_rebuild_state( $state );
	}

	/**
	 * Process one status rebuild batch.
	 *
	 * @param array<string,mixed> $state State payload.
	 */
	private function process_membership_rebuild_status_batch( $state ) {
		$user_ids = $this->get_user_ids_batch( (int) $state['status_rebuild_last_id'], self::MEMBERSHIP_REBUILD_USER_BATCH );
		if ( empty( $user_ids ) ) {
			$this->mark_membership_rebuild_complete( $state );
			return;
		}

		foreach ( $user_ids as $user_id ) {
			$lifetime_spend = $this->get_lifetime_spend( $user_id );
			$status         = $this->get_status_for_spend( $lifetime_spend );
			$status_name    = __( 'None', 'kd-bonus' );

			if ( $lifetime_spend > 0 && ! empty( $status['name'] ) ) {
				$status_name = sanitize_text_field( (string) $status['name'] );
				$this->upsert_user_meta_value( $user_id, self::STATUS_META, $status_name );
			} else {
				delete_user_meta( $user_id, self::STATUS_META );
			}

			$user = get_userdata( $user_id );
			$email = $user && ! empty( $user->user_email ) ? sanitize_email( (string) $user->user_email ) : sprintf( 'user-%d', (int) $user_id );
			$state['recent_logs'] = $this->append_membership_rebuild_log_line(
				$state,
				sprintf(
					/* translators: 1: user email, 2: lifetime spend, 3: new membership status name. */
					__( 'User Bonus Status Changed: %1$s | Lifetime spend: %2$s | New Status: %3$s', 'kd-bonus' ),
					$email,
					$this->format_decimal( $lifetime_spend, 2 ),
					$status_name
				)
			);
		}

		$state['status_rebuild_last_id']  = (int) end( $user_ids );
		$state['status_rebuild_processed'] = (int) $state['status_rebuild_processed'] + count( $user_ids );
		$this->save_membership_rebuild_state( $state );
	}

	/**
	 * Mark rebuild as completed.
	 *
	 * @param array<string,mixed> $state State payload.
	 */
	private function mark_membership_rebuild_complete( $state ) {
		$state['running']     = 0;
		$state['status']      = 'completed';
		$state['phase']       = 'completed';
		$state['finished_at'] = time();
		$state['message']     = __( 'Membership rebuild completed successfully.', 'kd-bonus' );
		$this->save_membership_rebuild_state( $state );
	}

	/**
	 * Mark rebuild as failed.
	 *
	 * @param string              $message Failure message.
	 * @param array<string,mixed> $state Existing state.
	 */
	private function mark_membership_rebuild_failed( $message, $state ) {
		$state['running']     = 0;
		$state['status']      = 'failed';
		$state['phase']       = 'failed';
		$state['finished_at'] = time();
		$state['message']     = (string) $message;
		$this->save_membership_rebuild_state( $state );
	}

	/**
	 * Check whether any user currently has a stored Bonus Status or override value.
	 *
	 * @return bool
	 */
	private function users_have_stored_bonus_status() {
		global $wpdb;

		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) AND meta_value != '' LIMIT 1",
				self::STATUS_META,
				self::STATUS_OVERRIDE_META
			)
		);

		return ! empty( $row );
	}

	/**
	 * Return all WooCommerce order statuses included in membership rebuild scans.
	 *
	 * @return array<int,string>
	 */
	private function get_membership_rebuild_order_statuses() {
		return array(
			'completed',
			'delivered',
			'processing',
			'uk-packed',
		);
	}

	/**
	 * Keep only a small tail of recent rebuild log lines.
	 *
	 * @param array<string,mixed> $state Current state.
	 * @param string              $line Log line.
	 * @return array<int,string>
	 */
	private function append_membership_rebuild_log_line( $state, $line ) {
		$logs   = isset( $state['recent_logs'] ) && is_array( $state['recent_logs'] ) ? $state['recent_logs'] : array();
		$logs[] = sanitize_text_field( (string) $line );
		$limit  = max( 1, (int) self::MEMBERSHIP_REBUILD_RECENT_LOG_LIMIT );
		if ( count( $logs ) > $limit ) {
			$logs = array_slice( $logs, 0 - $limit );
		}

		return array_values( $logs );
	}

	/**
	 * AJAX endpoint for polling current membership rebuild progress.
	 */
	public function ajax_membership_rebuild_progress() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to view membership rebuild progress.', 'kd-bonus' ),
				),
				403
			);
		}

		if ( ! check_ajax_referer( 'kd_bonus_membership_rebuild_progress', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed while reading membership rebuild progress.', 'kd-bonus' ),
				),
				400
			);
		}

		$state          = self::get_membership_rebuild_state();
		$status         = isset( $state['status'] ) ? sanitize_key( (string) $state['status'] ) : '';
		$phase          = isset( $state['phase'] ) ? sanitize_key( (string) $state['phase'] ) : '';
		$total_orders   = isset( $state['total_orders'] ) ? (int) $state['total_orders'] : 0;
		$done_orders    = isset( $state['processed_orders'] ) ? (int) $state['processed_orders'] : 0;
		$total_users    = isset( $state['total_users'] ) ? (int) $state['total_users'] : 0;
		$done_users     = isset( $state['status_rebuild_processed'] ) ? (int) $state['status_rebuild_processed'] : 0;
		$reset_users    = isset( $state['user_reset_processed'] ) ? (int) $state['user_reset_processed'] : 0;
		$reset_skipped  = ! empty( $state['reset_skipped'] );
		$recent_logs    = isset( $state['recent_logs'] ) && is_array( $state['recent_logs'] ) ? array_values( array_map( 'sanitize_text_field', $state['recent_logs'] ) ) : array();
		$message        = isset( $state['message'] ) ? sanitize_text_field( (string) $state['message'] ) : '';
		$is_running     = ! empty( $state['running'] );
		$is_completed   = ! $is_running && ( 'completed' === $status || 'completed' === $phase );
		$is_failed      = ! $is_running && ( 'failed' === $status || 'failed' === $phase );

		wp_send_json_success(
			array(
				'status'           => $status ? $status : ( $is_running ? 'running' : 'idle' ),
				'phase'            => $phase,
				'message'          => $message,
				'orders_processed' => $done_orders,
				'orders_total'     => $total_orders,
				'users_processed'  => $done_users,
				'users_total'      => $total_users,
				'users_reset'      => $reset_users,
				'reset_skipped'    => $reset_skipped,
				'recent_logs'      => $recent_logs,
				'running'          => $is_running,
				'completed'        => $is_completed,
				'failed'           => $is_failed,
			)
		);
	}

	/**
	 * Get a user ID batch after the given user ID.
	 *
	 * @param int $after_user_id Last processed user ID.
	 * @param int $limit Batch size.
	 * @return array<int,int>
	 */
	private function get_user_ids_batch( $after_user_id, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->users}
				WHERE ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				max( 0, (int) $after_user_id ),
				max( 1, (int) $limit )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'absint', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Normalize award status for WooCommerce order queries.
	 *
	 * @param string $award_status Raw configured status.
	 * @return string
	 */
	private function normalize_award_status_for_wc_query( $award_status ) {
		$award_status = sanitize_key( (string) $award_status );

		return 0 === strpos( $award_status, 'wc-' ) ? substr( $award_status, 3 ) : $award_status;
	}

	/**
	 * Read one paged set of orders for rebuild processing.
	 *
	 * @param array<int,string> $statuses WooCommerce status slugs without wc- prefix.
	 * @param int    $page Page number.
	 * @param int    $limit Batch size.
	 * @return array<string,mixed>
	 */
	private function get_orders_page_for_rebuild( $statuses, $page, $limit ) {
		$query = wc_get_orders(
			array(
				'type'     => 'shop_order',
				'status'   => $statuses,
				'limit'    => max( 1, (int) $limit ),
				'page'     => max( 1, (int) $page ),
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'ASC',
				'return'   => 'objects',
			)
		);

		if ( is_object( $query ) ) {
			return array(
				'orders'    => isset( $query->orders ) && is_array( $query->orders ) ? $query->orders : array(),
				'max_pages' => isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 0,
				'total'     => isset( $query->total ) ? (int) $query->total : 0,
			);
		}

		return array(
			'orders'    => array(),
			'max_pages' => 0,
			'total'     => 0,
		);
	}
}
