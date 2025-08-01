/**
 * Simplified JavaScript for SC WP Plugin admin
 * Focused on essential functionality only
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initializeSyncButton();
        initializeFormValidation();
    });

    /**
     * Initialize sync button functionality
     */
    function initializeSyncButton() {
        $('#run-sync').on('click', function(e) {
            e.preventDefault();
            
            if ($(this).prop('disabled')) {
                return;
            }
            
            if (!confirm('Run product sync now? This may take a few minutes.')) {
                return;
            }
            
            runSync();
        });
    }

    /**
     * Run sync operation
     */
    function runSync() {
        const $button = $('#run-sync');
        const $messages = $('#sync-messages');
        
        // Update UI
        $button.prop('disabled', true).text('Syncing...');
        showMessage('Starting sync...', 'info');
        
        // Make AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sc_run_sync',
                nonce: getSyncNonce()
            },
            timeout: 300000, // 5 minute timeout
            success: function(response) {
                if (response.success) {
                    handleSyncSuccess(response.data);
                    
                    // Refresh page after delay to show updated stats
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                } else {
                    handleSyncError(response.data?.message || 'Unknown error');
                    resetSyncButton();
                }
            },
            error: function(xhr, status, error) {
                handleSyncError('Connection error: ' + error);
                resetSyncButton();
            }
        });
    }

    /**
     * Handle successful sync
     */
    function handleSyncSuccess(data) {
        let message = 'Sync completed successfully!';
        
        if (data.processed) {
            message += ' Processed ' + data.processed + ' items.';
        }
        if (data.created) {
            message += ' Created ' + data.created + ' new products.';
        }
        if (data.deleted) {
            message += ' Removed ' + data.deleted + ' discontinued items.';
        }
        
        showMessage(message, 'success');
    }

    /**
     * Handle sync error
     */
    function handleSyncError(message) {
        showMessage('Sync failed: ' + message, 'error');
    }

    /**
     * Reset sync button to original state
     */
    function resetSyncButton() {
        $('#run-sync').prop('disabled', false).text('Run Sync Now');
    }

    /**
     * Show message to user
     */
    function showMessage(message, type) {
        const $messages = $('#sync-messages');
        const messageHtml = '<div class="notice notice-' + type + '"><p>' + escapeHtml(message) + '</p></div>';
        
        $messages.html(messageHtml);
        
        // Auto-hide success messages
        if (type === 'success') {
            setTimeout(function() {
                $messages.find('.notice').fadeOut(300);
            }, 5000);
        }
    }

    /**
     * Initialize form validation
     */
    function initializeFormValidation() {
        // API ID validation
        $('input[name="sc_api_id"]').on('blur', function() {
            const value = $(this).val().trim();
            if (value.length > 0 && value.length < 10) {
                showMessage('API ID seems too short. Please verify it\'s correct.', 'warning');
            }
        });

        // Batch size validation
        $('input[name="sc_api_rows_per_request"]').on('change blur', function() {
            let value = parseInt($(this).val());
            
            if (isNaN(value) || value < 500) {
                $(this).val(500);
                showMessage('Minimum batch size is 500.', 'warning');
            } else if (value > 5000) {
                $(this).val(5000);
                showMessage('Maximum batch size is 5000.', 'warning');
            }
        });
    }

    /**
     * Get sync nonce
     */
    function getSyncNonce() {
        // Try to get from localized script data first
        if (typeof scSyncData !== 'undefined' && scSyncData.nonce) {
            return scSyncData.nonce;
        }
        
        // Fallback: create nonce (this should match the PHP nonce)
        return $('#wp_nonce').val() || '';
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Utility: Log message (only in debug mode)
     */
    function debugLog(message) {
        if (window.console && console.log && typeof scSyncData !== 'undefined' && scSyncData.debug) {
            console.log('SC Sync: ' + message);
        }
    }

})(jQuery);