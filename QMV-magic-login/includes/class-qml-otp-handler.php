<?php
/**
 * OTP Handler Class
 * Handles OTP generation, storage, and validation
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_OTP_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Cleanup expired OTPs daily
        add_action('qml_cleanup_otps', array($this, 'cleanup_expired_otps'));
        
        if (!wp_next_scheduled('qml_cleanup_otps')) {
            wp_schedule_event(time(), 'daily', 'qml_cleanup_otps');
        }
    }
    
    /**
     * Generate a new OTP
     * 
     * @param string $email User's email address
     * @return string|WP_Error The generated OTP or error
     */
    public function generate_otp($email) {
        global $wpdb;
        
        // Validate email
        if (!is_email($email)) {
            return new WP_Error('invalid_email', __('Please enter a valid email address.', 'QMV-magic-login'));
        }
        
        $table = $wpdb->prefix . 'qml_otp';
        
        // Check if table exists, create if not
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $this->create_otp_table();
        }
        
        // Use WordPress time functions for consistency
        $current_time = current_time('mysql', true); // GMT time
        $one_hour_ago = gmdate('Y-m-d H:i:s', strtotime($current_time) - 3600);
        
        // Rate limiting - max 5 OTPs per email per hour
        $recent_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE email = %s AND created_at > %s",
            $email,
            $one_hour_ago
        ));
        
        if ($recent_count >= 5) {
            return new WP_Error('rate_limit', __('Too many OTP requests. Please try again later.', 'QMV-magic-login'));
        }
        
        // Generate 5-digit numeric OTP
        $otp = sprintf('%05d', wp_rand(0, 99999));
        
        // Calculate expiry (default 10 minutes)
        $expiry_minutes = get_option('qml_otp_expiry', 10);
        $expires_at = gmdate('Y-m-d H:i:s', strtotime($current_time) + ($expiry_minutes * 60));
        
        // Invalidate any existing OTPs for this email
        $wpdb->update(
            $table,
            array('used' => 1),
            array('email' => $email, 'used' => 0),
            array('%d'),
            array('%s', '%d')
        );
        
        // Store new OTP
        $result = $wpdb->insert(
            $table,
            array(
                'email' => $email,
                'otp' => $otp,
                'created_at' => $current_time,
                'expires_at' => $expires_at,
                'attempts' => 0,
                'used' => 0
            ),
            array('%s', '%s', '%s', '%s', '%d', '%d')
        );
        
        if (!$result) {
            // Log the error for debugging
            error_log('QML OTP Insert Error: ' . $wpdb->last_error);
            return new WP_Error('db_error', __('Could not generate OTP. Please try again.', 'QMV-magic-login'));
        }
        
        // Log for debugging
        error_log("QML OTP Generated - Email: $email, OTP: $otp, Expires: $expires_at, Current: $current_time");
        
        return $otp;
    }
    
    /**
     * Create OTP table if it doesn't exist
     */
    private function create_otp_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'qml_otp';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Verify OTP
     * 
     * @param string $email User's email
     * @param string $otp The OTP to verify
     * @return bool|WP_Error True if valid, WP_Error otherwise
     */
    public function verify_otp($email, $otp) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qml_otp';
        $current_time = current_time('mysql', true); // GMT time
        
        // Debug logging
        error_log("QML OTP Verify - Email: $email, OTP entered: $otp, Current time: $current_time");
        
        // Get the latest unused OTP for this email
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE email = %s AND used = 0 AND expires_at > %s 
             ORDER BY created_at DESC LIMIT 1",
            $email,
            $current_time
        ));
        
        // Debug: also check what's in the database
        $all_records = $wpdb->get_results($wpdb->prepare(
            "SELECT id, otp, created_at, expires_at, used FROM $table WHERE email = %s ORDER BY created_at DESC LIMIT 5",
            $email
        ));
        error_log("QML OTP Records for $email: " . print_r($all_records, true));
        
        if (!$record) {
            return new WP_Error('no_otp', __('No valid OTP found. Please request a new one.', 'QMV-magic-login'));
        }
        
        error_log("QML OTP Found - ID: {$record->id}, Stored OTP: {$record->otp}, Expires: {$record->expires_at}");
        
        // Check attempts (max 5)
        if ($record->attempts >= 5) {
            // Mark as used to prevent further attempts
            $wpdb->update(
                $table,
                array('used' => 1),
                array('id' => $record->id),
                array('%d'),
                array('%d')
            );
            return new WP_Error('max_attempts', __('Too many failed attempts. Please request a new OTP.', 'QMV-magic-login'));
        }
        
        // Increment attempts
        $wpdb->update(
            $table,
            array('attempts' => $record->attempts + 1),
            array('id' => $record->id),
            array('%d'),
            array('%d')
        );
        
        // Verify OTP (sanitize input - only digits)
        $otp = preg_replace('/[^0-9]/', '', $otp);
        
        if ($otp !== $record->otp) {
            $remaining = 5 - ($record->attempts + 1);
            return new WP_Error(
                'invalid_otp', 
                sprintf(
                    __('Invalid OTP. %d attempts remaining.', 'QMV-magic-login'),
                    $remaining
                )
            );
        }
        
        // Mark OTP as used
        $wpdb->update(
            $table,
            array('used' => 1),
            array('id' => $record->id),
            array('%d'),
            array('%d')
        );
        
        error_log("QML OTP Verified successfully for $email");
        
        return true;
    }
    
    /**
     * Cleanup expired OTPs
     */
    public function cleanup_expired_otps() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'qml_otp';
        
        // Delete OTPs older than 24 hours
        $wpdb->query(
            "DELETE FROM $table WHERE expires_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }
}
