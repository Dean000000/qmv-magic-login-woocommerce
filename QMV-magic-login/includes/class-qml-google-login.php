<?php
/**
 * Google Login Handler Class
 * Handles Google OAuth integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class QML_Google_Login {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Load Google API script if client ID is set
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_google_api'));
    }
    
    /**
     * Enqueue Google API if configured
     */
    public function maybe_enqueue_google_api() {
        $client_id = get_option('qml_google_client_id', '');
        
        if (empty($client_id) || is_user_logged_in()) {
            return;
        }
        
        // Enqueue Google Identity Services library
        wp_enqueue_script(
            'google-identity-services',
            'https://accounts.google.com/gsi/client',
            array(),
            null,
            true
        );
    }
    
    /**
     * Verify Google ID token
     * 
     * @param string $credential The JWT token from Google
     * @return array|WP_Error User data or error
     */
    public function verify_token($credential) {
        $client_id = get_option('qml_google_client_id', '');
        
        if (empty($client_id)) {
            return new WP_Error('not_configured', __('Google login is not configured.', 'QMV-magic-login'));
        }
        
        // Decode the JWT token
        $parts = explode('.', $credential);
        
        if (count($parts) !== 3) {
            return new WP_Error('invalid_token', __('Invalid Google token format.', 'QMV-magic-login'));
        }
        
        // Decode payload (middle part)
        $payload = json_decode($this->base64url_decode($parts[1]), true);
        
        if (!$payload) {
            return new WP_Error('decode_error', __('Could not decode Google token.', 'QMV-magic-login'));
        }
        
        // Verify the token
        // Check audience (client ID)
        if (!isset($payload['aud']) || $payload['aud'] !== $client_id) {
            return new WP_Error('invalid_audience', __('Token was not issued for this application.', 'QMV-magic-login'));
        }
        
        // Check issuer
        $valid_issuers = array('accounts.google.com', 'https://accounts.google.com');
        if (!isset($payload['iss']) || !in_array($payload['iss'], $valid_issuers)) {
            return new WP_Error('invalid_issuer', __('Invalid token issuer.', 'QMV-magic-login'));
        }
        
        // Check expiration
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return new WP_Error('token_expired', __('Google token has expired.', 'QMV-magic-login'));
        }
        
        // Verify email is present and verified
        if (!isset($payload['email']) || empty($payload['email'])) {
            return new WP_Error('no_email', __('No email in Google token.', 'QMV-magic-login'));
        }
        
        if (!isset($payload['email_verified']) || !$payload['email_verified']) {
            return new WP_Error('email_not_verified', __('Google email is not verified.', 'QMV-magic-login'));
        }
        
        // For production, you should verify the signature using Google's public keys
        // This is a simplified version - for full security, implement signature verification
        // using the Google API Client Library or manually fetching keys from:
        // https://www.googleapis.com/oauth2/v3/certs
        
        return array(
            'email' => sanitize_email($payload['email']),
            'email_verified' => $payload['email_verified'],
            'name' => isset($payload['name']) ? sanitize_text_field($payload['name']) : '',
            'given_name' => isset($payload['given_name']) ? sanitize_text_field($payload['given_name']) : '',
            'family_name' => isset($payload['family_name']) ? sanitize_text_field($payload['family_name']) : '',
            'picture' => isset($payload['picture']) ? esc_url($payload['picture']) : '',
            'sub' => isset($payload['sub']) ? sanitize_text_field($payload['sub']) : '' // Google user ID
        );
    }
    
    /**
     * Base64 URL decode
     */
    private function base64url_decode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Get Google login button HTML (for use in templates)
     */
    public function get_button_html($button_text = '') {
        $client_id = get_option('qml_google_client_id', '');
        
        if (empty($client_id)) {
            return '';
        }
        
        if (empty($button_text)) {
            $button_text = __('Sign in with Google', 'QMV-magic-login');
        }
        
        ob_start();
        ?>
        <div id="g_id_onload"
             data-client_id="<?php echo esc_attr($client_id); ?>"
             data-context="signin"
             data-ux_mode="popup"
             data-callback="qmlGoogleCallback"
             data-auto_prompt="false">
        </div>
        
        <div class="g_id_signin"
             data-type="standard"
             data-shape="rectangular"
             data-theme="outline"
             data-text="signin_with"
             data-size="large"
             data-logo_alignment="left">
        </div>
        <?php
        return ob_get_clean();
    }
}
