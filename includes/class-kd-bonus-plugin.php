<?php
/**
 * Core plugin bootstrap.
 *
 * @package KD_Bonus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KD_Bonus_Plugin {
	/**
	 * Plugin instance.
	 *
	 * @var KD_Bonus_Plugin|null
	 */
	private static $instance = null;

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
	 * Dashboard handler.
	 *
	 * @var KD_Bonus_Dashboard
	 */
	private $dashboard;

	/**
	 * Initialize plugin singleton.
	 *
	 * @return KD_Bonus_Plugin
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings  = new KD_Bonus_Settings();
		$this->rewards   = new KD_Bonus_Rewards( $this->settings );
		$this->dashboard = new KD_Bonus_Dashboard( $this->settings, $this->rewards );

		register_activation_hook( KD_BONUS_PLUGIN_FILE, array( __CLASS__, 'activate' ) );

		add_action( 'plugins_loaded', array( $this, 'load' ) );
		add_action( 'admin_notices', array( $this, 'render_single_site_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_woocommerce_notice' ) );
		add_action( 'wp_initialize_site', array( $this, 'provision_site_dashboard_page' ), 20, 1 );
	}

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 */
	public static function activate( $network_wide ) {
		KD_Bonus_Settings::ensure_defaults();
		KD_Bonus_Rewards::create_transaction_table();

		if ( is_multisite() && $network_wide ) {
			self::ensure_dashboard_pages_for_all_sites();

			return;
		}

		self::ensure_dashboard_page();
	}

	/**
	 * Load runtime hooks.
	 */
	public function load() {
		$this->settings->register();
		$this->dashboard->register();

		if ( class_exists( 'WooCommerce' ) ) {
			$this->rewards->register();
		}
	}

	/**
	 * Provision dashboard page for newly created sites.
	 *
	 * @param WP_Site $new_site New site object.
	 */
	public function provision_site_dashboard_page( $new_site ) {
		if ( ! $new_site instanceof WP_Site ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		self::ensure_dashboard_page();
		restore_current_blog();
	}

	/**
	 * Ensure dashboard pages exist across all network sites.
	 */
	public static function ensure_dashboard_pages_for_all_sites() {
		$offset = 0;
		$limit  = 100;

		do {
			$sites = get_sites(
				array(
					'number' => $limit,
					'offset' => $offset,
					'fields' => 'ids',
				)
			);

			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::ensure_dashboard_page();
				restore_current_blog();
			}

			$offset += $limit;
		} while ( count( $sites ) === $limit );
	}

	/**
	 * Ensure a customer dashboard page exists on the current site.
	 */
	public static function ensure_dashboard_page() {
		$settings = KD_Bonus_Settings::get_settings();

		if ( empty( $settings['auto_create_dashboard_page'] ) ) {
			return;
		}

		$slug    = ! empty( $settings['dashboard_page_slug'] ) ? sanitize_title( $settings['dashboard_page_slug'] ) : 'kd-bonus-dashboard';
		$page_id = (int) get_option( 'kd_bonus_dashboard_page_id', 0 );

		if ( $page_id > 0 && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		$existing_page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing_page instanceof WP_Post ) {
			update_option( 'kd_bonus_dashboard_page_id', (int) $existing_page->ID );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'KD Bonus Dashboard', 'kd-bonus' ),
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[kd_bonus_dashboard]',
			),
			true
		);

		if ( ! is_wp_error( $page_id ) ) {
			update_option( 'kd_bonus_dashboard_page_id', (int) $page_id );
		}
	}

	/**
	 * Render multisite-only admin notice.
	 */
	public function render_single_site_notice() {
		if ( is_multisite() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'KD Bonus is intended for WordPress multisite networks and should be network-activated from Network Admin.', 'kd-bonus' ) . '</p></div>';
	}

	/**
	 * Render WooCommerce availability notice in Network Admin.
	 */
	public function render_woocommerce_notice() {
		if ( ! is_network_admin() || class_exists( 'WooCommerce' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'KD Bonus is active, but WooCommerce was not detected. Reward accrual, dashboard balances, and checkout redemption remain on standby until WooCommerce is available.', 'kd-bonus' ) . '</p></div>';
	}
}
