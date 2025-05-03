/**
 * Enhanced JavaScript for the SC WP Plugin admin
 * With improved error handling and sync logging
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Status polling interval (ms)
        const POLLING_INTERVAL = 2000; // Reduced for more responsiveness
        const MAX_RETRIES = 3;
        
        // Track sync state
        let syncInProgress = false;
        let syncStartTime = null;
        let pollingTimerId = null;
        let logMessages = [];
        let retryCount = 0;
        
        // Set up error handling for AJAX requests
        $(document).ajaxError(function(event, jqxhr, settings, thrownError) {
            console.error('AJAX Error:', settings.url, thrownError);
            // if (settings.url.includes('admin-ajax.php')) {
            //     addLogEntry('error', 'Server error occurred: ' + (thrownError || 'Connection failed') + '. Retrying...');
            // }
        });
        
        // Check for active sync immediately on page load
        checkActiveSyncOnLoad();
        
        // Initialize UI components
        initializeSyncStatus();
        initializeButtons();
        initializeLogDisplay();
        
        // If initial sync hasn't been completed, check status immediately
        if (scSyncData.initialSyncDone === 'false') {
            checkSyncStatus(true);
        }
        
        /**
         * Check for active sync on page load
         */
        function checkActiveSyncOnLoad() {
            fetchSyncStatus(true, function(data) {
                if (!data) return;
                
                // Check if sync is actually in progress
                const activeSync = data.syncActive === 'true';
                
                if (activeSync) {
                    // Show sync in progress UI
                    syncInProgress = true;
                    syncStartTime = new Date();
                    
                    // Disable sync buttons
                    $('#sc-sync-full, #sc-sync-stock').addClass('disabled').prop('disabled', true);
                    
                    // Enable stop button
                    $('#sc-stop-sync').removeClass('disabled').prop('disabled', false);
                    
                    // Show progress indicator with appropriate message
                    $('.sc-sync-progress').removeClass('hidden');
                    $('.sc-sync-progress-message').text(data.syncOperationType || 'Sync in progress');
                    
                    // Clear any existing progress detail
                    $('.sc-sync-progress-detail').remove();
                    
                    // If progress data is available, show it
                    if (data.syncProgress) {
                        updateSyncProgress(data.syncProgress);
                    } else {
                        // Start indeterminate progress animation
                        updateProgress(0);
                        progressAnimation(0);
                    }
                    
                    // Start polling for status updates
                    startStatusPolling();
                    
                    // Expand the log display
                    if ($('.sc-sync-log').length && !$('.sc-sync-log').hasClass('expanded')) {
                        $('.sc-log-control').click();
                    }
                    
                    // Show notification
                    showNotification('info', 'A sync operation is currently in progress.');
                }
                
                // Process log entries if present
                if (data.logEntries && data.logEntries.length) {
                    processLogEntries(data.logEntries);
                }
            });
        }
        
        /**
         * Fetch sync status with error handling
         * 
         * @param {boolean} isInitial Whether this is the initial status check
         * @param {Function} callback Function to call with response data
         */
        function fetchSyncStatus(isInitial, callback) {
            $.ajax({
                url: scSyncData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sc_check_sync_status',
                    nonce: scSyncData.nonce,
                    getActiveState: 1,
                    getLogEntries: 1
                },
                success: function(response) {
                    retryCount = 0; // Reset retry count on success
                    
                    if (response.success) {
                        callback(response.data);
                    } else {
                        showNotification('error', response.data || 'Failed to check sync status');
                        callback(null);
                    }
                },
                error: function(xhr, status, error) {
                    // const errorMsg = 'Server error: ' + (error || 'Connection failed');
                    // 
                    // // Only show error notification once, not for every retry
                    // if (retryCount === 0) {
                    //     showNotification('error', errorMsg);
                    // }
                    // 
                    // // Add to log unless this is initial check
                    // if (!isInitial) {
                    //     addLogEntry('error', errorMsg + ' - Retrying (' + (retryCount + 1) + '/' + MAX_RETRIES + ')');
                    // }
                    // 
                    // // Retry the request if we haven't hit the limit
                    // retryCount++;
                    // if (retryCount <= MAX_RETRIES) {
                    //     setTimeout(function() {
                    //         fetchSyncStatus(isInitial, callback);
                    //     }, 1000); // Wait 1 second before retry
                    // } else {
                    //     retryCount = 0;
                    //     callback(null);
                    // }
                },
                timeout: 10000 // 10 second timeout for status checks
            });
        }
        
        /**
         * Initialize sync status indicators
         */
        function initializeSyncStatus() {
            updateSyncInfo();
            updateLastSyncStatus();
        }
        
        /**
         * Initialize log display area - make it more prominent
         */
        function initializeLogDisplay() {
            // Create log container if it doesn't exist
            if ($('.sc-sync-log-container').length === 0) {
                const $logContainer = $('<div>', {
                    'class': 'sc-sync-log-container',
                    'html': '<h3>Sync Activity Log</h3><div class="sc-sync-log"></div>'
                });
                
                // Add expand/collapse control
                const $logControl = $('<div>', {
                    'class': 'sc-log-control',
                    'html': '<span class="dashicons dashicons-arrow-down-alt2"></span> Show Log'
                });
                
                $logControl.on('click', function() {
                    $('.sc-sync-log').toggleClass('expanded');
                    if ($('.sc-sync-log').hasClass('expanded')) {
                        $('.sc-log-control').html('<span class="dashicons dashicons-arrow-up-alt2"></span> Hide Log');
                    } else {
                        $('.sc-log-control').html('<span class="dashicons dashicons-arrow-down-alt2"></span> Show Log');
                    }
                });
                
                $logContainer.prepend($logControl);
                
                // Insert after the first settings section
                $('.sc-settings-section').eq(1).after($logContainer);
                
                // Add enhanced styles
                $('<style>')
                    .prop('type', 'text/css')
                    .html(`
                        .sc-sync-log-container {
                            margin: 20px 0;
                            border: 1px solid #ccd0d4;
                            background: #fff;
                            padding: 15px;
                            border-radius: 3px;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        }
                        .sc-sync-log {
                            max-height: 300px;
                            overflow-y: auto;
                            font-family: Consolas, Monaco, monospace;
                            font-size: 13px;
                            background: #f8f9fa;
                            padding: 12px;
                            border: 1px solid #eee;
                            display: none;
                            line-height: 1.6;
                            margin-top: 10px;
                        }
                        .sc-sync-log.expanded {
                            display: block;
                        }
                        .sc-log-entry {
                            margin-bottom: 5px;
                            padding: 3px 5px;
                            border-radius: 3px;
                        }
                        .sc-log-entry:hover {
                            background-color: #f0f0f1;
                        }
                        .sc-log-time {
                            color: #646970;
                            margin-right: 8px;
                            display: inline-block;
                            min-width: 80px;
                        }
                        .sc-log-info {
                            color: #007cba;
                        }
                        .sc-log-success {
                            color: #00a32a;
                        }
                        .sc-log-warning {
                            color: #dba617;
                        }
                        .sc-log-error {
                            color: #d63638;
                        }
                        .sc-log-control {
                            cursor: pointer;
                            color: #2271b1;
                            font-weight: 500;
                            margin-bottom: 8px;
                            display: inline-block;
                            padding: 5px 10px;
                            background: #f0f6fc;
                            border-radius: 3px;
                        }
                        .sc-log-control:hover {
                            background: #e5f0fa;
                        }
                        .sc-sync-progress-detail {
                            margin-top: 8px;
                            font-size: 13px;
                            color: #3c434a;
                            font-weight: 500;
                        }
                        .sc-status-indicator {
                            display: inline-block;
                            width: 12px;
                            height: 12px;
                            border-radius: 50%;
                            margin-left: 8px;
                        }
                        .sc-status-ok {
                            background-color: #00a32a;
                        }
                        .sc-status-warning {
                            background-color: #dba617;
                        }
                        .sc-status-error {
                            background-color: #d63638;
                        }
                        .sc-sync-buttons {
                            display: flex;
                            gap: 10px;
                            flex-wrap: wrap;
                            margin: 15px 0;
                        }
                        .sc-sync-progress {
                            margin: 15px 0;
                        }
                        .sc-sync-progress-bar {
                            height: 20px;
                            background-color: #f0f0f1;
                            border-radius: 3px;
                            overflow: hidden;
                            margin-top: 8px;
                            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
                        }
                        .sc-sync-progress-bar-inner {
                            height: 100%;
                            background-color: #2271b1;
                            width: 0;
                            transition: width 0.3s ease;
                        }
                        .sc-notification {
                            padding: 12px 15px;
                            border-radius: 3px;
                            margin-bottom: 15px;
                            position: relative;
                            animation: fadeIn 0.3s ease;
                            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                        }
                        .sc-notification-success {
                            background-color: #edfaef;
                            border-left: 4px solid #00a32a;
                        }
                        .sc-notification-error {
                            background-color: #fcf0f1;
                            border-left: 4px solid #d63638;
                        }
                        .sc-notification-warning {
                            background-color: #fcf9e8;
                            border-left: 4px solid #dba617;
                        }
                        .sc-notification-info {
                            background-color: #f0f6fc;
                            border-left: 4px solid #2271b1;
                        }
                        .sc-notification-close {
                            position: absolute;
                            top: 10px;
                            right: 10px;
                            cursor: pointer;
                            color: #646970;
                        }
                        @keyframes fadeIn {
                            from { opacity: 0; }
                            to { opacity: 1; }
                        }
                        .sc-sync-progress-message {
                            font-weight: 600;
                        }
                    `)
                    .appendTo('head');
            }
        }
        
        /**
         * Initialize button handlers
         */
        function initializeButtons() {
            // Handle full sync button
            $('#sc-sync-full').on('click', function(e) {
                e.preventDefault();
                if ($(this).hasClass('disabled') || $(this).prop('disabled')) return;
                
                if (!confirm('Run a full product sync? This will sync all product data including descriptions, images, and categories.')) {
                    return;
                }
                
                runSync('sc_sync_full', 'Running full product sync');
            });
            
            // Handle stock sync button
            $('#sc-sync-stock').on('click', function(e) {
                e.preventDefault();
                if ($(this).hasClass('disabled') || $(this).prop('disabled')) return;
                
                runSync('sc_sync_stock', 'Updating inventory stock levels');
            });
            
            // Emergency stop button
            $('#sc-stop-sync').on('click', function(e) {
                e.preventDefault();
                if ($(this).hasClass('disabled') || $(this).prop('disabled')) return;
                
                if (!confirm('Are you sure you want to stop the current sync process? This may leave products in an incomplete state.')) {
                    return;
                }
                
                stopSync();
            });
            
            // Add check lock button if it doesn't exist
            // if ($('#sc-check-lock').length === 0) {
            //     const $checkLockButton = $('<button>', {
            //         'id': 'sc-check-lock',
            //         'class': 'button button-secondary',
            //         'text': 'Check Sync Status',
            //         'click': function(e) {
            //             e.preventDefault();
            //             checkAndClearLock(false);
            //         }
            //     });
            //     
            //     $('.sc-sync-buttons').append($checkLockButton);
            // }

            // Add force clear lock button if it doesn't exist
            // if ($('#sc-force-clear-lock').length === 0) {
            //     const $forceClearButton = $('<button>', {
            //         'id': 'sc-force-clear-lock',
            //         'class': 'button button-secondary',
            //         'text': 'Force Clear Lock',
            //         'click': function(e) {
            //             e.preventDefault();
            //             if (!confirm('Are you sure you want to force clear the sync lock? Only do this if you believe the sync is stuck.')) {
            //                 return;
            //             }
            //             checkAndClearLock(true);
            //         }
            //     });
            //     
            //     $('.sc-sync-buttons').append($forceClearButton);
            // }
        }
        
        /**
         * Run a sync operation via AJAX
         * 
         * @param {string} action AJAX action name
         * @param {string} message Progress message to display
         */
        function runSync(action, message) {
            // Check for existing lock first
            $.ajax({
                url: scSyncData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sc_clear_sync_lock',
                    nonce: scSyncData.nonce,
                    force_clear: 'false'
                },
                success: function(response) {
                    if (response.success) {
                        if (!response.data.lock_exists || response.data.lock_cleared) {
                            // No lock exists or it was cleared, proceed with sync
                            startSync(message);
                            performSync(action, message);
                        } else {
                            // Lock exists, show notification
                            showNotification('warning', 
                                response.data.message + ' If you believe this is a stuck sync, ' +
                                'use the "Force Clear Lock" button.'
                            );
                        }
                    } else {
                        showNotification('error', response.data || 'Failed to check sync lock');
                    }
                },
                error: function(xhr, status, error) {
                    // If we can't check the lock, assume we can sync anyway
                    // showNotification('warning', 'Could not check sync locks. Starting sync anyway...');
                    startSync(message);
                    performSync(action, message);
                }
            });
        }
        
        /**
         * Perform the actual sync AJAX request
         * 
         * @param {string} action AJAX action name
         * @param {string} message Progress message to display
         */
        function performSync(action, message) {
            // Log message about sync starting
            addLogEntry('info', 'Sending ' + action.replace('sc_', '') + ' request to server...');
            
            $.ajax({
                url: scSyncData.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: scSyncData.nonce,
                    force_sync: 'true' // Force sync when manually triggered
                },
                success: function(response) {
                    if (response.success) {
                        // Update sync data
                        updateSyncDataFromResponse(response.data);
                        updateSyncInfo();
                        
                        // Add to log
                        addLogEntry('success', response.data.message);
                        
                        // Show success notification
                        showNotification('success', response.data.message);
                    } else {
                        // Add to log
                        addLogEntry('error', response.data || 'Sync failed: Unknown error');
                        
                        // Show error notification
                        showNotification('error', response.data || 'Sync failed: Unknown error');
                    }
                    endSync();
                },
                error: function(xhr, status, error) {
                    // Continue polling anyway, since the sync might have started on the server
                    // but the response failed to come back to the browser
                    // let errorMsg = 'Connection error while starting sync: ' + (error || 'Server error');
                    // 
                    // addLogEntry('warning', errorMsg + ' - Will continue polling for sync status in case it started successfully.');
                    // showNotification('warning', errorMsg + ' - Will continue to check if sync is running.');
                    // 
                    // // Don't end sync yet - keep polling to see if it's actually running
                    // startStatusPolling();
                },
                timeout: 30000 // 30 second timeout for sync requests
            });
        }
        
        /**
         * Check and clear sync lock
         * 
         * @param {boolean} forceClear Whether to force clear the lock
         */
        function checkAndClearLock(forceClear = false) {
            addLogEntry('info', 'Checking sync lock status' + (forceClear ? ' (with force clear)' : '') + '...');
            
            $.ajax({
                url: scSyncData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sc_clear_sync_lock',
                    nonce: scSyncData.nonce,
                    force_clear: forceClear ? 'true' : 'false'
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.lock_cleared) {
                            showNotification('success', response.data.message);
                            addLogEntry('success', 'Sync lock cleared successfully.');
                            
                            // Re-enable sync buttons
                            $('#sc-sync-full, #sc-sync-stock').removeClass('disabled').prop('disabled', false);
                            endSync();
                            
                            // Refresh the page to update server-side rendering
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else if (response.data.lock_exists) {
                            addLogEntry('warning', 'Sync lock exists: ' + response.data.message);
                            
                            // Show warning notification
                            showNotification('warning', 
                                response.data.message + ' If you believe this is a stuck sync, ' +
                                'use the "Force Clear Lock" button.'
                            );
                        } else {
                            addLogEntry('info', 'No sync lock found.');
                            showNotification('info', response.data.message || 'No sync lock found.');
                        }
                    } else {
                        addLogEntry('error', 'Failed to check sync lock: ' + (response.data || 'Unknown error'));
                        showNotification('error', response.data || 'Failed to check sync lock');
                    }
                },
                error: function(xhr, status, error) {
                    // const errorMsg = 'Server error while checking lock: ' + (error || 'Connection failed');
                    // addLogEntry('error', errorMsg);
                    // showNotification('error', errorMsg);
                }
            });
        }
        
        /**
         * Stop the current sync operation
         */
        function stopSync() {
            addLogEntry('warning', 'Attempting to stop sync operation...');
            
            $.ajax({
                url: scSyncData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sc_kill_sync',
                    nonce: scSyncData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        addLogEntry('info', 'Sync termination signal sent. The operation should stop shortly.');
                        showNotification('info', 'Sync termination signal sent. The operation should stop shortly.');
                    } else {
                        addLogEntry('error', 'Failed to send stop signal: ' + (response.data || 'Unknown error'));
                        showNotification('error', 'Failed to send stop signal');
                    }
                },
                error: function(xhr, status, error) {
                    // const errorMsg = 'Connection error while stopping sync: ' + (error || 'Server error');
                    // addLogEntry('error', errorMsg);
                    // showNotification('error', errorMsg);
                }
            });
        }
        
        /**
         * Start polling for status updates
         */
        function startStatusPolling() {
            // Clear any existing timer
            if (pollingTimerId) {
                clearTimeout(pollingTimerId);
            }
            
            syncInProgress = true;
            if (!syncStartTime) {
                syncStartTime = new Date();
            }
            
            // Start polling
            pollingTimerId = setTimeout(checkSyncStatus, POLLING_INTERVAL);
            addLogEntry('info', 'Started monitoring sync status...');
        }
        
        /**
         * Stop polling for status updates
         */
        function stopStatusPolling() {
            if (syncInProgress) {
                addLogEntry('info', 'Stopped monitoring sync status.');
            }
            
            syncInProgress = false;
            syncStartTime = null;
            
            if (pollingTimerId) {
                clearTimeout(pollingTimerId);
                pollingTimerId = null;
            }
        }
        
        /**
         * Check sync status via AJAX
         * 
         * @param {boolean} showNotifications Whether to show notifications for status updates
         */
        function checkSyncStatus(showNotifications = false) {
            fetchSyncStatus(false, function(data) {
                if (!data) {
                    // If error fetching status but we believe sync is still in progress,
                    // continue polling with a longer interval
                    if (syncInProgress) {
                        pollingTimerId = setTimeout(checkSyncStatus, POLLING_INTERVAL * 2);
                    } else {
                        stopStatusPolling();
                    }
                    return;
                }
                
                // Update sync data
                updateSyncDataFromResponse(data);
                updateSyncInfo();
                
                // Process log entries if present
                if (data.logEntries && data.logEntries.length) {
                    processLogEntries(data.logEntries);
                }
                
                // Check if sync is actually in progress
                const activeSync = data.syncActive === 'true';
                
                // Update progress information if available
                if (data.syncProgress) {
                    updateSyncProgress(data.syncProgress);
                }
                
                // If initial sync is complete but we thought it was still running
                if (data.initialSyncDone === 'true' && scSyncData.initialSyncDone === 'false') {
                    scSyncData.initialSyncDone = 'true';
                    
                    // Only show notification if we weren't already aware
                    if (showNotifications) {
                        addLogEntry('success', 'Initial sync completed successfully');
                        showNotification('success', 'Initial sync completed successfully');
                    }
                    
                    // End the sync UI if no active sync
                    if (!activeSync) {
                        endSync();
                    }
                }
                
                // If active sync detected but UI doesn't reflect it
                if (activeSync && !syncInProgress) {
                    syncInProgress = true;
                    syncStartTime = new Date();
                    startSync(data.syncOperationType || 'Sync in progress');
                }
                
                // If no active sync but UI shows as in progress
                if (!activeSync && syncInProgress) {
                    syncInProgress = false;
                    endSync();
                    
                    // Only refresh the page when explicitly requested
                    if (showNotifications) {
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    }
                }
                
                // Continue polling if sync is active or initial sync not done
                if (activeSync || data.initialSyncDone === 'false') {
                    pollingTimerId = setTimeout(checkSyncStatus, POLLING_INTERVAL);
                } else {
                    stopStatusPolling();
                }
            });
        }
        
        /**
         * Update progress information with data from backend
         * 
         * @param {Object} progressData Progress data from backend
         */
        function updateSyncProgress(progressData) {
            // Calculate percent complete if items_processed and items_total are available
            if (progressData.items_processed !== undefined && progressData.items_total !== undefined) {
                const itemsProcessed = parseInt(progressData.items_processed, 10);
                const itemsTotal = parseInt(progressData.items_total, 10);
                
                if (itemsTotal > 0) {
                    const percent = Math.min(Math.round((itemsProcessed / itemsTotal) * 100), 100);
                    updateProgress(percent);
                    
                    // Update progress message
                    const elapsedTime = getElapsedTimeString();
                    
                    $('.sc-sync-progress-message').text(
                        progressData.message || 'Synchronizing...'
                    );
                    
                    // Add or update detailed progress info
                    if ($('.sc-sync-progress-detail').length === 0) {
                        $('.sc-sync-progress-message').after(
                            $('<div>', {'class': 'sc-sync-progress-detail'})
                        );
                    }
                    
                    $('.sc-sync-progress-detail').text(
                        `Processed ${itemsProcessed} of ${itemsTotal} items (${percent}%)${elapsedTime ? ' - ' + elapsedTime : ''}`
                    );
                    
                    // Add log entry for significant progress points
                    if (itemsProcessed % 10 === 0 || 
                        percent % 10 === 0 || 
                        itemsProcessed === 1 || 
                        itemsProcessed === itemsTotal) {
                        addLogEntry('info', `Processed ${itemsProcessed} of ${itemsTotal} items (${percent}%)`);
                    }
                }
            } else if (progressData.message) {
                // Just update the message if percent can't be calculated
                $('.sc-sync-progress-message').text(progressData.message);
            }
        }
        
        /**
         * Get elapsed time string since sync started
         * 
         * @return {string} Formatted elapsed time
         */
        function getElapsedTimeString() {
            if (!syncStartTime) return '';
            
            const elapsed = Math.round((new Date() - syncStartTime) / 1000); // seconds
            
            if (elapsed < 60) {
                return `${elapsed} seconds`;
            } else if (elapsed < 3600) {
                const minutes = Math.floor(elapsed / 60);
                const seconds = elapsed % 60;
                return `${minutes} min ${seconds} sec`;
            } else {
                const hours = Math.floor(elapsed / 3600);
                const minutes = Math.floor((elapsed % 3600) / 60);
                return `${hours} hr ${minutes} min`;
            }
        }
        
        /**
         * Process log entries from backend
         * 
         * @param {Array} entries Log entries from backend
         */
        function processLogEntries(entries) {
            // Add each new entry to our log
            entries.forEach(entry => {
                // Skip if we already have this log entry (compare message and time)
                const entryKey = entry.time + '-' + entry.message;
                if (logMessages.includes(entryKey)) {
                    return;
                }
                
                addLogEntry(
                    entry.level || 'info', 
                    entry.message, 
                    entry.time
                );
                
                // Add to our tracking array to avoid duplicates
                logMessages.push(entryKey);
                
                // Keep array size reasonable
                if (logMessages.length > 200) {
                    logMessages.shift();
                }
            });
        }
        
        /**
         * Add entry to log display
         * 
         * @param {string} level Log level: 'info', 'success', 'warning', 'error'
         * @param {string} message Log message
         * @param {string} time Optional timestamp, defaults to now
         */
        function addLogEntry(level, message, time) {
            const timestamp = time || new Date().toLocaleTimeString();
            
            const $logEntry = $('<div>', {
                'class': 'sc-log-entry sc-log-' + level,
                'html': `<span class="sc-log-time">[${timestamp}]</span> ${message}`
            });
            
            $('.sc-sync-log').prepend($logEntry);
            
            // If log has more than 100 entries, remove oldest
            if ($('.sc-log-entry').length > 100) {
                $('.sc-log-entry').last().remove();
            }
            
            // Auto-expand log when new entries appear during active sync
            if (syncInProgress && !$('.sc-sync-log').hasClass('expanded')) {
                $('.sc-log-control').click();
            }
        }
        

