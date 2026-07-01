<?php
/**
 * Plugin Name: درگاه پرداخت زیبال برای Contact Form 7
 * Plugin URI: https://zibal.ir
 * Description: اتصال حرفه‌ای Contact Form 7 به درگاه پرداخت زیبال
 * Version: 2.0.8
 * Author:Abolfazla Abdollahi
 * Author URI: https://zibal.ir
 */


if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZIBAL_CF7_VERSION', '2.0.8' );
define( 'ZIBAL_CF7_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZIBAL_CF7_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ZIBAL_CF7_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once ZIBAL_CF7_PLUGIN_DIR . 'includes/class-zibal-service.php';
require_once ZIBAL_CF7_PLUGIN_DIR . 'includes/class-zibal-api.php';
require_once ZIBAL_CF7_PLUGIN_DIR . 'includes/form-tag.php';
require_once ZIBAL_CF7_PLUGIN_DIR . 'includes/hooks.php';
require_once ZIBAL_CF7_PLUGIN_DIR . 'includes/admin-functions.php';

add_action( 'wpcf7_init', 'zibal_cf7_register_service', 10, 0 );

function zibal_cf7_register_service() {
    if ( ! class_exists( 'WPCF7_Integration' ) ) {
        add_action( 'admin_notices', 'zibal_cf7_missing_cf7_notice' );
        return;
    }
    $integration = WPCF7_Integration::get_instance();
    $integration->add_service( 'zibal', Zibal_CF7_Service::get_instance() );
}

function zibal_cf7_missing_cf7_notice() {
    echo '<div class="notice notice-error"><p><strong>درگاه پرداخت زیبال:</strong> برای استفاده از این افزونه، باید Contact Form 7 نصب و فعال باشد.</p></div>';
}

register_activation_hook( __FILE__, 'zibal_cf7_activate' );

function zibal_cf7_activate() {
    zibal_cf7_install_schema();
    zibal_cf7_ensure_transaction_schema();
    update_option( 'zibal_cf7_version', ZIBAL_CF7_VERSION );
}

function zibal_cf7_install_schema() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id bigint(20) NOT NULL,
        track_id varchar(100) DEFAULT NULL,
        callback_token varchar(64) DEFAULT NULL,
        ref_number varchar(100) DEFAULT NULL,
        payer_card varchar(32) DEFAULT NULL,
        amount bigint(20) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        email varchar(100) DEFAULT NULL,
        mobile varchar(15) DEFAULT NULL,
        description text DEFAULT NULL,
        posted_data longtext DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY track_id (track_id),
        KEY callback_token (callback_token),
        KEY form_id (form_id),
        KEY status (status)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    zibal_cf7_ensure_transaction_schema();
}

function zibal_cf7_ensure_transaction_schema() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

    if ( $table_exists !== $table_name ) {
        return;
    }

    $callback_token_column = $wpdb->get_var( "SHOW COLUMNS FROM $table_name LIKE 'callback_token'" );

    if ( 'callback_token' !== $callback_token_column ) {
        $alter_result = $wpdb->query( "ALTER TABLE $table_name ADD callback_token varchar(64) DEFAULT NULL AFTER track_id" );

        if ( false === $alter_result && function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'database',
                __( 'افزودن ستون callback_token به جدول تراکنش‌ها ناموفق بود.', 'zibal-cf7' ),
                array(
                    'table' => $table_name,
                    'db_error' => $wpdb->last_error,
                ),
                'critical'
            );
        }
    }

    $callback_token_index = (int) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT COUNT(1)
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = %s
             AND INDEX_NAME = %s',
            $table_name,
            'callback_token'
        )
    );

    if ( 0 === $callback_token_index ) {
        $index_result = $wpdb->query( "ALTER TABLE $table_name ADD KEY callback_token (callback_token)" );

        if ( false === $index_result && function_exists( 'zibal_cf7_log_error' ) ) {
            if ( false !== stripos( (string) $wpdb->last_error, 'Duplicate key name' ) ) {
                return;
            }

            zibal_cf7_log_error(
                'database',
                __( 'افزودن ایندکس callback_token به جدول تراکنش‌ها ناموفق بود.', 'zibal-cf7' ),
                array(
                    'table' => $table_name,
                    'db_error' => $wpdb->last_error,
                ),
                'warning'
            );
        }
    }

    $payer_card_column = $wpdb->get_var( "SHOW COLUMNS FROM $table_name LIKE 'payer_card'" );

    if ( 'payer_card' !== $payer_card_column ) {
        $alter_result = $wpdb->query( "ALTER TABLE $table_name ADD payer_card varchar(32) DEFAULT NULL AFTER ref_number" );

        if ( false === $alter_result && function_exists( 'zibal_cf7_log_error' ) ) {
            zibal_cf7_log_error(
                'database',
                __( 'افزودن ستون شماره کارت پرداخت کننده به جدول تراکنش‌ها ناموفق بود.', 'zibal-cf7' ),
                array(
                    'table' => $table_name,
                    'db_error' => $wpdb->last_error,
                ),
                'warning'
            );
        }
    }
}

register_deactivation_hook( __FILE__, 'zibal_cf7_deactivate' );

function zibal_cf7_deactivate() {
    wp_cache_flush();
}

register_uninstall_hook( __FILE__, 'zibal_cf7_uninstall' );

function zibal_cf7_uninstall() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'zibal_cf7_transactions';
    $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
    delete_option( 'zibal_cf7_version' );
    $cf7_options = get_option( 'wpcf7' );
    if ( isset( $cf7_options['zibal'] ) ) {
        unset( $cf7_options['zibal'] );
        update_option( 'wpcf7', $cf7_options );
    }
}

add_action( 'plugins_loaded', 'zibal_cf7_load_textdomain' );

function zibal_cf7_load_textdomain() {
    load_plugin_textdomain( 'zibal-cf7', false, dirname( ZIBAL_CF7_PLUGIN_BASENAME ) . '/languages' );
}

add_action( 'plugins_loaded', 'zibal_cf7_maybe_upgrade' );

function zibal_cf7_maybe_upgrade() {
    if ( get_option( 'zibal_cf7_version' ) !== ZIBAL_CF7_VERSION ) {
        zibal_cf7_activate();
    }
}

add_action( 'wp_enqueue_scripts', 'zibal_cf7_enqueue_scripts' );

function zibal_cf7_enqueue_scripts() {
    if ( ! function_exists( 'wpcf7_enqueue_scripts' ) ) return;
    wp_enqueue_style( 'zibal-cf7-style', ZIBAL_CF7_PLUGIN_URL . 'assets/style.css', array(), ZIBAL_CF7_VERSION );
    wp_enqueue_script( 'zibal-cf7-script', ZIBAL_CF7_PLUGIN_URL . 'assets/script.js', array( 'contact-form-7' ), ZIBAL_CF7_VERSION, true );
    wp_localize_script( 'zibal-cf7-script', 'zibalCF7', array(
        'restUrl' => rest_url( 'zibal-cf7/v1/' ),
    ) );
}
