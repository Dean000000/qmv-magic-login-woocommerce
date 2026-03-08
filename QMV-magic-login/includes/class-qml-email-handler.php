<?php
/**
 * Email Handler Class
 * Handles all email sending for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_Email_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into WooCommerce stock status change
        add_action('woocommerce_product_set_stock_status', array($this, 'check_back_in_stock'), 10, 3);
    }
    
    /**
     * Send OTP email
     */
    public function send_otp_email($email, $otp) {
        $site_name = get_bloginfo('name');
        $from_name = get_option('qml_email_from_name', $site_name);
        $from_email = get_option('qml_email_from_address', get_option('admin_email'));
        
        $subject = sprintf(__('Your login code for %s', 'QMV-magic-login'), $site_name);
        
        $message = $this->get_otp_email_template($otp, $site_name);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email)
        );
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if (!$sent) {
            return new WP_Error('email_failed', __('Failed to send email. Please try again.', 'QMV-magic-login'));
        }
        
        return true;
    }
    
    /**
     * Get OTP email template
     */
    private function get_otp_email_template($otp, $site_name) {
        $expiry_minutes = get_option('qml_otp_expiry', 10);
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f5f5f5;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f5; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: #2563eb; padding: 32px 40px; text-align: center;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        <?php echo esc_html($site_name); ?>
                                    </h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style="padding: 40px;">
                                    <h2 style="margin: 0 0 16px; color: #1f2937; font-size: 20px; font-weight: 600;">
                                        <?php esc_html_e('Your Login Code', 'QMV-magic-login'); ?>
                                    </h2>
                                    
                                    <p style="margin: 0 0 24px; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                        <?php esc_html_e('Enter this code to sign in to your account:', 'QMV-magic-login'); ?>
                                    </p>
                                    
                                    <!-- OTP Code -->
                                    <div style="background-color: #f3f4f6; border-radius: 8px; padding: 24px; text-align: center; margin-bottom: 24px;">
                                        <span style="font-size: 36px; font-weight: 700; letter-spacing: 8px; color: #1f2937; font-family: 'Courier New', monospace;">
                                            <?php echo esc_html($otp); ?>
                                        </span>
                                    </div>
                                    
                                    <p style="margin: 0 0 8px; color: #6b7280; font-size: 14px;">
                                        <?php printf(
                                            esc_html__('This code expires in %d minutes.', 'QMV-magic-login'),
                                            $expiry_minutes
                                        ); ?>
                                    </p>
                                    
                                    <p style="margin: 0; color: #9ca3af; font-size: 13px;">
                                        <?php esc_html_e("If you didn't request this code, you can safely ignore this email.", 'QMV-magic-login'); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f9fafb; padding: 24px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
                                    <p style="margin: 0; color: #9ca3af; font-size: 13px;">
                                        &copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Check if product is back in stock and notify subscribers
     */
    public function check_back_in_stock($product_id, $stock_status, $product) {
        if ($stock_status !== 'instock') {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'qml_stock_notifications';
        
        // Get all unnotified subscribers for this product
        $subscribers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND notified = 0",
            $product_id
        ));
        
        if (empty($subscribers)) {
            return;
        }
        
        foreach ($subscribers as $subscriber) {
            // Check if user has opted in to back_in_stock notifications
            $prefs_table = $wpdb->prefix . 'qml_email_preferences';
            $opted_in = $wpdb->get_var($wpdb->prepare(
                "SELECT back_in_stock FROM $prefs_table WHERE user_id = %d",
                $subscriber->user_id
            ));
            
            if ($opted_in === '0') {
                continue;
            }
            
            // Send notification
            $this->send_back_in_stock_email($subscriber->email, $product);
            
            // Mark as notified
            $wpdb->update(
                $table,
                array('notified' => 1),
                array('id' => $subscriber->id),
                array('%d'),
                array('%d')
            );
        }
    }
    
    /**
     * Send back in stock email
     */
    public function send_back_in_stock_email($email, $product) {
        $site_name = get_bloginfo('name');
        $from_name = get_option('qml_email_from_name', $site_name);
        $from_email = get_option('qml_email_from_address', get_option('admin_email'));
        
        $product_name = $product->get_name();
        $product_url = $product->get_permalink();
        $product_image = wp_get_attachment_image_url($product->get_image_id(), 'medium');
        $product_price = $product->get_price_html();
        
        $subject = sprintf(__('%s is back in stock!', 'QMV-magic-login'), $product_name);
        
        $message = $this->get_back_in_stock_template($product_name, $product_url, $product_image, $product_price, $site_name);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email)
        );
        
        wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Get back in stock email template
     */
    private function get_back_in_stock_template($product_name, $product_url, $product_image, $product_price, $site_name) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f5f5f5;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f5; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: #10b981; padding: 32px 40px; text-align: center;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        <?php esc_html_e('Good News! 🎉', 'QMV-magic-login'); ?>
                                    </h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style="padding: 40px;">
                                    <h2 style="margin: 0 0 16px; color: #1f2937; font-size: 18px; font-weight: 600;">
                                        <?php echo esc_html($product_name); ?> <?php esc_html_e('is back in stock!', 'QMV-magic-login'); ?>
                                    </h2>
                                    
                                    <?php if ($product_image) : ?>
                                    <div style="margin-bottom: 24px; text-align: center;">
                                        <img src="<?php echo esc_url($product_image); ?>" alt="<?php echo esc_attr($product_name); ?>" style="max-width: 200px; border-radius: 8px;">
                                    </div>
                                    <?php endif; ?>
                                    
                                    <p style="margin: 0 0 24px; color: #6b7280; font-size: 15px; line-height: 1.6;">
                                        <?php esc_html_e('The item you were waiting for is now available. Get it before it sells out again!', 'QMV-magic-login'); ?>
                                    </p>
                                    
                                    <?php if ($product_price) : ?>
                                    <p style="margin: 0 0 24px; color: #1f2937; font-size: 18px; font-weight: 600;">
                                        <?php echo $product_price; ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <a href="<?php echo esc_url($product_url); ?>" style="display: inline-block; background-color: #2563eb; color: #ffffff; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 15px;">
                                        <?php esc_html_e('Shop Now', 'QMV-magic-login'); ?>
                                    </a>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f9fafb; padding: 24px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
                                    <p style="margin: 0 0 8px; color: #9ca3af; font-size: 13px;">
                                        <?php esc_html_e('You received this email because you asked to be notified.', 'QMV-magic-login'); ?>
                                    </p>
                                    <p style="margin: 0; color: #9ca3af; font-size: 13px;">
                                        &copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Send bulk email to users
     * This is called from admin
     */
    public function send_bulk_email($subject, $message, $user_ids = array(), $preference_filter = null) {
        global $wpdb;
        
        $site_name = get_bloginfo('name');
        $from_name = get_option('qml_email_from_name', $site_name);
        $from_email = get_option('qml_email_from_address', get_option('admin_email'));
        
        // Get users to email
        if (empty($user_ids)) {
            // Get all users who have opted in to promotions
            $prefs_table = $wpdb->prefix . 'qml_email_preferences';
            
            $query = "SELECT u.user_email FROM {$wpdb->users} u 
                      INNER JOIN $prefs_table p ON u.ID = p.user_id";
            
            if ($preference_filter) {
                $query .= $wpdb->prepare(" WHERE p.{$preference_filter} = %d", 1);
            }
            
            $emails = $wpdb->get_col($query);
        } else {
            $emails = array();
            foreach ($user_ids as $user_id) {
                $user = get_user_by('id', $user_id);
                if ($user) {
                    $emails[] = $user->user_email;
                }
            }
        }
        
        if (empty($emails)) {
            return new WP_Error('no_recipients', __('No recipients found.', 'QMV-magic-login'));
        }
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email)
        );
        
        $sent_count = 0;
        $failed_count = 0;
        
        foreach ($emails as $email) {
            // Wrap message in template
            $full_message = $this->get_bulk_email_template($message, $site_name);
            
            $sent = wp_mail($email, $subject, $full_message, $headers);
            
            if ($sent) {
                $sent_count++;
            } else {
                $failed_count++;
            }
            
            // Small delay to prevent overwhelming mail server
            usleep(100000); // 0.1 second
        }
        
        return array(
            'sent' => $sent_count,
            'failed' => $failed_count,
            'total' => count($emails)
        );
    }
    
    /**
     * Get bulk email template
     */
    private function get_bulk_email_template($message, $site_name) {
        $unsubscribe_url = wc_get_account_endpoint_url('edit-account'); // Profile page
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; background-color: #f5f5f5;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f5f5f5; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: #2563eb; padding: 32px 40px; text-align: center;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        <?php echo esc_html($site_name); ?>
                                    </h1>
                                </td>
                            </tr>
                            
                            <!-- Body -->
                            <tr>
                                <td style="padding: 40px;">
                                    <div style="color: #374151; font-size: 15px; line-height: 1.7;">
                                        <?php echo wp_kses_post($message); ?>
                                    </div>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f9fafb; padding: 24px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
                                    <p style="margin: 0 0 8px; color: #9ca3af; font-size: 13px;">
                                        <a href="<?php echo esc_url($unsubscribe_url); ?>" style="color: #6b7280;">
                                            <?php esc_html_e('Manage email preferences', 'QMV-magic-login'); ?>
                                        </a>
                                    </p>
                                    <p style="margin: 0; color: #9ca3af; font-size: 13px;">
                                        &copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
