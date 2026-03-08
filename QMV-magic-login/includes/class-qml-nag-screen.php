<?php
/**
 * Nag Screen Class
 * Handles the login prompt popup for non-logged-in users
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_Nag_Screen {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_footer', array($this, 'render_nag_screen'));
    }
    
    /**
     * Check if nag screen should be shown
     */
    private function should_show_nag() {
        // Don't show if logged in
        if (is_user_logged_in()) {
            return false;
        }
        
        // Don't show on admin pages
        if (is_admin()) {
            return false;
        }
        
        // Don't show on actual login/register pages (WooCommerce my-account handles both)
        if (is_page('my-account')) {
            return false;
        }
        
        // Check if nag is enabled
        if (!get_option('qml_nag_enabled', true)) {
            return false;
        }
        
        // Allow search engines/bots to crawl without the nag
        // This preserves SEO while blocking human visitors
        if ($this->is_bot()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if current visitor is a search engine bot
     */
    private function is_bot() {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }
        
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        
        // Common search engine and social media bots
        $bots = array(
            'googlebot',
            'bingbot',
            'slurp',           // Yahoo
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'exabot',
            'facebot',         // Facebook
            'facebookexternalhit',
            'ia_archiver',     // Alexa
            'twitterbot',
            'linkedinbot',
            'pinterest',
            'whatsapp',
            'telegrambot',
            'applebot',
            'semrushbot',
            'ahrefsbot',
            'mj12bot',
            'dotbot',
            'screaming frog',
            'lighthouse',      // Google PageSpeed
            'gtmetrix',
            'pingdom',
            'uptimerobot',
        );
        
        foreach ($bots as $bot) {
            if (strpos($user_agent, $bot) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Render the nag screen HTML
     */
    public function render_nag_screen() {
        if (!$this->should_show_nag()) {
            return;
        }
        
        $google_client_id = get_option('qml_google_client_id', '');
        $show_google = !empty($google_client_id);
        
        ?>
        <!-- QMV Magic Login Modal -->
        <div id="qml-modal-overlay" class="qml-modal-overlay qml-blocking" style="display: none;">
            <div class="qml-modal">
                <!-- No close button - login is required -->
                
                <div class="qml-modal-header">
                    <div class="qml-logo">
                        <?php 
                        $custom_logo_id = get_theme_mod('custom_logo');
                        if ($custom_logo_id) {
                            echo wp_get_attachment_image($custom_logo_id, 'medium', false, array('class' => 'qml-site-logo'));
                        } else {
                            echo '<h2>' . esc_html(get_bloginfo('name')) . '</h2>';
                        }
                        ?>
                    </div>
                    <h3 class="qml-modal-title"><?php esc_html_e('Welcome to QMV!', 'QMV-magic-login'); ?></h3>
                    <p class="qml-modal-subtitle"><?php esc_html_e('Please sign in to browse our store. This helps us save your favorites, notify you about restocks, and provide a personalized shopping experience.', 'QMV-magic-login'); ?></p>
                </div>
                
                <div class="qml-modal-body">
                    <!-- Step 1: Email Entry -->
                    <div id="qml-step-email" class="qml-step active">
                        <form id="qml-email-form" class="qml-form">
                            <div class="qml-form-group">
                                <label for="qml-email" class="qml-label"><?php esc_html_e('Email Address', 'QMV-magic-login'); ?></label>
                                <input type="email" 
                                       id="qml-email" 
                                       name="email" 
                                       class="qml-input" 
                                       placeholder="<?php esc_attr_e('you@example.com', 'QMV-magic-login'); ?>"
                                       required 
                                       autocomplete="email">
                            </div>
                            
                            <div class="qml-consent-notice">
                                <p>
                                    <small>
                                        <?php esc_html_e('By signing in, you agree to receive email notifications about restocks, new products, and order updates. You can manage your preferences in your profile settings.', 'QMV-magic-login'); ?>
                                    </small>
                                </p>
                            </div>
                            
                            <button type="submit" class="qml-btn qml-btn-primary">
                                <span class="qml-btn-text"><?php esc_html_e('Send Login Code', 'QMV-magic-login'); ?></span>
                                <span class="qml-btn-loading" style="display: none;">
                                    <svg class="qml-spinner" viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" stroke-linecap="round"/>
                                    </svg>
                                    <?php esc_html_e('Sending...', 'QMV-magic-login'); ?>
                                </span>
                            </button>
                        </form>
                        
                        <?php if ($show_google) : ?>
                        <div class="qml-divider">
                            <span><?php esc_html_e('or', 'QMV-magic-login'); ?></span>
                        </div>
                        
                        <div class="qml-social-login">
                            <button type="button" id="qml-google-btn" class="qml-btn qml-btn-google">
                                <svg class="qml-google-icon" viewBox="0 0 24 24" width="18" height="18">
                                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                </svg>
                                <?php esc_html_e('Continue with Google', 'QMV-magic-login'); ?>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Step 2: OTP Verification -->
                    <div id="qml-step-otp" class="qml-step" style="display: none;">
                        <div class="qml-otp-sent-notice">
                            <p><?php esc_html_e('We sent a 5-digit code to:', 'QMV-magic-login'); ?></p>
                            <p class="qml-sent-email"></p>
                        </div>
                        
                        <form id="qml-otp-form" class="qml-form">
                            <div class="qml-form-group">
                                <label for="qml-otp" class="qml-label"><?php esc_html_e('Enter Code', 'QMV-magic-login'); ?></label>
                                <div class="qml-otp-inputs">
                                    <input type="text" 
                                           inputmode="numeric" 
                                           pattern="[0-9]*"
                                           maxlength="1" 
                                           class="qml-otp-digit" 
                                           data-index="0"
                                           autocomplete="one-time-code">
                                    <input type="text" 
                                           inputmode="numeric" 
                                           pattern="[0-9]*"
                                           maxlength="1" 
                                           class="qml-otp-digit" 
                                           data-index="1">
                                    <input type="text" 
                                           inputmode="numeric" 
                                           pattern="[0-9]*"
                                           maxlength="1" 
                                           class="qml-otp-digit" 
                                           data-index="2">
                                    <input type="text" 
                                           inputmode="numeric" 
                                           pattern="[0-9]*"
                                           maxlength="1" 
                                           class="qml-otp-digit" 
                                           data-index="3">
                                    <input type="text" 
                                           inputmode="numeric" 
                                           pattern="[0-9]*"
                                           maxlength="1" 
                                           class="qml-otp-digit" 
                                           data-index="4">
                                </div>
                                <input type="hidden" id="qml-otp-combined" name="otp">
                            </div>
                            
                            <button type="submit" class="qml-btn qml-btn-primary">
                                <span class="qml-btn-text"><?php esc_html_e('Verify & Sign In', 'QMV-magic-login'); ?></span>
                                <span class="qml-btn-loading" style="display: none;">
                                    <svg class="qml-spinner" viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" stroke-linecap="round"/>
                                    </svg>
                                    <?php esc_html_e('Verifying...', 'QMV-magic-login'); ?>
                                </span>
                            </button>
                        </form>
                        
                        <div class="qml-otp-actions">
                            <button type="button" id="qml-resend-otp" class="qml-link-btn">
                                <?php esc_html_e("Didn't receive the code? Resend", 'QMV-magic-login'); ?>
                            </button>
                            <button type="button" id="qml-change-email" class="qml-link-btn">
                                <?php esc_html_e('Use different email', 'QMV-magic-login'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Success State -->
                    <div id="qml-step-success" class="qml-step" style="display: none;">
                        <div class="qml-success-icon">
                            <svg viewBox="0 0 24 24" width="64" height="64">
                                <circle cx="12" cy="12" r="11" fill="#10B981"/>
                                <path d="M7 12l3 3 7-7" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <h3><?php esc_html_e('Welcome!', 'QMV-magic-login'); ?></h3>
                        <p><?php esc_html_e("You're now signed in. Enjoy your shopping!", 'QMV-magic-login'); ?></p>
                    </div>
                    
                    <!-- Error Message Container -->
                    <div id="qml-error-message" class="qml-error-message" style="display: none;"></div>
                </div>
                
                <div class="qml-modal-footer">
                    <p class="qml-privacy-note">
                        <small><?php esc_html_e('Your email is only used for login and notifications. We never share your data.', 'QMV-magic-login'); ?></small>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }
}
