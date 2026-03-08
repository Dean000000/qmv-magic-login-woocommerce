<?php
/**
 * Admin Class
 * Handles plugin settings and admin functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_Admin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX for bulk email
        add_action('wp_ajax_qml_send_bulk_email', array($this, 'handle_bulk_email'));
        add_action('wp_ajax_qml_get_user_count', array($this, 'get_user_count'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Magic Login', 'QMV-magic-login'),
            __('Magic Login', 'QMV-magic-login'),
            'manage_options',
            'QMV-magic-login',
            array($this, 'settings_page'),
            'dashicons-unlock',
            58
        );
        
        add_submenu_page(
            'QMV-magic-login',
            __('Settings', 'QMV-magic-login'),
            __('Settings', 'QMV-magic-login'),
            'manage_options',
            'QMV-magic-login',
            array($this, 'settings_page')
        );
        
        add_submenu_page(
            'QMV-magic-login',
            __('Bulk Email', 'QMV-magic-login'),
            __('Bulk Email', 'QMV-magic-login'),
            'manage_options',
            'qml-bulk-email',
            array($this, 'bulk_email_page')
        );
        
        add_submenu_page(
            'QMV-magic-login',
            __('Subscribers', 'QMV-magic-login'),
            __('Subscribers', 'QMV-magic-login'),
            'manage_options',
            'qml-subscribers',
            array($this, 'subscribers_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // General Settings
        register_setting('qml_settings', 'qml_nag_enabled');
        register_setting('qml_settings', 'qml_nag_delay');
        register_setting('qml_settings', 'qml_nag_once_per_session');
        register_setting('qml_settings', 'qml_nag_show_skip_button');
        register_setting('qml_settings', 'qml_nag_skip_duration');
        
        // OTP Settings
        register_setting('qml_settings', 'qml_otp_expiry');
        register_setting('qml_settings', 'qml_login_remember_days');
        
        // Google Settings
        register_setting('qml_settings', 'qml_google_client_id');
        register_setting('qml_settings', 'qml_google_client_secret');
        
        // Redirect Settings
        register_setting('qml_settings', 'qml_new_user_redirect');
        register_setting('qml_settings', 'qml_existing_user_redirect');
        
        // Email Settings
        register_setting('qml_settings', 'qml_email_from_name');
        register_setting('qml_settings', 'qml_email_from_address');
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'QMV-magic-login') === false && strpos($hook, 'qml-') === false) {
            return;
        }
        
        wp_enqueue_style(
            'qml-admin',
            QML_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            QML_VERSION
        );
        
        wp_enqueue_script(
            'qml-admin',
            QML_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            QML_VERSION,
            true
        );
        
        wp_localize_script('qml-admin', 'qml_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('qml_admin_nonce')
        ));
        
        // Enqueue WP editor for bulk email
        if (strpos($hook, 'qml-bulk-email') !== false) {
            wp_enqueue_editor();
        }
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap qml-admin-wrap">
            <h1><?php esc_html_e('Magic Login Settings', 'QMV-magic-login'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('qml_settings'); ?>
                
                <div class="qml-settings-section">
                    <h2><?php esc_html_e('Nag Screen Settings', 'QMV-magic-login'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Nag Screen', 'QMV-magic-login'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="qml_nag_enabled" value="1" <?php checked(get_option('qml_nag_enabled', true), true); ?>>
                                    <?php esc_html_e('Show login popup to non-logged-in users', 'QMV-magic-login'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Delay (seconds)', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="number" name="qml_nag_delay" value="<?php echo esc_attr(get_option('qml_nag_delay', 10)); ?>" min="1" max="300" class="small-text">
                                <p class="description"><?php esc_html_e('Seconds to wait before showing the login popup.', 'QMV-magic-login'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Show Once Per Session', 'QMV-magic-login'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="qml_nag_once_per_session" value="1" <?php checked(get_option('qml_nag_once_per_session', true), true); ?>>
                                    <?php esc_html_e('Only show once per browser session', 'QMV-magic-login'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Show Skip Button', 'QMV-magic-login'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="qml_nag_show_skip_button" value="1" <?php checked(get_option('qml_nag_show_skip_button', true), true); ?>>
                                    <?php esc_html_e('Show "Maybe later" option', 'QMV-magic-login'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Skip Duration (hours)', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="number" name="qml_nag_skip_duration" value="<?php echo esc_attr(get_option('qml_nag_skip_duration', 24)); ?>" min="1" max="720" class="small-text">
                                <p class="description"><?php esc_html_e('Hours to wait before showing popup again after user clicks "Maybe later".', 'QMV-magic-login'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="qml-settings-section">
                    <h2><?php esc_html_e('OTP & Login Settings', 'QMV-magic-login'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('OTP Expiry (minutes)', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="number" name="qml_otp_expiry" value="<?php echo esc_attr(get_option('qml_otp_expiry', 10)); ?>" min="1" max="60" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Stay Logged In (days)', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="number" name="qml_login_remember_days" value="<?php echo esc_attr(get_option('qml_login_remember_days', 30)); ?>" min="1" max="365" class="small-text">
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="qml-settings-section">
                    <h2><?php esc_html_e('Google Login Settings', 'QMV-magic-login'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Google OAuth is pre-configured with the same credentials as Loginizer.', 'QMV-magic-login'); ?>
                    </p>
                    <div class="notice notice-info inline" style="margin: 10px 0;">
                        <p>
                            <strong><?php esc_html_e('Important:', 'QMV-magic-login'); ?></strong>
                            <?php esc_html_e('Make sure your Google Cloud Console OAuth settings include:', 'QMV-magic-login'); ?>
                        </p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <li><strong>Authorized JavaScript origins:</strong> <code><?php echo esc_html(home_url()); ?></code></li>
                            <li><strong>Authorized redirect URIs:</strong> <code><?php echo esc_html(home_url()); ?></code></li>
                        </ul>
                        <p>
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="button button-secondary">
                                <?php esc_html_e('Open Google Cloud Console', 'QMV-magic-login'); ?>
                            </a>
                        </p>
                    </div>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Client ID', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="text" name="qml_google_client_id" value="<?php echo esc_attr(get_option('qml_google_client_id', '')); ?>" class="large-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Client Secret', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="password" name="qml_google_client_secret" value="<?php echo esc_attr(get_option('qml_google_client_secret', '')); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('Note: Client Secret is not required for Google Identity Services (frontend only), but stored for compatibility.', 'QMV-magic-login'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="qml-settings-section">
                    <h2><?php esc_html_e('Redirect Settings', 'QMV-magic-login'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('New User Redirect', 'QMV-magic-login'); ?></th>
                            <td>
                                <select name="qml_new_user_redirect">
                                    <option value="profile" <?php selected(get_option('qml_new_user_redirect', 'profile'), 'profile'); ?>><?php esc_html_e('My Account / Profile', 'QMV-magic-login'); ?></option>
                                    <option value="same_page" <?php selected(get_option('qml_new_user_redirect'), 'same_page'); ?>><?php esc_html_e('Stay on Same Page', 'QMV-magic-login'); ?></option>
                                    <option value="home" <?php selected(get_option('qml_new_user_redirect'), 'home'); ?>><?php esc_html_e('Home Page', 'QMV-magic-login'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Existing User Redirect', 'QMV-magic-login'); ?></th>
                            <td>
                                <select name="qml_existing_user_redirect">
                                    <option value="same_page" <?php selected(get_option('qml_existing_user_redirect', 'same_page'), 'same_page'); ?>><?php esc_html_e('Stay on Same Page', 'QMV-magic-login'); ?></option>
                                    <option value="my_account" <?php selected(get_option('qml_existing_user_redirect'), 'my_account'); ?>><?php esc_html_e('My Account', 'QMV-magic-login'); ?></option>
                                    <option value="home" <?php selected(get_option('qml_existing_user_redirect'), 'home'); ?>><?php esc_html_e('Home Page', 'QMV-magic-login'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="qml-settings-section">
                    <h2><?php esc_html_e('Email Settings', 'QMV-magic-login'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('From Name', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="text" name="qml_email_from_name" value="<?php echo esc_attr(get_option('qml_email_from_name', get_bloginfo('name'))); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('From Email', 'QMV-magic-login'); ?></th>
                            <td>
                                <input type="email" name="qml_email_from_address" value="<?php echo esc_attr(get_option('qml_email_from_address', get_option('admin_email'))); ?>" class="regular-text">
                            </td>
                        </tr>
                    </table>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Bulk email page
     */
    public function bulk_email_page() {
        ?>
        <div class="wrap qml-admin-wrap">
            <h1><?php esc_html_e('Send Bulk Email', 'QMV-magic-login'); ?></h1>
            
            <div class="qml-bulk-email-form">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Recipients', 'QMV-magic-login'); ?></th>
                        <td>
                            <select id="qml-recipient-filter" name="recipient_filter">
                                <option value="all"><?php esc_html_e('All Subscribers', 'QMV-magic-login'); ?></option>
                                <option value="promotions"><?php esc_html_e('Opted in to Promotions', 'QMV-magic-login'); ?></option>
                                <option value="new_products"><?php esc_html_e('Opted in to New Products', 'QMV-magic-login'); ?></option>
                            </select>
                            <span id="qml-recipient-count" class="description"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Subject', 'QMV-magic-login'); ?></th>
                        <td>
                            <input type="text" id="qml-email-subject" class="large-text" placeholder="<?php esc_attr_e('Email subject line', 'QMV-magic-login'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Message', 'QMV-magic-login'); ?></th>
                        <td>
                            <?php 
                            wp_editor('', 'qml_email_content', array(
                                'media_buttons' => true,
                                'textarea_rows' => 15,
                                'teeny' => false
                            )); 
                            ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="button" id="qml-preview-email" class="button button-secondary">
                        <?php esc_html_e('Preview Email', 'QMV-magic-login'); ?>
                    </button>
                    <button type="button" id="qml-send-bulk-email" class="button button-primary">
                        <?php esc_html_e('Send Email', 'QMV-magic-login'); ?>
                    </button>
                </p>
                
                <div id="qml-email-status" style="display: none;"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Subscribers page
     */
    public function subscribers_page() {
        global $wpdb;
        
        $prefs_table = $wpdb->prefix . 'qml_email_preferences';
        
        // Get subscriber stats
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $prefs_table");
        $promotions = $wpdb->get_var("SELECT COUNT(*) FROM $prefs_table WHERE promotions = 1");
        $new_products = $wpdb->get_var("SELECT COUNT(*) FROM $prefs_table WHERE new_products = 1");
        $back_in_stock = $wpdb->get_var("SELECT COUNT(*) FROM $prefs_table WHERE back_in_stock = 1");
        
        // Get recent subscribers
        $recent = $wpdb->get_results(
            "SELECT p.*, u.user_email, u.display_name 
             FROM $prefs_table p 
             INNER JOIN {$wpdb->users} u ON p.user_id = u.ID 
             ORDER BY p.created_at DESC 
             LIMIT 50"
        );
        
        ?>
        <div class="wrap qml-admin-wrap">
            <h1><?php esc_html_e('Email Subscribers', 'QMV-magic-login'); ?></h1>
            
            <div class="qml-stats-grid">
                <div class="qml-stat-box">
                    <span class="qml-stat-number"><?php echo esc_html($total); ?></span>
                    <span class="qml-stat-label"><?php esc_html_e('Total Subscribers', 'QMV-magic-login'); ?></span>
                </div>
                <div class="qml-stat-box">
                    <span class="qml-stat-number"><?php echo esc_html($promotions); ?></span>
                    <span class="qml-stat-label"><?php esc_html_e('Promotions', 'QMV-magic-login'); ?></span>
                </div>
                <div class="qml-stat-box">
                    <span class="qml-stat-number"><?php echo esc_html($new_products); ?></span>
                    <span class="qml-stat-label"><?php esc_html_e('New Products', 'QMV-magic-login'); ?></span>
                </div>
                <div class="qml-stat-box">
                    <span class="qml-stat-number"><?php echo esc_html($back_in_stock); ?></span>
                    <span class="qml-stat-label"><?php esc_html_e('Back in Stock', 'QMV-magic-login'); ?></span>
                </div>
            </div>
            
            <h2><?php esc_html_e('Recent Subscribers', 'QMV-magic-login'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Email', 'QMV-magic-login'); ?></th>
                        <th><?php esc_html_e('Name', 'QMV-magic-login'); ?></th>
                        <th><?php esc_html_e('Promotions', 'QMV-magic-login'); ?></th>
                        <th><?php esc_html_e('New Products', 'QMV-magic-login'); ?></th>
                        <th><?php esc_html_e('Back in Stock', 'QMV-magic-login'); ?></th>
                        <th><?php esc_html_e('Date', 'QMV-magic-login'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)) : ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e('No subscribers yet.', 'QMV-magic-login'); ?></td>
                    </tr>
                    <?php else : ?>
                        <?php foreach ($recent as $subscriber) : ?>
                        <tr>
                            <td><?php echo esc_html($subscriber->user_email); ?></td>
                            <td><?php echo esc_html($subscriber->display_name); ?></td>
                            <td><?php echo $subscriber->promotions ? '✓' : '✗'; ?></td>
                            <td><?php echo $subscriber->new_products ? '✓' : '✗'; ?></td>
                            <td><?php echo $subscriber->back_in_stock ? '✓' : '✗'; ?></td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($subscriber->created_at))); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Handle bulk email AJAX
     */
    public function handle_bulk_email() {
        check_ajax_referer('qml_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'QMV-magic-login')));
        }
        
        $subject = isset($_POST['subject']) ? sanitize_text_field($_POST['subject']) : '';
        $message = isset($_POST['message']) ? wp_kses_post($_POST['message']) : '';
        $filter = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';
        
        if (empty($subject) || empty($message)) {
            wp_send_json_error(array('message' => __('Subject and message are required.', 'QMV-magic-login')));
        }
        
        $preference_filter = null;
        if ($filter !== 'all') {
            $preference_filter = $filter;
        }
        
        $email_handler = QML_Email_Handler::get_instance();
        $result = $email_handler->send_bulk_email($subject, $message, array(), $preference_filter);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Email sent to %d subscribers. %d failed.', 'QMV-magic-login'),
                $result['sent'],
                $result['failed']
            )
        ));
    }
    
    /**
     * Get user count for filter
     */
    public function get_user_count() {
        check_ajax_referer('qml_admin_nonce', 'nonce');
        
        global $wpdb;
        
        $filter = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';
        $prefs_table = $wpdb->prefix . 'qml_email_preferences';
        
        if ($filter === 'all') {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $prefs_table");
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $prefs_table WHERE {$filter} = %d",
                1
            ));
        }
        
        wp_send_json_success(array('count' => $count));
    }
}
