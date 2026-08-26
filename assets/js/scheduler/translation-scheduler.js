jQuery(function ($) {
    'use strict';

    // Cache selectors
    var $mergedList = $('#polytrans-merged-list');
    var $controls = $('.polytrans-controls');
    var $scope = $('#polytrans-scope');
    var $translateBtn = $('#polytrans-translate-btn');
    var $targetLangsRow = $('#polytrans-target-langs-row');
    var $targetLangs = $('#polytrans-target-langs');
    var $needsReview = $('#polytrans-needs-review');
    var $translateStatus = $('#polytrans-translate-status');

    var postId = PolyTransScheduler.postId;

    // Built-in whitelist of field patterns that should NOT trigger "unsaved changes"
    // These are fields from known plugins that modify values in the background
    var builtInFieldWhitelist = [
        // AIOSEO (All in One SEO)
        'aioseo',
        // Yoast SEO
        'yoast',
        '_yoast',
        'wpseo',
        // Rank Math SEO
        'rank_math',
        'rank-math',
        // SEOPress
        'seopress',
        // The SEO Framework
        'tsf',
        '_tsf',
        // WordPress internal (additional patterns)
        'meta-box',
        'metabox',
        // ACF (sometimes changes in background)
        'acf-',
        // Gutenberg/Block Editor internal
        'editor-',
        'block-editor',
        // WooCommerce
        '_wc_',
        'woocommerce',
        // Elementor
        'elementor',
        // Classic Editor
        'classic-editor'
    ];

    // Combine built-in whitelist with user-configured whitelist
    var customWhitelist = PolyTransScheduler.fieldWhitelist || [];
    var fieldWhitelist = builtInFieldWhitelist.concat(customWhitelist);

    /**
     * Check if a field name/id matches any whitelist pattern
     * @param {string} fieldName - The field name or ID to check
     * @returns {boolean} - True if field should be ignored (whitelisted)
     */
    function isFieldWhitelisted(fieldName) {
        if (!fieldName) return false;
        var lowerName = fieldName.toLowerCase();
        for (var i = 0; i < fieldWhitelist.length; i++) {
            var pattern = fieldWhitelist[i].toLowerCase();
            if (lowerName.indexOf(pattern) !== -1) {
                return true;
            }
        }
        return false;
    }

    // Flag to track if translate button is locked by translation process
    var translateButtonLocked = false;


    // Helper: show/hide translation status UI
    function renderStatusUI(status) {
        var hasAny = false;
        $mergedList.find('li').each(function () {
            var lang = this.id.replace('polytrans-merged-', '');
            var info = status[lang];
            var $li = $(this);
            var $loader = $li.find('.polytrans-loader');
            var $check = $li.find('.polytrans-check');
            var $failed = $li.find('.polytrans-failed');
            var $editBtn = $li.find('.polytrans-edit-btn');
            var $retryBtn = $li.find('.polytrans-retry-translation');
            var $clearBtn = $li.find('.polytrans-clear-translation');
            if (info && (info.status === 'started' || info.status === 'translating' || info.status === 'processing')) {
                $li.show();
                $loader.removeClass('post-processing').show();
                $check.hide();
                $failed.hide();
                $editBtn.hide();
                $retryBtn.show();
                $clearBtn.show();
                hasAny = true;
            } else if (info && info.status === 'post_processing') {
                // Post created, workflows running - show purple spinner + edit button
                $li.show();
                $loader.addClass('post-processing').show();
                $check.hide();
                $failed.hide();
                // Show edit button if post_id available (post exists, can preview)
                if (info.post_id) {
                    $editBtn.show().attr('href', PolyTransScheduler.edit_url.replace('__ID__', info.post_id));
                } else {
                    $editBtn.hide();
                }
                $retryBtn.show();
                $clearBtn.show();
                hasAny = true;
            } else if (info && (info.status === 'finished' || info.status === 'completed') && info.post_id) {
                $li.show();
                $loader.hide();
                $check.show();
                $failed.hide();
                $editBtn.show().attr('href', PolyTransScheduler.edit_url.replace('__ID__', info.post_id));
                $retryBtn.show();
                $clearBtn.show();
                hasAny = true;
            } else if (info && info.status === 'failed') {
                $li.show();
                $loader.hide();
                $check.hide();
                $failed.show();
                $editBtn.hide();
                $retryBtn.show();
                $clearBtn.show();
                hasAny = true;
            } else {
                console.log('[TreeTank] Hiding status for:', lang, 'info status:', info ? info.status : 'no info');
                $li.hide();
                $editBtn.hide();
                $retryBtn.hide();
                $clearBtn.hide();
            }
        });
        if (hasAny) {
            $mergedList.show();
            $controls.hide();
        } else {
            $mergedList.hide();
            $controls.show();
            clearInterval(window.polytransPollInterval);
        }
    }

    // Helper: fetch status from server and update UI
    function fetchStatusAndRender() {
        $.post(PolyTransScheduler.ajax_url, {
            action: 'polytrans_get_translation_status',
            post_id: postId,
            _ajax_nonce: PolyTransScheduler.nonce
        }, function (resp) {
            if (resp && resp.success && resp.data && resp.data.status) {
                console.log('[TreeTank] Rendering status:', resp.data.status);
                renderStatusUI(resp.data.status);
            } else {
                console.warn('[TreeTank] Invalid status response:', resp);
            }
        }).fail(function (xhr, status, error) {
            console.error('[TreeTank] Status fetch failed:', error, xhr.responseText);
        });
    }

    // Helper: start polling for status updates
    function startPolling() {
        if (window.polytransPollInterval) clearInterval(window.polytransPollInterval);
        window.polytransPollInterval = setInterval(fetchStatusAndRender, 5000);
    }

    // Initial fetch and polling
    fetchStatusAndRender();
    startPolling();

    // Monitor form changes to disable translation when dirty
    function checkFormDirty() {
        var isDirty = false;
        var dirtyReason = '';

        // Check TinyMCE editor if available
        if (window.tinymce && window.tinymce.get('content')) {
            var editor = window.tinymce.get('content');
            if (!editor.isHidden() && editor.isDirty()) {
                isDirty = true;
                dirtyReason = 'TinyMCE editor has changes';
            }
        }

        // Check WordPress autosave if available
        if (!isDirty && window.wp && window.wp.autosave) {
            if (wp.autosave.server.postChanged()) {
                isDirty = true;
                dirtyReason = 'WordPress autosave detected changes';
            }
        }

        // Check if any form fields have changed
        if (!isDirty) {
            $('#post input:not([name*="polytrans"], #active_post_lock), #post textarea:not([name*="polytrans"]), #post select:not([name*="polytrans"])').each(function () {
                var $field = $(this);
                var originalValue = $field.data('original-value');
                var currentValue = $field.val();
                var fieldName = $field.attr('name') || '';
                var fieldId = $field.attr('id') || '';

                // Skip if this is a hidden WordPress field that changes automatically
                if (fieldName && (
                    fieldName.indexOf('_wp') === 0 ||
                    fieldName.indexOf('_ajax') === 0 ||
                    fieldName.indexOf('action') === 0 ||
                    fieldName.indexOf('post_ID') === 0
                ) || !fieldName) {
                    return true; // Continue to next field
                }

                // Skip if field is in whitelist (known plugins that change values in background)
                if (isFieldWhitelisted(fieldName) || isFieldWhitelisted(fieldId)) {
                    return true; // Continue to next field
                }

                if (originalValue === undefined) {
                    $field.data('original-value', currentValue);
                } else if (originalValue !== currentValue) {
                    isDirty = true;
                    dirtyReason = 'Form field changed: ' + (fieldName || fieldId || 'unknown');
                    return false; // Break out of loop
                }
            });
        }

        // Debug logging
        if (isDirty) {
            console.error('[TreeTank] Translation disabled - ' + dirtyReason);
        }

        // Disable/enable translation controls based on dirty state
        if (isDirty) {
            $translateBtn.prop('disabled', true);
            $scope.prop('disabled', true);
            $targetLangs.prop('disabled', true);
            $needsReview.prop('disabled', true);

            // Show dirty warning if not already shown
            if (!$('#polytrans-dirty-warning').length) {
                $controls.prepend(
                    '<div id="polytrans-dirty-warning" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 8px; margin-bottom: 10px; border-radius: 3px; color: #856404;">' +
                    '<strong>⚠ Unsaved Changes:</strong> Save the post before translating.' +
                    '</div>'
                );
            }
        } else {
            $scope.prop('disabled', false);
            $targetLangs.prop('disabled', false);
            $needsReview.prop('disabled', false);
            $('#polytrans-dirty-warning').remove();
            // Re-trigger scope change to set correct button state (but respect lock)
            if (!translateButtonLocked) {
                $scope.trigger('change');
            }
        }
    }

    // Defer initial check to allow page to fully load
    setTimeout(function () {
        // First, store initial values for all form fields
        $('#post input:not([name*="polytrans"]), #post textarea:not([name*="polytrans"]), #post select:not([name*="polytrans"])').each(function () {
            $(this).data('original-value', $(this).val());
        });

        checkFormDirty();
    }, 2000); // 2 second delay

    // Monitor form changes
    $(document).on('input change', '#post input:not([name*="_polytrans_"]), #post textarea:not([name*="_polytrans_"]), #post select:not([name*="_polytrans_"])', function () {
        setTimeout(checkFormDirty, 100); // Small delay to allow other handlers to run
    });

    // Monitor TinyMCE changes if available
    if (window.tinymce) {
        $(document).on('tinymce-editor-init', function (event, editor) {
            if (editor.id === 'content') {
                editor.on('change keyup', function () {
                    setTimeout(checkFormDirty, 100);
                });
            }
        });
    }

    // Monitor autosave changes
    if (window.wp && window.wp.autosave) {
        $(document).on('heartbeat-tick', function () {
            setTimeout(checkFormDirty, 100);
        });
    }

    // Listen for post save events to re-enable translation
    $(document).on('click', '#publish, #save-post', function () {
        // Wait a bit for the save to complete, then recheck
        setTimeout(function () {
            // Reset original values after save
            $('#post input:not([name*="_polytrans_"]), #post textarea:not([name*="_polytrans_"]), #post select:not([name*="_polytrans_"])').each(function () {
                $(this).data('original-value', $(this).val());
            });
            checkFormDirty();
        }, 1000);
    });

    // Handle scope change
    $scope.on('change', function () {
        var scopeVal = $scope.val();
        if (scopeVal === 'local') {
            $('#polytrans-scheduler-options').hide();
            $translateBtn.prop('disabled', true);
        } else {
            $('#polytrans-scheduler-options').show();
            // Only enable button if not locked by translation process
            if (!translateButtonLocked) {
                $translateBtn.prop('disabled', false);
            }
            if (scopeVal === 'regional') {
                $targetLangsRow.show();
            } else {
                $targetLangsRow.hide();
            }
        }
    }).trigger('change');

    // Handle translation button click
    $translateBtn.on('click', function () {
        var $btn = $translateBtn;

        // Prevent double-click: disable immediately
        if ($btn.prop('disabled') || translateButtonLocked) {
            return false;
        }

        var scopeVal = $scope.val();
        var targets = $targetLangs.val() || [];
        var needsReviewVal = $needsReview.is(':checked') ? 1 : 0;
        var data = {
            action: 'polytrans_schedule_translation',
            post_id: postId,
            scope: scopeVal,
            targets: targets,
            needs_review: needsReviewVal,
            _ajax_nonce: PolyTransScheduler.nonce
        };

        // Set lock flag and disable button immediately to prevent double-click
        translateButtonLocked = true;
        $btn.prop('disabled', true);

        // Track when button was disabled (minimum 5 seconds lock)
        var disabledAt = Date.now();
        var minLockDuration = 5000; // 5 seconds

        // Helper function to check if we should unlock the button
        var checkUnlockButton = function () {
            var elapsed = Date.now() - disabledAt;

            // Check if minimum lock duration has passed
            if (elapsed >= minLockDuration) {
                // Fetch current status to see if translation is in progress
                $.post(PolyTransScheduler.ajax_url, {
                    action: 'polytrans_get_translation_status',
                    post_id: postId,
                    _ajax_nonce: PolyTransScheduler.nonce
                }, function (statusResp) {
                    if (statusResp && statusResp.success && statusResp.data && statusResp.data.status) {
                        // Check if any translation is in progress
                        var hasActiveTranslation = false;
                        for (var lang in statusResp.data.status) {
                            var status = statusResp.data.status[lang];
                            if (status && (status.status === 'started' || status.status === 'translating' || status.status === 'processing' || status.status === 'post_processing')) {
                                hasActiveTranslation = true;
                                break;
                            }
                        }

                        // Only unlock if no active translation and minimum time has passed
                        if (!hasActiveTranslation && elapsed >= minLockDuration) {
                            translateButtonLocked = false;
                            // Only enable if scope is not 'local' and form is not dirty
                            var scopeVal = $scope.val();
                            if (scopeVal !== 'local') {
                                $btn.prop('disabled', false);
                            }
                        } else if (hasActiveTranslation) {
                            // Translation is active, keep checking every 2 seconds
                            setTimeout(checkUnlockButton, 2000);
                        }
                    } else {
                        // Status check failed, unlock after minimum time
                        if (elapsed >= minLockDuration) {
                            translateButtonLocked = false;
                            var scopeVal = $scope.val();
                            if (scopeVal !== 'local') {
                                $btn.prop('disabled', false);
                            }
                        }
                    }
                }).fail(function () {
                    // Status check failed, unlock after minimum time
                    if (elapsed >= minLockDuration) {
                        translateButtonLocked = false;
                        var scopeVal = $scope.val();
                        if (scopeVal !== 'local') {
                            $btn.prop('disabled', false);
                        }
                    }
                });
            } else {
                // Minimum time not passed yet, check again soon
                setTimeout(checkUnlockButton, 100);
            }
        };

        // Make AJAX request
        $.post(PolyTransScheduler.ajax_url, data, function (resp) {
            if (resp && resp.success && resp.data) {
                fetchStatusAndRender();
                startPolling();
                // Start checking if we can unlock (after minimum time)
                setTimeout(checkUnlockButton, minLockDuration);
            } else {
                $translateStatus.text(resp.data && resp.data.message ? resp.data.message : resp.data);
                startPolling();
                console.error('Translation scheduling failed:', resp);
                // Start checking if we can unlock (after minimum time)
                setTimeout(checkUnlockButton, minLockDuration);
            }
        }).fail(function (xhr) {
            $translateStatus.text('Error: ' + (xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : 'Unknown error'));
            // Start checking if we can unlock (after minimum time)
            setTimeout(checkUnlockButton, minLockDuration);
        });
    });

    // Handle clear button click
    $mergedList.on('click', '.polytrans-clear-translation', function () {
        var $btn = $(this);
        var lang = $btn.data('lang');
        if (!lang) return;
        if (!confirm(PolyTransScheduler.i18n.confirm_clear)) return;
        $.post(PolyTransScheduler.ajax_url, {
            action: 'polytrans_clear_translation_status',
            post_id: postId,
            target_lang: lang,
            _ajax_nonce: PolyTransScheduler.nonce
        }, function (resp) {
            if (resp && resp.success) {
                // Always re-fetch status after clear
                fetchStatusAndRender();
            } else {
                console.error(resp);
                alert('Failed to clear translation: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
            }
        });
    });

    // Handle retry button click
    $mergedList.on('click', '.polytrans-retry-translation', function () {
        var $btn = $(this);
        var lang = $btn.data('lang');
        if (!lang) return;
        if (!confirm(PolyTransScheduler.i18n.confirm_retry)) return;

        $btn.prop('disabled', true);

        $.post(PolyTransScheduler.ajax_url, {
            action: 'polytrans_retry_translation',
            post_id: postId,
            target_lang: lang,
            _ajax_nonce: PolyTransScheduler.nonce
        }, function (resp) {
            if (resp && resp.success) {
                console.log('[TreeTank] Retry translation started for ' + lang);
                // Re-fetch status to show new translation state
                fetchStatusAndRender();
                startPolling();
            } else {
                console.error(resp);
                alert('Failed to retry translation: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                $btn.prop('disabled', false);
            }
        }).fail(function (xhr) {
            alert('Error retrying translation: ' + (xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : 'Unknown error'));
            $btn.prop('disabled', false);
        });
    });

    // Handle edit button click (delegated)
    $(document).on('click', '.polytrans-edit-btn', function (e) {
        e.preventDefault();
        var url = $(this).attr('href');
        if (url && url !== '#') {
            window.open(url, '_blank');
        }
    });

    // Add More Languages functionality
    var $addMoreBtn = $('#polytrans-add-more-btn');
    var $addMoreSection = $('#polytrans-add-more-section');
    var $addMoreLangs = $('#polytrans-add-more-langs');
    var $addMoreNeedsReview = $('#polytrans-add-more-needs-review');
    var $addMoreSubmit = $('#polytrans-add-more-submit');
    var $addMoreCancel = $('#polytrans-add-more-cancel');

    // Helper: Update add more languages dropdown to exclude already scheduled
    function updateAddMoreLanguages(status) {
        var scheduledLangs = Object.keys(status || {});
        $addMoreLangs.find('option').each(function () {
            var lang = $(this).val();
            if (scheduledLangs.indexOf(lang) !== -1) {
                $(this).prop('disabled', true).hide();
            } else {
                $(this).prop('disabled', false).show();
            }
        });

        // Show "Add More" button only if:
        // - There are translations
        // - There are available languages
        // - The add more section is NOT currently visible
        var hasTranslations = scheduledLangs.length > 0;
        var hasAvailableLangs = $addMoreLangs.find('option:not(:disabled)').length > 0;
        var sectionVisible = $addMoreSection.is(':visible');

        if (hasTranslations && hasAvailableLangs && !sectionVisible) {
            $addMoreBtn.removeClass('hidden');
        } else if (!hasTranslations || !hasAvailableLangs) {
            // Hide both button and section if conditions aren't met
            $addMoreBtn.addClass('hidden');
            $addMoreSection.hide();
        }
    }

    // Show add more section
    $addMoreBtn.on('click', function () {
        $addMoreSection.show();
        $addMoreBtn.addClass('hidden');
        $addMoreLangs.val([]);
    });

    // Cancel add more
    $addMoreCancel.on('click', function () {
        $addMoreSection.hide();
        $addMoreBtn.removeClass('hidden');
        $addMoreLangs.val([]);
    });

    // Submit add more languages
    $addMoreSubmit.on('click', function () {
        var selectedLangs = $addMoreLangs.val() || [];

        if (selectedLangs.length === 0) {
            alert(PolyTransScheduler.i18n.select_languages_add_more);
            return;
        }

        var needsReview = $addMoreNeedsReview.is(':checked') ? 1 : 0;
        var $btn = $(this);

        $btn.prop('disabled', true).text(PolyTransScheduler.i18n.translating);

        $.post(PolyTransScheduler.ajax_url, {
            action: 'polytrans_schedule_translation',
            post_id: postId,
            scope: 'regional',
            targets: selectedLangs,
            needs_review: needsReview,
            _ajax_nonce: PolyTransScheduler.nonce
        }, function (resp) {
            if (resp && resp.success) {
                $addMoreSection.hide();
                $addMoreBtn.removeClass('hidden');
                $addMoreLangs.val([]);
                fetchStatusAndRender();
                startPolling();
            } else {
                alert(resp.data && resp.data.message ? resp.data.message : PolyTransScheduler.i18n.error_occurred);
            }
            $btn.prop('disabled', false).text(PolyTransScheduler.i18n.translation_started);
        }).fail(function (xhr) {
            alert(PolyTransScheduler.i18n.connection_error);
            $btn.prop('disabled', false).text(PolyTransScheduler.i18n.translation_started);
        });
    });

    // Override renderStatusUI to also update add more languages
    var originalRenderStatusUI = renderStatusUI;
    renderStatusUI = function (status) {
        originalRenderStatusUI(status);
        updateAddMoreLanguages(status);
    };

    // Handle detach translation button
    $(document).on('click', '#polytrans-detach-translation', function () {
        var $btn = $(this);
        var detachPostId = $btn.data('post-id');
        if (!detachPostId) return;
        if (!confirm('Are you sure you want to detach this post from its translation source?\n\nThis will allow you to use this post as a source for new translations to other languages.')) return;
        $btn.prop('disabled', true).text('Detaching...');
        $.post(PolyTransScheduler.ajax_url, {
            action: 'polytrans_detach_translation',
            post_id: detachPostId,
            _ajax_nonce: PolyTransScheduler.nonce
        }, function (resp) {
            if (resp && resp.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-editor-unlink" aria-hidden="true"></span> Detach from source');
                alert('Failed to detach: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-editor-unlink" aria-hidden="true"></span> Detach from source');
            alert('Connection error. Please try again.');
        });
    });
});
