<?php
/**
 * Plugin Name: KD Bonus
 * Plugin URI:  https://github.com/mantelimustikka-prog/KD-Bonus
 * Description: Multisite-ready global bonus and reward starter plugin for WooCommerce networks.
 * Version:     0.1.0
 * Author:      mantelimustikka-prog
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Network:     true
 * Text Domain: kd-bonus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KD_BONUS_VERSION', '0.1.0' );
define( 'KD_BONUS_PLUGIN_FILE', __FILE__ );
define( 'KD_BONUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KD_BONUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once KD_BONUS_PLUGIN_DIR . 'includes/class-kd-bonus-settings.php';
require_once KD_BONUS_PLUGIN_DIR . 'includes/class-kd-bonus-rewards.php';
require_once KD_BONUS_PLUGIN_DIR . 'includes/class-kd-bonus-dashboard.php';
require_once KD_BONUS_PLUGIN_DIR . 'includes/class-kd-bonus-plugin.php';

KD_Bonus_Plugin::init();
