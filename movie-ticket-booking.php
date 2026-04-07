<?php
/**
 * Plugin Name: Movie Ticket Booking
 * Plugin URI:  https://github.com/haziqfaruqi/movie-ticket-booking
 * Description: Full movie ticket booking system with WooCommerce integration and QR code tickets.
 * Version:     1.0.0
 * Author:      Haziq
 * Requires Plugins: woocommerce
 * Text Domain: mtb
 */

defined( 'ABSPATH' ) || exit;

define( 'MTB_VERSION', '1.0.0' );
define( 'MTB_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MTB_URL',     plugin_dir_url( __FILE__ ) );
define( 'MTB_TICKETS_DIR', WP_CONTENT_DIR . '/uploads/movie-tickets/' );
define( 'MTB_TICKETS_URL', WP_CONTENT_URL . '/uploads/movie-tickets/' );

// Autoload classes
foreach ( [
    'DB',
    'Movies',
    'Showtimes',
    'Seats',
    'Booking',
    'WooCommerce',
    'Scanner',
    'Shortcodes',
] as $class ) {
    require_once MTB_PATH . "includes/class-{$class}.php";
}

// Activation
register_activation_hook( __FILE__, function () {
    MTB_DB::create_tables();
    MTB_Movies::register_post_types();
    MTB_Showtimes::register_post_types();
    flush_rewrite_rules();
    // Create ticket upload dir
    wp_mkdir_p( MTB_TICKETS_DIR );
    file_put_contents( MTB_TICKETS_DIR . '.htaccess', 'Options -Indexes' );

    // Enable Cash on Delivery if no payment gateways are currently active,
    // so checkout works on fresh WooCommerce installs without manual config.
    $enabled = get_option( 'woocommerce_cod_settings', [] );
    if ( empty( $enabled['enabled'] ) || $enabled['enabled'] !== 'yes' ) {
        $enabled['enabled'] = 'yes';
        update_option( 'woocommerce_cod_settings', $enabled );
    }
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// Boot
add_action( 'init', function () {
    MTB_DB::maybe_upgrade();          // patches seat_rows/seat_cols if missing
    MTB_Movies::register_post_types();
    MTB_Showtimes::register_post_types();
    MTB_Shortcodes::register();
} );

add_action( 'admin_menu', [ 'MTB_Movies',    'admin_menus' ] );
add_action( 'admin_menu', [ 'MTB_Showtimes', 'admin_menus' ] );
add_action( 'admin_menu', [ 'MTB_Scanner',   'admin_menu'  ] );
add_action( 'add_meta_boxes', [ 'MTB_Movies',    'meta_boxes' ] );
add_action( 'add_meta_boxes', [ 'MTB_Showtimes', 'meta_boxes' ] );
add_action( 'save_post', [ 'MTB_Movies',    'save_meta' ] );
add_action( 'save_post', [ 'MTB_Showtimes', 'save_meta' ] );

MTB_WooCommerce::init();
MTB_Scanner::init();

// Enqueue frontend assets on ALL frontend pages
// wp_localize_script only works if the script is enqueued at wp_enqueue_scripts time.
// Registering only and enqueuing later (from inside a shortcode) means the
// localized MTB object never gets printed — so AJAX calls break with undefined errors.
add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(  'mtb-style',  MTB_URL . 'assets/booking.css',  [], MTB_VERSION );
    wp_enqueue_script( 'mtb-script', MTB_URL . 'assets/booking.js', [], MTB_VERSION, true );
    wp_localize_script( 'mtb-script', 'MTB', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'mtb_nonce' ),
    ] );
} );

// AJAX handlers
add_action( 'wp_ajax_mtb_get_seats',        [ 'MTB_Seats',   'ajax_get_seats' ] );
add_action( 'wp_ajax_nopriv_mtb_get_seats', [ 'MTB_Seats',   'ajax_get_seats' ] );
add_action( 'wp_ajax_mtb_hold_seat',        [ 'MTB_Seats',   'ajax_hold_seat' ] );
add_action( 'wp_ajax_nopriv_mtb_hold_seat', [ 'MTB_Seats',   'ajax_hold_seat' ] );