/**
         * Update sync info with current data
         */
        function updateSyncInfo() {
            $('.sc-next-stock-sync').text(scSyncData.nextStockSync);
            $('.sc-next-full-sync').text(scSyncData.nextFullSync);
            $('.sc-last-stock-sync').text(scSyncData.lastStockSync);
            $('.sc-last-full-sync').text(scSyncData.lastFullSync);
            $('.sc-last-sync-count').text(scSyncData.lastSyncCount);
            
            // Update sync status indicators
            updateLastSyncStatus();
            
            // Handle stock sync button state based on initial sync
            if (scSyncData.initialSyncDone === 'true') {
                $('#sc-sync-stock').removeClass('disabled').prop('disabled', false);
            } else {
                $('#sc-sync-stock').addClass('disabled').prop('disabled', true);
            }
        }
        
        /**
         * Update last sync status indicators
         */
        function updateLastSyncStatus() {
            // Calculate how long ago the last syncs were
            const now = new Date();
            
            // Stock sync status
            try {
                if (scSyncData.lastStockSync === 'Never') {
                    $('.sc-stock-sync-status').removeClass('sc-status-ok sc-status-warning').addClass('sc-status-error')
                        .attr('title', 'Stock sync has never run');
                } else {
                    const lastStockDate = new Date(scSyncData.lastStockSync);
                    const stockSyncAgo = Math.round((now - lastStockDate) / 1000 / 60); // minutes
                    
                    if (stockSyncAgo < 20) {
                        $('.sc-stock-sync-status').removeClass('sc-status-warning sc-status-error').addClass('sc-status-ok')
                            .attr('title', 'Stock sync is up to date');
                    } else if (stockSyncAgo < 60) {
                        $('.sc-stock-sync-status').removeClass('sc-status-ok sc-status-error').addClass('sc-status-warning')
                            .attr('title', 'Stock sync is slightly delayed');
                    } else {
                        $('.sc-stock-sync-status').removeClass('sc-status-ok sc-status-warning').addClass('sc-status-error')
                            .attr('title', 'Stock sync is overdue');
                    }
                }
            } catch (e) {
                $('.sc-stock-sync-status').removeClass('sc-status-ok sc-status-warning').addClass('sc-status-error')
                    .attr('title', 'Stock sync has never run or time format is invalid');
            }
            
            // Full sync status
            try {
                if (scSyncData.lastFullSync === 'Never') {
                    $('.sc-full-sync-status').removeClass('sc-status-ok sc-status-warning').addClass('sc-status-error')
                        .attr('title', 'Full sync has never run');
                } else {
                    const lastFullDate = new Date(scSyncData.lastFullSync);
                    const fullSyncAgo = Math.round((now - lastFullDate) / 1000 / 60 / 60 / 24); // days
                    
                    if (fullSyncAgo < 2) {
                        $('.sc-full-sync-status').removeClass('sc-status-warning sc-status-error').addClass('sc-status-ok')
                            .attr('title', 'Full sync is up to date');
                    } else if (fullSyncAgo < 4) {
                        $('.sc-full-sync-status').removeClass('sc-status-ok sc-status-error').addClass('sc-status-warning')
                            .attr('title', 'Full sync is slightly delayed');
                    } else {
                        $('.sc-full-sync-status').removeClass('sc-status-ok sc-status-warning').addClass('sc-status-error')
                            .attr('title', 'Full sync is overdue');
                    }
                }
            } catch (e) {
                $('.sc-full-sync-status').removeClass('sc-status-ok sc-status-warning').addClass('sc-status-error')
                    .attr('title', 'Full sync has never run or time format is invalid');
            }
        }
        
        /**
         * Update sync data from AJAX response
         * 
         * @param {Object} data Response data
         */
        function updateSyncDataFromResponse(data) {
            if (data.lastStockSync) scSyncData.lastStockSync = data.lastStockSync;
            if (data.lastFullSync) scSyncData.lastFullSync = data.lastFullSync;
            if (data.lastSyncCount) scSyncData.lastSyncCount = data.lastSyncCount;
            if (data.nextStockSync) scSyncData.nextStockSync = data.nextStockSync;
            if (data.nextFullSync) scSyncData.nextFullSync = data.nextFullSync;
            if (data.initialSyncDone) scSyncData.initialSyncDone = data.initialSyncDone;
            if (data.stockSyncEnabled) scSyncData.stockSyncEnabled = data.stockSyncEnabled;
        }
        
        /**
         * Show a notification message
         * 
         * @param {string} type Notification type: 'success', 'error', 'warning', 'info'
         * @param {string} message Message to display
         */
        function showNotification(type, message) {
            // Remove any existing notifications of the same type
            $('.sc-notification-' + type).remove();
            
            // Create new notification
            const $notification = $('<div>', {
                'class': 'sc-notification sc-notification-' + type,
                'html': '<span class="dashicons dashicons-' + getIconForType(type) + '"></span>' + message
            });
            
            // Add close button
            const $closeBtn = $('<span>', {
                'class': 'sc-notification-close dashicons dashicons-no-alt',
                'click': function() {
                    $notification.fadeOut(300, function() {
                        $(this).remove();
                    });
                }
            });
            
            $notification.append($closeBtn);
            
            // Add to page and animate
            $('.sc-sync-notifications').append($notification);
            $notification.hide().fadeIn(300);
            
            // Auto-hide after 8 seconds for success/info messages
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    $notification.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 8000);
            }
        }
        
        /**
         * Get dashicon name for notification type
         * 
         * @param {string} type Notification type
         * @return {string} Dashicon name
         */
        function getIconForType(type) {
            switch (type) {
                case 'success': return 'yes-alt';
                case 'error': return 'dismiss';
                case 'warning': return 'warning';
                case 'info': default: return 'info';
            }
        }
        
        /**
         * UI updates when sync starts
         * 
         * @param {string} message Message to display during sync
         */
        function startSync(message) {
            // Add log entry
            addLogEntry('info', 'Starting sync operation: ' + message);
            
            // Disable sync buttons
            $('#sc-sync-full, #sc-sync-stock').addClass('disabled').prop('disabled', true);
            
            // Enable stop button
            $('#sc-stop-sync').removeClass('disabled').prop('disabled', false);
            
            // Show progress indicator
            $('.sc-sync-progress').removeClass('hidden');
            $('.sc-sync-progress-message').text(message || 'Synchronizing...');
            
            // Clear any existing progress detail
            $('.sc-sync-progress-detail').remove();
            
            // Start progress animation
            updateProgress(0);
            progressAnimation(0);
            
            // Set sync state
            syncInProgress = true;
            syncStartTime = new Date();
        }
        
        /**
         * UI updates when sync ends
         */
        function endSync() {
            // Add log entry if sync was in progress
            if (syncInProgress) {
                const elapsedTime = getElapsedTimeString();
                addLogEntry('info', 'Sync operation completed' + (elapsedTime ? ' after ' + elapsedTime : ''));
            }
            
            // Re-enable sync buttons
            $('#sc-sync-full').removeClass('disabled').prop('disabled', false);
            
            // Only enable stock sync if initial sync is done
            if (scSyncData.initialSyncDone === 'true') {
                $('#sc-sync-stock').removeClass('disabled').prop('disabled', false);
            } else {
                $('#sc-sync-stock').addClass('disabled').prop('disabled', true);
            }
            
            // Disable stop button
            $('#sc-stop-sync').addClass('disabled').prop('disabled', true);
            
            // Hide progress indicator
            $('.sc-sync-progress').addClass('hidden');
            $('.sc-sync-progress-bar-inner').css('width', '0%');
            
            // Reset sync state
            syncInProgress = false;
            syncStartTime = null;
            
            // Stop polling
            stopStatusPolling();
        }
        
        /**
         * Update progress bar percentage
         * 
         * @param {number} percent Progress percentage (0-100)
         */
        function updateProgress(percent) {
            $('.sc-sync-progress-bar-inner').css('width', percent + '%');
        }
        
        /**
         * Progress animation when we don't know actual progress
         * 
         * @param {number} current Current position in animation
         */
        function progressAnimation(current) {
            if ($('.sc-sync-progress').hasClass('hidden') || !syncInProgress) {
                return;
            }
            
            // Move progress bar back and forth for indeterminate progress
            let next;
            if (current < 30) {
                next = current + 0.5;
            } else if (current >= 95) {
                next = 30;
            } else {
                next = current + 0.2;
            }
            
            updateProgress(next);
            
            setTimeout(function() {
                progressAnimation(next);
            }, 50);
        }
    });
})(jQuery);