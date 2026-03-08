/**
 * QMV Magic Login - Admin JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Update recipient count when filter changes
        $('#qml-recipient-filter').on('change', function() {
            updateRecipientCount();
        });
        
        // Initial count update
        if ($('#qml-recipient-filter').length) {
            updateRecipientCount();
        }
        
        // Preview email
        $('#qml-preview-email').on('click', function() {
            var subject = $('#qml-email-subject').val();
            var content = getEditorContent();
            
            if (!subject || !content) {
                alert('Please enter a subject and message.');
                return;
            }
            
            // Open preview in new window
            var previewHtml = '<html><head><title>Email Preview</title>' +
                '<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:600px;margin:40px auto;padding:20px;}</style>' +
                '</head><body>' +
                '<h2>' + escapeHtml(subject) + '</h2>' +
                '<hr>' +
                content +
                '</body></html>';
            
            var previewWindow = window.open('', 'Preview', 'width=700,height=600');
            previewWindow.document.write(previewHtml);
            previewWindow.document.close();
        });
        
        // Send bulk email
        $('#qml-send-bulk-email').on('click', function() {
            var subject = $('#qml-email-subject').val();
            var content = getEditorContent();
            var filter = $('#qml-recipient-filter').val();
            
            if (!subject || !content) {
                alert('Please enter a subject and message.');
                return;
            }
            
            if (!confirm('Are you sure you want to send this email to all selected subscribers?')) {
                return;
            }
            
            var $btn = $(this);
            var $status = $('#qml-email-status');
            
            $btn.prop('disabled', true).text('Sending...');
            $status.hide();
            
            $.ajax({
                url: qml_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'qml_send_bulk_email',
                    nonce: qml_admin.nonce,
                    subject: subject,
                    message: content,
                    filter: filter
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Send Email');
                    
                    if (response.success) {
                        $status.removeClass('error').addClass('success')
                            .text(response.data.message).show();
                    } else {
                        $status.removeClass('success').addClass('error')
                            .text(response.data.message || 'An error occurred.').show();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Send Email');
                    $status.removeClass('success').addClass('error')
                        .text('An error occurred. Please try again.').show();
                }
            });
        });
        
    });
    
    /**
     * Update recipient count
     */
    function updateRecipientCount() {
        var filter = $('#qml-recipient-filter').val();
        var $count = $('#qml-recipient-count');
        
        $count.text('Loading...');
        
        $.ajax({
            url: qml_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'qml_get_user_count',
                nonce: qml_admin.nonce,
                filter: filter
            },
            success: function(response) {
                if (response.success) {
                    $count.text('(' + response.data.count + ' recipients)');
                } else {
                    $count.text('');
                }
            },
            error: function() {
                $count.text('');
            }
        });
    }
    
    /**
     * Get editor content (works with both TinyMCE and plain textarea)
     */
    function getEditorContent() {
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('qml_email_content')) {
            return tinyMCE.get('qml_email_content').getContent();
        }
        return $('#qml_email_content').val();
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
})(jQuery);
