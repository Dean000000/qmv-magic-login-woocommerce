<?php
/**
 * User Profile Class
 * Handles frontend profile management and WooCommerce My Account integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_User_Profile {
    
    private static $instance = null;
    
    // Endpoint slug - using 'notifications' instead of 'email-preferences' to avoid conflicts
    const ENDPOINT_SLUG = 'notifications';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Add email preferences to WooCommerce My Account
        add_action('woocommerce_account_dashboard', array($this, 'show_email_preferences_notice'), 5);
        
        // Add custom endpoint for email preferences
        add_action('init', array($this, 'add_endpoints'), 0);
        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        add_filter('woocommerce_account_menu_items', array($this, 'modify_menu_items'), 20);
        add_action('woocommerce_account_' . self::ENDPOINT_SLUG . '_endpoint', array($this, 'email_preferences_content'));
        
        // Redirect my-account to edit-account (make Account Details the default)
        add_action('template_redirect', array($this, 'redirect_dashboard_to_account'));
        
        // Add phone field to account form if not present
        add_action('woocommerce_edit_account_form', array($this, 'add_phone_field'));
        add_action('woocommerce_save_account_details', array($this, 'save_phone_field'), 10, 1);
        
        // Hide password fields for magic login users
        add_action('wp_head', array($this, 'hide_password_fields_css'));
        add_filter('woocommerce_save_account_details_required_fields', array($this, 'remove_password_validation'));
        
        // Shortcode for email preferences (for use outside My Account)
        add_shortcode('qml_email_preferences', array($this, 'email_preferences_shortcode'));
        add_shortcode('qml_notifications', array($this, 'email_preferences_shortcode'));
    }
    
    /**
     * Add custom endpoints
     */
    public function add_endpoints() {
        add_rewrite_endpoint(self::ENDPOINT_SLUG, EP_ROOT | EP_PAGES);
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = self::ENDPOINT_SLUG;
        return $vars;
    }
    
    /**
     * Redirect dashboard to account details page
     */
    public function redirect_dashboard_to_account() {
        global $wp;
        
        // Check if we're on the my-account page (dashboard)
        if (is_account_page() && is_user_logged_in()) {
            // Get current endpoint
            $current_endpoint = '';
            foreach (wc()->query->get_query_vars() as $key => $value) {
                if (isset($wp->query_vars[$key])) {
                    $current_endpoint = $key;
                    break;
                }
            }
            
            // If no endpoint (dashboard), redirect to edit-account
            if (empty($current_endpoint) && !isset($wp->query_vars['pagename'])) {
                // Only redirect if we're on the exact my-account page
                $my_account_page_id = wc_get_page_id('myaccount');
                if ($my_account_page_id && is_page($my_account_page_id)) {
                    wp_safe_redirect(wc_get_account_endpoint_url('edit-account'));
                    exit;
                }
            }
        }
    }
    
    /**
     * Modify My Account menu - remove dashboard, add email preferences
     */
    public function modify_menu_items($items) {
        // Remove dashboard
        unset($items['dashboard']);
        
        // Create new array with desired order
        $new_items = array();
        
        foreach ($items as $key => $label) {
            // Add email preferences before logout
            if ($key === 'customer-logout') {
                $new_items[self::ENDPOINT_SLUG] = __('Email Preferences', 'QMV-magic-login');
            }
            $new_items[$key] = $label;
        }
        
        return $new_items;
    }
    
    /**
     * Hide password fields with CSS for magic login users
     */
    public function hide_password_fields_css() {
        if (!is_account_page()) {
            return;
        }
        
        // Hide password fields for all users on account edit page
        // Since we're using magic login, passwords aren't needed
        ?>
        <style>
            /* Hide password change section */
            .woocommerce-EditAccountForm fieldset,
            .woocommerce-edit-account fieldset {
                display: none !important;
            }
            
            /* Also hide any password-related fields */
            .woocommerce-EditAccountForm .password-input,
            .woocommerce-edit-account .password-input,
            .woocommerce-EditAccountForm [name="password_current"],
            .woocommerce-EditAccountForm [name="password_1"],
            .woocommerce-EditAccountForm [name="password_2"],
            .woocommerce-edit-account [name="password_current"],
            .woocommerce-edit-account [name="password_1"],
            .woocommerce-edit-account [name="password_2"] {
                display: none !important;
            }
            
            /* Hide the password change legend/header */
            .woocommerce-EditAccountForm legend,
            .woocommerce-edit-account legend {
                display: none !important;
            }
        </style>
        <?php
    }
    
    /**
     * Remove password validation since we don't use passwords
     */
    public function remove_password_validation($fields) {
        // Remove password fields from required validation
        unset($fields['password_current']);
        unset($fields['password_1']);
        unset($fields['password_2']);
        return $fields;
    }
    
    /**
     * Show notice about email preferences on dashboard
     */
    public function show_email_preferences_notice() {
        $user_id = get_current_user_id();
        
        // Check if this is a new user (registered via magic login)
        $registered_via = get_user_meta($user_id, 'qml_registered_via', true);
        $has_seen_notice = get_user_meta($user_id, 'qml_seen_preferences_notice', true);
        
        if ($registered_via === 'magic_login' && !$has_seen_notice) {
            ?>
            <div class="woocommerce-message qml-welcome-notice">
                <strong><?php esc_html_e('Welcome to QMV!', 'QMV-magic-login'); ?></strong>
                <p><?php esc_html_e('Complete your profile and manage your email notification preferences.', 'QMV-magic-login'); ?></p>
                <p>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>" class="button">
                        <?php esc_html_e('Complete Profile', 'QMV-magic-login'); ?>
                    </a>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url(self::ENDPOINT_SLUG)); ?>" class="button button-secondary">
                        <?php esc_html_e('Email Preferences', 'QMV-magic-login'); ?>
                    </a>
                </p>
            </div>
            <?php
            update_user_meta($user_id, 'qml_seen_preferences_notice', true);
        }
    }
    
    /**
     * Email preferences page content
     */
    public function email_preferences_content() {
        $user_id = get_current_user_id();
        $preferences = $this->get_user_email_preferences($user_id);
        $saved = false;
        
        // Handle form submission
        if (isset($_POST['qml_save_email_preferences']) && wp_verify_nonce($_POST['qml_email_prefs_nonce'], 'qml_email_prefs')) {
            $this->save_email_preferences_from_post($user_id);
            $preferences = $this->get_user_email_preferences($user_id);
            $saved = true;
        }
        
        if ($saved) {
            echo '<div class="woocommerce-message">' . esc_html__('Email preferences saved!', 'QMV-magic-login') . '</div>';
        }
        
        ?>
        <h3><?php esc_html_e('Email Notification Preferences', 'QMV-magic-login'); ?></h3>
        <p><?php esc_html_e('Choose which email notifications you would like to receive:', 'QMV-magic-login'); ?></p>
        
        <form method="post" class="qml-email-preferences-form">
            <?php wp_nonce_field('qml_email_prefs', 'qml_email_prefs_nonce'); ?>
            
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox" 
                           name="qml_back_in_stock" 
                           value="1" 
                           <?php checked($preferences['back_in_stock'], 1); ?>>
                    <span><?php esc_html_e('Back in Stock Alerts', 'QMV-magic-login'); ?></span>
                </label>
                <span class="description"><?php esc_html_e('Get notified when products you want are back in stock.', 'QMV-magic-login'); ?></span>
            </p>
            
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox" 
                           name="qml_new_products" 
                           value="1" 
                           <?php checked($preferences['new_products'], 1); ?>>
                    <span><?php esc_html_e('New Product Announcements', 'QMV-magic-login'); ?></span>
                </label>
                <span class="description"><?php esc_html_e('Be the first to know about new fabrics and supplies.', 'QMV-magic-login'); ?></span>
            </p>
            
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox" 
                           name="qml_abandoned_cart" 
                           value="1" 
                           <?php checked($preferences['abandoned_cart'], 1); ?>>
                    <span><?php esc_html_e('Cart Reminders', 'QMV-magic-login'); ?></span>
                </label>
                <span class="description"><?php esc_html_e('Reminder emails if you leave items in your cart.', 'QMV-magic-login'); ?></span>
            </p>
            
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox" 
                           name="qml_order_updates" 
                           value="1" 
                           <?php checked($preferences['order_updates'], 1); ?>>
                    <span><?php esc_html_e('Order Updates', 'QMV-magic-login'); ?></span>
                </label>
                <span class="description"><?php esc_html_e('Updates about your orders, shipping, and delivery.', 'QMV-magic-login'); ?></span>
            </p>
            
            <p class="form-row">
                <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
                    <input type="checkbox" 
                           class="woocommerce-form__input woocommerce-form__input-checkbox" 
                           name="qml_promotions" 
                           value="1" 
                           <?php checked($preferences['promotions'], 1); ?>>
                    <span><?php esc_html_e('Special Offers & Promotions', 'QMV-magic-login'); ?></span>
                </label>
                <span class="description"><?php esc_html_e('Exclusive deals, discounts, and promotional offers.', 'QMV-magic-login'); ?></span>
            </p>
            
            <p class="form-row">
                <button type="submit" name="qml_save_email_preferences" class="woocommerce-Button button">
                    <?php esc_html_e('Save Preferences', 'QMV-magic-login'); ?>
                </button>
            </p>
        </form>
        <?php
    }
    
    /**
     * Get user email preferences
     */
    public function get_user_email_preferences($user_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qml_email_preferences';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return array(
                'back_in_stock' => 1,
                'new_products' => 1,
                'abandoned_cart' => 1,
                'order_updates' => 1,
                'promotions' => 1
            );
        }
        
        $preferences = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ), ARRAY_A);
        
        if (!$preferences) {
            // Return defaults
            return array(
                'back_in_stock' => 1,
                'new_products' => 1,
                'abandoned_cart' => 1,
                'order_updates' => 1,
                'promotions' => 1
            );
        }
        
        return $preferences;
    }
    
    /**
     * Save email preferences from POST data
     */
    private function save_email_preferences_from_post($user_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qml_email_preferences';
        
        // Create table if it doesn't exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $this->create_preferences_table();
        }
        
        $preferences = array(
            'back_in_stock' => isset($_POST['qml_back_in_stock']) ? 1 : 0,
            'new_products' => isset($_POST['qml_new_products']) ? 1 : 0,
            'abandoned_cart' => isset($_POST['qml_abandoned_cart']) ? 1 : 0,
            'order_updates' => isset($_POST['qml_order_updates']) ? 1 : 0,
            'promotions' => isset($_POST['qml_promotions']) ? 1 : 0
        );
        
        // Check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d",
            $user_id
        ));
        
        if ($exists) {
            $wpdb->update(
                $table,
                $preferences,
                array('user_id' => $user_id),
                array('%d', '%d', '%d', '%d', '%d'),
                array('%d')
            );
        } else {
            $preferences['user_id'] = $user_id;
            $wpdb->insert(
                $table,
                $preferences,
                array('%d', '%d', '%d', '%d', '%d', '%d')
            );
        }
    }
    
    /**
     * Create preferences table
     */
    private function create_preferences_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'qml_email_preferences';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add phone field to account form
     */
    public function add_phone_field() {
        $user_id = get_current_user_id();
        $phone = get_user_meta($user_id, 'billing_phone', true);
        
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <label for="qml_phone"><?php esc_html_e('Phone Number', 'QMV-magic-login'); ?></label>
            <input type="tel" 
                   class="woocommerce-Input woocommerce-Input--text input-text" 
                   name="qml_phone" 
                   id="qml_phone" 
                   value="<?php echo esc_attr($phone); ?>">
        </p>
        <?php
    }
    
    /**
     * Save phone field
     */
    public function save_phone_field($user_id) {
        if (isset($_POST['qml_phone'])) {
            update_user_meta($user_id, 'billing_phone', sanitize_text_field($_POST['qml_phone']));
        }
    }
    
    /**
     * Email preferences shortcode
     */
    public function email_preferences_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to manage your email preferences.', 'QMV-magic-login') . '</p>';
        }
        
        ob_start();
        $this->email_preferences_content();
        return ob_get_clean();
    }
    
    /**
     * Flush rewrite rules - call this on plugin activation
     */
    public static function flush_rules() {
        $instance = self::get_instance();
        $instance->add_endpoints();
        flush_rewrite_rules();
    }
}