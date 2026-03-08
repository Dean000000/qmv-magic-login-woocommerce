<?php
/**
 * AJAX Handler Class
 * Handles all AJAX requests for the magic login
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_Ajax_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Public AJAX actions (for non-logged-in users)
        add_action('wp_ajax_nopriv_qml_send_otp', array($this, 'send_otp'));
        add_action('wp_ajax_nopriv_qml_verify_otp', array($this, 'verify_otp'));
        add_action('wp_ajax_nopriv_qml_google_login', array($this, 'google_login'));
        
        // Actions for logged-in users too
        add_action('wp_ajax_qml_send_otp', array($this, 'send_otp'));
        add_action('wp_ajax_qml_verify_otp', array($this, 'verify_otp'));
        add_action('wp_ajax_qml_google_login', array($this, 'google_login'));
        
        // Profile update actions (logged-in only)
        add_action('wp_ajax_qml_update_profile', array($this, 'update_profile'));
        add_action('wp_ajax_qml_update_email_preferences', array($this, 'update_email_preferences'));
        add_action('wp_ajax_qml_subscribe_stock_notification', array($this, 'subscribe_stock_notification'));
    }
    
    /**
     * Verify nonce
     */
    private function verify_nonce() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'qml_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed. Please refresh the page and try again.', 'QMV-magic-login')
            ));
        }
    }
    
    /**
     * Send OTP to email
     */
    public function send_otp() {
        $this->verify_nonce();
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        if (empty($email)) {
            wp_send_json_error(array(
                'message' => __('Please enter your email address.', 'QMV-magic-login')
            ));
        }
        
        // Generate OTP
        $otp_handler = QML_OTP_Handler::get_instance();
        $otp = $otp_handler->generate_otp($email);
        
        if (is_wp_error($otp)) {
            wp_send_json_error(array(
                'message' => $otp->get_error_message()
            ));
        }
        
        // Send email
        $email_handler = QML_Email_Handler::get_instance();
        $sent = $email_handler->send_otp_email($email, $otp);
        
        if (is_wp_error($sent)) {
            wp_send_json_error(array(
                'message' => $sent->get_error_message()
            ));
        }
        
        wp_send_json_success(array(
            'message' => __('OTP sent! Check your email.', 'QMV-magic-login'),
            'email' => $email
        ));
    }
    
    /**
     * Verify OTP and log user in
     */
    public function verify_otp() {
        $this->verify_nonce();
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $otp = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';
        
        // Debug logging
        error_log("QML AJAX verify_otp called - Email: $email, OTP: $otp");
        
        if (empty($email) || empty($otp)) {
            error_log("QML AJAX verify_otp - Missing email or OTP");
            wp_send_json_error(array(
                'message' => __('Email and OTP are required.', 'QMV-magic-login')
            ));
        }
        
        // Verify OTP
        $otp_handler = QML_OTP_Handler::get_instance();
        $valid = $otp_handler->verify_otp($email, $otp);
        
        if (is_wp_error($valid)) {
            error_log("QML AJAX verify_otp - Verification failed: " . $valid->get_error_message());
            wp_send_json_error(array(
                'message' => $valid->get_error_message()
            ));
        }
        
        error_log("QML AJAX verify_otp - OTP verified, proceeding to login");
        
        // Get or create user
        $user = get_user_by('email', $email);
        $is_new_user = false;
        
        if (!$user) {
            // Create new user
            $user_id = $this->create_user($email);
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(array(
                    'message' => $user_id->get_error_message()
                ));
            }
            
            $user = get_user_by('id', $user_id);
            $is_new_user = true;
            
            // Set default email preferences for new user
            $this->set_default_email_preferences($user_id);
        }
        
        // Log the user in
        $this->login_user($user);
        
        // Determine redirect URL
        $redirect_url = $this->get_redirect_url($is_new_user);
        
        error_log("QML AJAX verify_otp - Login successful for user ID: " . $user->ID);
        
        wp_send_json_success(array(
            'message' => __('Login successful!', 'QMV-magic-login'),
            'is_new_user' => $is_new_user,
            'redirect_url' => $redirect_url,
            'user_display_name' => $user->display_name
        ));
    }
    
    /**
     * Handle Google login
     */
    public function google_login() {
        $this->verify_nonce();
        
        $credential = isset($_POST['credential']) ? sanitize_text_field($_POST['credential']) : '';
        
        if (empty($credential)) {
            wp_send_json_error(array(
                'message' => __('Invalid Google login response.', 'QMV-magic-login')
            ));
        }
        
        $google_handler = QML_Google_Login::get_instance();
        $result = $google_handler->verify_token($credential);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }
        
        // Get or create user
        $user = get_user_by('email', $result['email']);
        $is_new_user = false;
        
        if (!$user) {
            // Create new user with Google data
            $user_id = $this->create_user($result['email'], array(
                'first_name' => $result['given_name'] ?? '',
                'last_name' => $result['family_name'] ?? '',
                'display_name' => $result['name'] ?? '',
                'google_id' => $result['sub'] ?? ''
            ));
            
            if (is_wp_error($user_id)) {
                wp_send_json_error(array(
                    'message' => $user_id->get_error_message()
                ));
            }
            
            $user = get_user_by('id', $user_id);
            $is_new_user = true;
            
            // Save Google profile picture
            if (!empty($result['picture'])) {
                update_user_meta($user_id, 'qml_google_picture', esc_url($result['picture']));
            }
            
            // Set default email preferences
            $this->set_default_email_preferences($user_id);
        } else {
            // Update Google ID if not set
            if (empty(get_user_meta($user->ID, 'qml_google_id', true))) {
                update_user_meta($user->ID, 'qml_google_id', $result['sub'] ?? '');
            }
        }
        
        // Log the user in
        $this->login_user($user);
        
        // Determine redirect URL
        $redirect_url = $this->get_redirect_url($is_new_user);
        
        wp_send_json_success(array(
            'message' => __('Login successful!', 'QMV-magic-login'),
            'is_new_user' => $is_new_user,
            'redirect_url' => $redirect_url,
            'user_display_name' => $user->display_name
        ));
    }
    
    /**
     * Create a new user
     */
    private function create_user($email, $extra_data = array()) {
        // Generate username from email
        $username = sanitize_user(current(explode('@', $email)), true);
        $original_username = $username;
        $counter = 1;
        
        // Ensure unique username
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }
        
        // Create user
        $user_data = array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => wp_generate_password(24, true, true),
            'role' => 'customer'
        );
        
        // Merge extra data
        if (!empty($extra_data['first_name'])) {
            $user_data['first_name'] = sanitize_text_field($extra_data['first_name']);
        }
        if (!empty($extra_data['last_name'])) {
            $user_data['last_name'] = sanitize_text_field($extra_data['last_name']);
        }
        if (!empty($extra_data['display_name'])) {
            $user_data['display_name'] = sanitize_text_field($extra_data['display_name']);
        }
        
        $user_id = wp_insert_user($user_data);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Save Google ID if provided
        if (!empty($extra_data['google_id'])) {
            update_user_meta($user_id, 'qml_google_id', sanitize_text_field($extra_data['google_id']));
        }
        
        // Mark as magic login user
        update_user_meta($user_id, 'qml_registered_via', 'magic_login');
        update_user_meta($user_id, 'qml_registered_at', current_time('mysql'));
        
        // Trigger action for new user
        do_action('qml_user_registered', $user_id, $email);
        
        return $user_id;
    }
    
    /**
     * Log user in
     */
    private function login_user($user) {
        // Clear any existing auth cookies
        wp_clear_auth_cookie();
        
        // Set auth cookie with very long expiration (essentially "until logout")
        $remember = true;
        
        // Set expiration to 1 year (effectively "until logout")
        // Users will stay logged in until they manually log out
        add_filter('auth_cookie_expiration', function($expiration) {
            return YEAR_IN_SECONDS; // 1 year - effectively permanent until logout
        });
        
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);
        
        // Update last login
        update_user_meta($user->ID, 'qml_last_login', current_time('mysql'));
        
        // Trigger action
        do_action('qml_user_logged_in', $user->ID);
        do_action('wp_login', $user->user_login, $user);
    }
    
    /**
     * Get redirect URL after login
     */
    private function get_redirect_url($is_new_user) {
        if ($is_new_user) {
            $redirect_setting = get_option('qml_new_user_redirect', 'profile');
            
            if ($redirect_setting === 'profile') {
                // Redirect to WooCommerce My Account or profile page
                if (function_exists('wc_get_page_id')) {
                    return wc_get_account_endpoint_url('edit-account');
                }
                return admin_url('profile.php');
            }
        }
        
        $redirect_setting = get_option('qml_existing_user_redirect', 'same_page');
        
        if ($redirect_setting === 'same_page') {
            return ''; // JS will handle staying on same page
        }
        
        if ($redirect_setting === 'my_account' && function_exists('wc_get_page_id')) {
            return wc_get_page_permalink('myaccount');
        }
        
        return home_url();
    }
    
    /**
     * Set default email preferences for new user
     */
    private function set_default_email_preferences($user_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qml_email_preferences';
        
        $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'back_in_stock' => 1,
                'new_products' => 1,
                'abandoned_cart' => 1,
                'order_updates' => 1,
                'promotions' => 1
            ),
            array('%d', '%d', '%d', '%d', '%d', '%d')
        );
    }
    
    /**
     * Update user profile
     */
    public function update_profile() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('You must be logged in to update your profile.', 'QMV-magic-login')
            ));
        }
        
        $user_id = get_current_user_id();
        
        $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        
        // Update user data
        $user_data = array(
            'ID' => $user_id
        );
        
        if (!empty($first_name)) {
            $user_data['first_name'] = $first_name;
        }
        if (!empty($last_name)) {
            $user_data['last_name'] = $last_name;
        }
        
        $result = wp_update_user($user_data);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }
        
        // Update phone in user meta
        if (!empty($phone)) {
            update_user_meta($user_id, 'billing_phone', $phone);
        }
        
        wp_send_json_success(array(
            'message' => __('Profile updated successfully!', 'QMV-magic-login')
        ));
    }
    
    /**
     * Update email preferences
     */
    public function update_email_preferences() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('You must be logged in.', 'QMV-magic-login')
            ));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'qml_email_preferences';
        
        $preferences = array(
            'back_in_stock' => isset($_POST['back_in_stock']) ? 1 : 0,
            'new_products' => isset($_POST['new_products']) ? 1 : 0,
            'abandoned_cart' => isset($_POST['abandoned_cart']) ? 1 : 0,
            'order_updates' => isset($_POST['order_updates']) ? 1 : 0,
            'promotions' => isset($_POST['promotions']) ? 1 : 0
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
        
        wp_send_json_success(array(
            'message' => __('Email preferences updated!', 'QMV-magic-login')
        ));
    }
    
    /**
     * Subscribe to stock notification
     */
    public function subscribe_stock_notification() {
        $this->verify_nonce();
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('Please sign in to get notified when this product is back in stock.', 'QMV-magic-login')
            ));
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(array(
                'message' => __('Invalid product.', 'QMV-magic-login')
            ));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        $user = get_user_by('id', $user_id);
        $table = $wpdb->prefix . 'qml_stock_notifications';
        
        // Check if already subscribed
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND product_id = %d",
            $user_id,
            $product_id
        ));
        
        if ($exists) {
            wp_send_json_success(array(
                'message' => __("You're already subscribed to notifications for this product.", 'QMV-magic-login')
            ));
            return;
        }
        
        $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'product_id' => $product_id,
                'email' => $user->user_email,
                'notified' => 0
            ),
            array('%d', '%d', '%s', '%d')
        );
        
        wp_send_json_success(array(
            'message' => __("Great! We'll email you when this product is back in stock.", 'QMV-magic-login')
        ));
    }
}
