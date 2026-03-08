/**
 * QMV Magic Login - Frontend JavaScript
 */

(function($) {
    'use strict';
    
    // Store current state
    var QML = {
        email: '',
        nagShown: false,
        initialized: false
    };
    
    /**
     * Initialize the magic login
     */
    function init() {
        if (QML.initialized) return;
        QML.initialized = true;
        
        // Don't show nag if logged in
        if (qml_vars.is_logged_in) {
            return;
        }
        
        // Check if nag is enabled
        if (!qml_vars.nag_enabled) {
            return;
        }
        
        // Set up event handlers
        bindEvents();
        
        // Show nag screen after delay (allows page content to load for SEO)
        setTimeout(showNagScreen, qml_vars.nag_delay);
        
        // Initialize Google if configured
        if (qml_vars.google_client_id) {
            initGoogle();
        }
    }
    
    /**
     * Bind event handlers
     */
    function bindEvents() {
        // NOTE: No close button or overlay click - login is required
        // Modal cannot be dismissed without logging in
        
        // Email form submission
        $(document).on('submit', '#qml-email-form', handleEmailSubmit);
        
        // OTP form submission
        $(document).on('submit', '#qml-otp-form', handleOTPSubmit);
        
        // OTP digit inputs
        $(document).on('input', '.qml-otp-digit', handleOTPInput);
        $(document).on('keydown', '.qml-otp-digit', handleOTPKeydown);
        $(document).on('paste', '.qml-otp-digit', handleOTPPaste);
        
        // Resend OTP
        $(document).on('click', '#qml-resend-otp', resendOTP);
        
        // Change email
        $(document).on('click', '#qml-change-email', showEmailStep);
        
        // Google login button
        $(document).on('click', '#qml-google-btn', handleGoogleLogin);
        
        // NOTE: No escape key handler - modal cannot be closed
    }
    
    /**
     * Show the nag screen (blocking - requires login)
     */
    function showNagScreen() {
        if (QML.nagShown || qml_vars.is_logged_in) {
            return;
        }
        
        QML.nagShown = true;
        
        var $overlay = $('#qml-modal-overlay');
        $overlay.css('display', 'flex');
        
        // Prevent body scroll while modal is open
        $('body').addClass('qml-modal-open');
        
        // Trigger animation
        setTimeout(function() {
            $overlay.addClass('qml-visible');
        }, 10);
        
        // Focus email input
        setTimeout(function() {
            $('#qml-email').focus();
        }, 300);
    }
    
    /**
     * Close the modal
     */
    function closeModal() {
        var $overlay = $('#qml-modal-overlay');
        $overlay.removeClass('qml-visible');
        
        setTimeout(function() {
            $overlay.hide();
        }, 300);
    }
    
    /**
     * Skip for later
     */
    function skipForLater() {
        // Get skip duration from settings (default 24 hours)
        var skipHours = 24; // This could be passed from PHP if needed
        var skipUntil = new Date().getTime() + (skipHours * 60 * 60 * 1000);
        localStorage.setItem('qml_skip_until', skipUntil.toString());
        
        closeModal();
    }
    
    /**
     * Handle email form submission
     */
    function handleEmailSubmit(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn = $form.find('.qml-btn-primary');
        var email = $('#qml-email').val().trim();
        
        if (!email || !isValidEmail(email)) {
            showError(qml_vars.i18n.error || 'Please enter a valid email address.');
            return;
        }
        
        // Store email
        QML.email = email;
        
        // Show loading
        $btn.addClass('qml-loading').prop('disabled', true);
        hideError();
        
        // Send OTP request
        $.ajax({
            url: qml_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'qml_send_otp',
                nonce: qml_vars.nonce,
                email: email
            },
            success: function(response) {
                $btn.removeClass('qml-loading').prop('disabled', false);
                
                if (response.success) {
                    showOTPStep(email);
                } else {
                    showError(response.data.message || qml_vars.i18n.error);
                }
            },
            error: function() {
                $btn.removeClass('qml-loading').prop('disabled', false);
                showError(qml_vars.i18n.error);
            }
        });
    }
    
    /**
     * Show OTP step
     */
    function showOTPStep(email) {
        $('#qml-step-email').removeClass('active').hide();
        $('#qml-step-otp').addClass('active').show();
        
        // Display email
        $('.qml-sent-email').text(email);
        
        // Focus first OTP input
        setTimeout(function() {
            $('.qml-otp-digit').first().focus();
        }, 100);
    }
    
    /**
     * Show email step
     */
    function showEmailStep() {
        $('#qml-step-otp').removeClass('active').hide();
        $('#qml-step-email').addClass('active').show();
        
        // Clear OTP inputs
        $('.qml-otp-digit').val('').removeClass('qml-filled');
        
        // Focus email
        $('#qml-email').focus();
    }
    
    /**
     * Handle OTP input
     */
    function handleOTPInput(e) {
        var $input = $(this);
        var value = $input.val();
        
        // Only allow digits
        value = value.replace(/[^0-9]/g, '');
        $input.val(value);
        
        if (value) {
            $input.addClass('qml-filled');
            
            // Move to next input
            var index = parseInt($input.data('index'));
            if (index < 4) {
                $('.qml-otp-digit[data-index="' + (index + 1) + '"]').focus();
            }
        } else {
            $input.removeClass('qml-filled');
        }
        
        // Update combined value
        updateOTPCombined();
        
        // Auto-submit when all filled
        if (isOTPComplete()) {
            $('#qml-otp-form').submit();
        }
    }
    
    /**
     * Handle OTP keydown
     */
    function handleOTPKeydown(e) {
        var $input = $(this);
        var index = parseInt($input.data('index'));
        
        // Backspace - move to previous
        if (e.key === 'Backspace' && !$input.val() && index > 0) {
            $('.qml-otp-digit[data-index="' + (index - 1) + '"]').focus().val('').removeClass('qml-filled');
        }
        
        // Arrow keys
        if (e.key === 'ArrowLeft' && index > 0) {
            $('.qml-otp-digit[data-index="' + (index - 1) + '"]').focus();
        }
        if (e.key === 'ArrowRight' && index < 4) {
            $('.qml-otp-digit[data-index="' + (index + 1) + '"]').focus();
        }
    }
    
    /**
     * Handle OTP paste
     */
    function handleOTPPaste(e) {
        e.preventDefault();
        
        var pastedData = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
        var digits = pastedData.replace(/[^0-9]/g, '').split('').slice(0, 5);
        
        digits.forEach(function(digit, i) {
            var $input = $('.qml-otp-digit[data-index="' + i + '"]');
            $input.val(digit);
            if (digit) {
                $input.addClass('qml-filled');
            }
        });
        
        updateOTPCombined();
        
        // Focus last filled or next empty
        var focusIndex = Math.min(digits.length, 4);
        $('.qml-otp-digit[data-index="' + focusIndex + '"]').focus();
        
        // Auto-submit if complete
        if (isOTPComplete()) {
            setTimeout(function() {
                $('#qml-otp-form').submit();
            }, 100);
        }
    }
    
    /**
     * Update combined OTP value
     */
    function updateOTPCombined() {
        var otp = '';
        $('.qml-otp-digit').each(function() {
            otp += $(this).val();
        });
        $('#qml-otp-combined').val(otp);
    }
    
    /**
     * Check if OTP is complete
     */
    function isOTPComplete() {
        var complete = true;
        $('.qml-otp-digit').each(function() {
            if (!$(this).val()) {
                complete = false;
            }
        });
        return complete;
    }
    
    /**
     * Handle OTP form submission
     */
    function handleOTPSubmit(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn = $form.find('.qml-btn-primary');
        var otp = $('#qml-otp-combined').val();
        
        if (!otp || otp.length !== 5) {
            showError('Please enter the complete 5-digit code.');
            return;
        }
        
        // Show loading
        $btn.addClass('qml-loading').prop('disabled', true);
        hideError();
        
        // Verify OTP
        $.ajax({
            url: qml_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'qml_verify_otp',
                nonce: qml_vars.nonce,
                email: QML.email,
                otp: otp
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data);
                } else {
                    $btn.removeClass('qml-loading').prop('disabled', false);
                    showError(response.data.message || qml_vars.i18n.error);
                    
                    // Clear OTP on error
                    $('.qml-otp-digit').val('').removeClass('qml-filled').first().focus();
                }
            },
            error: function() {
                $btn.removeClass('qml-loading').prop('disabled', false);
                showError(qml_vars.i18n.error);
            }
        });
    }
    
    /**
     * Resend OTP
     */
    function resendOTP() {
        var $btn = $(this);
        
        if ($btn.hasClass('qml-disabled')) {
            return;
        }
        
        $btn.addClass('qml-disabled').text('Sending...');
        hideError();
        
        $.ajax({
            url: qml_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'qml_send_otp',
                nonce: qml_vars.nonce,
                email: QML.email
            },
            success: function(response) {
                $btn.removeClass('qml-disabled').text("Didn't receive the code? Resend");
                
                if (response.success) {
                    // Clear and refocus
                    $('.qml-otp-digit').val('').removeClass('qml-filled').first().focus();
                    showError('New code sent! Check your email.', 'success');
                } else {
                    showError(response.data.message || qml_vars.i18n.error);
                }
            },
            error: function() {
                $btn.removeClass('qml-disabled').text("Didn't receive the code? Resend");
                showError(qml_vars.i18n.error);
            }
        });
    }
    
    /**
     * Show success state
     */
    function showSuccess(data) {
        $('#qml-step-otp').removeClass('active').hide();
        $('#qml-step-success').addClass('active').show();
        
        // Hide footer
        $('.qml-modal-footer').hide();
        
        // Redirect after delay
        setTimeout(function() {
            if (data.redirect_url) {
                window.location.href = data.redirect_url;
            } else {
                // Reload current page
                window.location.reload();
            }
        }, 1500);
    }
    
    /**
     * Show error message
     */
    function showError(message, type) {
        var $error = $('#qml-error-message');
        
        if (type === 'success') {
            $error.css({
                'background': '#ecfdf5',
                'border-color': '#a7f3d0',
                'color': '#059669'
            });
        } else {
            $error.css({
                'background': '#fef2f2',
                'border-color': '#fecaca',
                'color': '#dc2626'
            });
        }
        
        $error.text(message).show();
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $error.fadeOut();
        }, 5000);
    }
    
    /**
     * Hide error message
     */
    function hideError() {
        $('#qml-error-message').hide();
    }
    
    /**
     * Validate email
     */
    function isValidEmail(email) {
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    /**
     * Initialize Google Sign-In
     */
    function initGoogle() {
        // Wait for Google Identity Services to load
        if (typeof google === 'undefined' || !google.accounts) {
            setTimeout(initGoogle, 200);
            return;
        }
        
        try {
            google.accounts.id.initialize({
                client_id: qml_vars.google_client_id,
                callback: handleGoogleResponse,
                auto_select: false,
                cancel_on_tap_outside: false,
                use_fedcm_for_prompt: true // Enable FedCM for future compatibility
            });
            
            // Render the button inside our custom button container
            var googleBtnContainer = document.getElementById('qml-google-btn');
            if (googleBtnContainer) {
                // We'll trigger the prompt manually on click instead of rendering
                console.log('QML: Google Sign-In initialized');
            }
        } catch (e) {
            console.error('QML: Google Sign-In init error:', e);
        }
    }
    
    /**
     * Handle Google login button click
     */
    function handleGoogleLogin(e) {
        e.preventDefault();
        
        if (typeof google === 'undefined' || !google.accounts) {
            showError('Google Sign-In is not available. Please use email login instead.');
            return;
        }
        
        var $btn = $('#qml-google-btn');
        $btn.addClass('qml-loading').prop('disabled', true);
        
        try {
            // Use the One Tap prompt
            google.accounts.id.prompt(function(notification) {
                console.log('QML: Google prompt notification:', notification);
                
                if (notification.isNotDisplayed()) {
                    var reason = notification.getNotDisplayedReason();
                    console.log('QML: Prompt not displayed reason:', reason);
                    
                    $btn.removeClass('qml-loading').prop('disabled', false);
                    
                    // Handle common reasons
                    if (reason === 'opt_out_or_no_session') {
                        showError('Please sign in to your Google account in this browser first, or use email login.');
                    } else if (reason === 'suppressed_by_user') {
                        showError('Google Sign-In was previously declined. Please use email login or clear your browser settings.');
                    } else if (reason === 'browser_not_supported') {
                        showError('Your browser does not support Google Sign-In. Please use email login.');
                    } else {
                        showError('Google Sign-In is not available. Please use email login instead.');
                    }
                } else if (notification.isSkippedMoment()) {
                    $btn.removeClass('qml-loading').prop('disabled', false);
                    console.log('QML: Prompt skipped:', notification.getSkippedReason());
                } else if (notification.isDismissedMoment()) {
                    $btn.removeClass('qml-loading').prop('disabled', false);
                    console.log('QML: Prompt dismissed:', notification.getDismissedReason());
                }
                // If successful, handleGoogleResponse will be called
            });
        } catch (e) {
            console.error('QML: Google prompt error:', e);
            $btn.removeClass('qml-loading').prop('disabled', false);
            showError('Google Sign-In error. Please use email login instead.');
        }
    }
    
    /**
     * Handle Google response
     */
    function handleGoogleResponse(response) {
        if (!response.credential) {
            showError('Google sign-in failed. Please try again.');
            return;
        }
        
        // Show loading state
        $('#qml-google-btn').addClass('qml-loading').prop('disabled', true);
        hideError();
        
        $.ajax({
            url: qml_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'qml_google_login',
                nonce: qml_vars.nonce,
                credential: response.credential
            },
            success: function(response) {
                $('#qml-google-btn').removeClass('qml-loading').prop('disabled', false);
                
                if (response.success) {
                    showSuccess(response.data);
                } else {
                    showError(response.data.message || qml_vars.i18n.error);
                }
            },
            error: function() {
                $('#qml-google-btn').removeClass('qml-loading').prop('disabled', false);
                showError(qml_vars.i18n.error);
            }
        });
    }
    
    // Make Google callback available globally
    window.qmlGoogleCallback = handleGoogleResponse;
    
    // Initialize when DOM is ready
    $(document).ready(init);
    
})(jQuery);
