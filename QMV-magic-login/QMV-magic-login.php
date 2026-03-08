<?php
/**
 * Plugin Name: QMV Magic Login
 * Plugin URI: https://QMV.co.za
 * Description: Magic login with email OTP, Google social login integration, and frontend user profile management for QMV.
 * Version: 1.0.0
 * Author: QMV
 * Author URI: https://QMV.co.za
 * Text Domain: QMV-magic-login
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('QML_VERSION', '1.0.0');
define('QML_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QML_PLUGIN_URL', plugin_dir_url(__FILE__));
define('QML_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
class QMV_Magic_Login {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once QML_PLUGIN_DIR . 'includes/class-qml-otp-handler.php';
        require_once QML_PLUGIN_DIR . 'includes/class-qml-nag-screen.php';
        require_once QML_PLUGIN_DIR . 'includes/class-qml-user-profile.php';
        require_once QML_PLUGIN_DIR . 'includes/class-qml-ajax-handler.php';
        require_once QML_PLUGIN_DIR . 'includes/class-qml-email-handler.php';
        require_once QML_PLUGIN_DIR . 'includes/class-qml-google-login.php';
        
        // Admin
        if (is_admin()) {
            require_once QML_PLUGIN_DIR . 'admin/class-qml-admin.php';
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Init
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // HPOS compatibility for WooCommerce
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }
    
    /**
     * Plugin initialization
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('QMV-magic-login', false, dirname(QML_PLUGIN_BASENAME) . '/languages');
        
        // Check/create tables on init (in case activation didn't run properly)
        $this->maybe_create_tables();
        
        // Initialize components
        QML_OTP_Handler::get_instance();
        QML_Nag_Screen::get_instance();
        QML_User_Profile::get_instance();
        QML_Ajax_Handler::get_instance();
        QML_Email_Handler::get_instance();
        QML_Google_Login::get_instance();
        
        if (is_admin()) {
            QML_Admin::get_instance();
        }
    }
    
    /**
     * Check and create tables if they don't exist
     */
    private function maybe_create_tables() {
        global $wpdb;
        
        $table_otp = $wpdb->prefix . 'qml_otp';
        
        // Check if main table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_otp'") != $table_otp) {
            $this->create_tables();
        }
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Don't load on admin pages
        if (is_admin()) {
            return;
        }
        
        // Don't load on wp-login.php
        if ($GLOBALS['pagenow'] === 'wp-login.php') {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'qml-frontend',
            QML_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            QML_VERSION
        );
        
        // JS
        wp_enqueue_script(
            'qml-frontend',
            QML_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            QML_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('qml-frontend', 'qml_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qml_nonce'),
            'is_logged_in' => is_user_logged_in(),
            'nag_delay' => $this->get_option('nag_delay', 10) * 1000, // Convert to milliseconds
            'nag_enabled' => $this->get_option('nag_enabled', true),
            'show_nag_once_per_session' => $this->get_option('nag_once_per_session', true),
            'google_client_id' => $this->get_option('google_client_id', ''),
            'i18n' => array(
                'email_placeholder' => __('Enter your email address', 'QMV-magic-login'),
                'otp_placeholder' => __('Enter 5-digit code', 'QMV-magic-login'),
                'sending' => __('Sending...', 'QMV-magic-login'),
                'verifying' => __('Verifying...', 'QMV-magic-login'),
                'error' => __('Something went wrong. Please try again.', 'QMV-magic-login'),
            )
        ));
    }
    
    /**
     * Activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Add the email-preferences endpoint
        add_rewrite_endpoint('email-preferences', EP_ROOT | EP_PAGES);
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // OTP table
        $table_otp = $wpdb->prefix . 'qml_otp';
        $sql_otp = "CREATE TABLE IF NOT EXISTS $table_otp (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            otp varchar(5) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            attempts int(11) DEFAULT 0,
            used tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY email (email),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        // Email preferences table
        $table_prefs = $wpdb->prefix . 'qml_email_preferences';
        $sql_prefs = "CREATE TABLE IF NOT EXISTS $table_prefs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            back_in_stock tinyint(1) DEFAULT 1,
            new_products tinyint(1) DEFAULT 1,
            abandoned_cart tinyint(1) DEFAULT 1,
            order_updates tinyint(1) DEFAULT 1,
            promotions tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";
        
        // Wishlist/favorites tracking (for back-in-stock)
        $table_stock = $wpdb->prefix . 'qml_stock_notifications';
        $sql_stock = "CREATE TABLE IF NOT EXISTS $table_stock (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            product_id bigint(20) UNSIGNED NOT NULL,
            email varchar(255) NOT NULL,
            notified tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_product (user_id, product_id),
            KEY product_id (product_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_otp);
        dbDelta($sql_prefs);
        dbDelta($sql_stock);
    }
    
    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = array(
            'nag_enabled' => true,
            'nag_delay' => 10,
            'nag_once_per_session' => false, // Blocking mode - always show
            'nag_show_skip_button' => false, // No skip in blocking mode
            'nag_skip_duration' => 24, // hours (not used in blocking mode)
            'otp_expiry' => 10, // minutes
            'otp_length' => 5,
            'login_remember_days' => 365, // 1 year - effectively until logout
            // Pre-configured Google OAuth (same as Loginizer)
            'google_client_id' => '',
            'google_client_secret' => '',
            'new_user_redirect' => 'profile',
            'existing_user_redirect' => 'same_page',
            'email_from_name' => get_bloginfo('name'),
            'email_from_address' => get_option('admin_email'),
        );
        
        foreach ($defaults as $key => $value) {
            if (get_option('qml_' . $key) === false) {
                update_option('qml_' . $key, $value);
            }
        }
    }
    
    /**
     * Get option helper
     */
    public function get_option($key, $default = '') {
        return get_option('qml_' . $key, $default);
    }
    
    /**
     * Update option helper
     */
    public function update_option($key, $value) {
        return update_option('qml_' . $key, $value);
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
}

/**
 * Initialize the plugin
 */
function QMV_magic_login() {
    return QMV_Magic_Login::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'QMV_magic_login');
