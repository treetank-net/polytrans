/**
 * Execute Workflow Wizard
 * 
 * Handles the manual workflow execution wizard interface
 */

(function ($) {
    'use strict';

    const ExecuteWorkflow = {
        // State
        selectedWorkflowId: null,
        selectedOriginalPostId: null,
        selectedTranslatedPostId: null,
        executionId: null,
        pollInterval: null,
        pollCount: 0,
        maxPolls: 120, // 2 minutes at 1 second intervals

        // Data
        workflows: {},
        currentWorkflow: null,
        locked: false,

        /**
         * Initialize
         */
        init: function () {
            // Get initial data from window object
            if (window.polytransExecuteWorkflowData) {
                this.workflows = this.indexWorkflows(window.polytransExecuteWorkflowData.workflows);
                this.locked = window.polytransExecuteWorkflowData.locked || false;

                // Handle pre-selections
                if (window.polytransExecuteWorkflowData.selectedWorkflow) {
                    this.selectedWorkflowId = window.polytransExecuteWorkflowData.selectedWorkflow.id;
                    this.currentWorkflow = window.polytransExecuteWorkflowData.selectedWorkflow;
                }

                if (window.polytransExecuteWorkflowData.selectedPost) {
                    this.selectedOriginalPostId = window.polytransExecuteWorkflowData.selectedPost.ID;
                }
            }

            // Bind events
            this.bindEvents();

            // Initialize wizard state
            this.initWizard();
        },

        /**
         * Index workflows by ID for quick lookup
         */
        indexWorkflows: function (workflowsArray) {
            const indexed = {};
            workflowsArray.forEach(workflow => {
                indexed[workflow.id] = workflow;
            });
            return indexed;
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // Workflow selection
            $('#workflow-select').on('change', this.handleWorkflowChange.bind(this));

            // Post search
            $('#post-search').on('keyup', this.debounce(this.handlePostSearch.bind(this), 300));

            // Post ID verification
            $('#verify-post-id').on('click', this.handleVerifyPostIds.bind(this));

            // Enter key on post ID input
            $('#post-id-input').on('keypress', (e) => {
                if (e.which === 13) {
                    e.preventDefault();
                    this.handleVerifyPostIds();
                }
            });

            // Execute button
            $('#execute-workflow-btn').on('click', this.handleExecute.bind(this));

            // Execute another
            $('#execute-another-btn').on('click', this.resetWizard.bind(this));
        },

        /**
         * Initialize wizard state
         */
        initWizard: function () {
            if (this.selectedWorkflowId) {
                $('#workflow-select').val(this.selectedWorkflowId).trigger('change');
            }

            if (this.selectedOriginalPostId && this.currentWorkflow) {
                // Load post data if pre-selected
                this.loadPostData(this.selectedOriginalPostId);
            }
        },

        /**
         * Handle workflow selection change
         */
        handleWorkflowChange: function (e) {
            const workflowId = $(e.target).val();

            if (!workflowId) {
                $('#workflow-details').hide();
                $('#step-post').hide();
                $('#step-execute').hide();
                this.selectedWorkflowId = null;
                this.currentWorkflow = null;
                return;
            }

            this.selectedWorkflowId = workflowId;
            this.currentWorkflow = this.workflows[workflowId];

            // Display workflow details
            this.displayWorkflowDetails();

            // Show post selection step
            $('#step-post').slideDown();

            // If there's a pre-selected post ID, load it now that we have a workflow
            if (this.selectedOriginalPostId && !this.selectedTranslatedPostId) {
                this.loadPostData(this.selectedOriginalPostId);
            }
            // Clear post selection if language doesn't match
            else if (this.selectedTranslatedPostId) {
                // Check if current selection is still valid
                this.validateCurrentSelection();
            }
        },

        /**
         * Display workflow details
         */
        displayWorkflowDetails: function () {
            if (!this.currentWorkflow) return;

            const languageName = this.currentWorkflow.language
                ? (polytransExecuteWorkflow.languages[this.currentWorkflow.language] || this.currentWorkflow.language.toUpperCase())
                : (polytransExecuteWorkflow.strings.allLanguages || 'All languages');
            const stepsCount = this.currentWorkflow.steps ? this.currentWorkflow.steps.length : 0;

            $('#workflow-language').text(languageName);
            $('#workflow-steps-count').text(stepsCount);

            // Display steps list
            let stepsList = '<ul style="margin: 10px 0; padding-left: 20px;">';
            if (this.currentWorkflow.steps && this.currentWorkflow.steps.length > 0) {
                this.currentWorkflow.steps.forEach((step, index) => {
                    stepsList += `<li>${this.escapeHtml(step.name || 'Step ' + (index + 1))}</li>`;
                });
            }
            stepsList += '</ul>';

            $('#workflow-steps-list').html(stepsList);
            $('#workflow-details').slideDown();
        },

        /**
         * Handle post search
         */
        handlePostSearch: function (e) {
            const query = $(e.target).val().trim();

            if (query.length < 2) {
                $('#post-search-results').hide();
                return;
            }

            if (!this.currentWorkflow) {
                this.showNotice('error', polytransExecuteWorkflow.strings.error, 'Please select a workflow first.');
                return;
            }

            // Show loading
            $('#post-search').addClass('loading');

            // AJAX search with language filter
            $.ajax({
                url: polytransExecuteWorkflow.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_search_posts',
                    nonce: polytransExecuteWorkflow.nonce,
                    search: query,
                    target_language: this.currentWorkflow.language,
                    post_type: 'any'
                },
                success: (response) => {
                    $('#post-search').removeClass('loading');
                    if (response.success && response.data.posts && response.data.posts.length > 0) {
                        this.displayPostSearchResults(response.data.posts);
                    } else {
                        $('#post-search-results').hide();
                        this.showNotice('info', polytransExecuteWorkflow.strings.noPosts, 'No matching posts found.');
                    }
                },
                error: () => {
                    $('#post-search').removeClass('loading');
                    $('#post-search-results').hide();
                    this.showNotice('error', polytransExecuteWorkflow.strings.error, 'Search failed. Please try again.');
                }
            });
        },

        /**
         * Display post search results
         */
        displayPostSearchResults: function (posts) {
            if (!posts || posts.length === 0) {
                $('#post-search-results').hide();
                this.showNotice('info', 'No Results', 'No posts found in ' + (this.currentWorkflow.language || 'the selected language').toUpperCase());
                return;
            }

            let html = '<div style="border: 1px solid #ddd; border-radius: 4px; max-height: 300px; overflow-y: auto; background: #fff; position: absolute; z-index: 3;">';

            posts.slice(0, 10).forEach((post) => {
                const postId = post.ID || post.id;
                const title = this.escapeHtml(post.post_title || post.title || 'Untitled');
                const type = post.post_type || 'post';
                const language = post.language || '';
                const languageName = language ? (polytransExecuteWorkflow.languages[language] || language.toUpperCase()) : '';

                html += `
                    <div class="post-search-result" data-post-id="${postId}" 
                         style="padding: 12px 15px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: background 0.2s;">
                        <div style="font-weight: 500; color: #23282d;">${title}</div>
                        <div style="font-size: 12px; color: #666; margin-top: 4px;">
                            <span>ID: ${postId}</span>
                            <span style="margin: 0 8px;">•</span>
                            <span>Type: ${type}</span>
                            ${languageName ? '<span style="margin: 0 8px;">•</span><span style="color: #2271b1; font-weight: 500;">' + languageName + '</span>' : ''}
                        </div>
                    </div>
                `;
            });

            html += '</div>';

            $('#post-search-results').html(html).slideDown();

            // Add click handlers
            $('.post-search-result').hover(
                function () { $(this).css('background', '#f5f5f5'); },
                function () { $(this).css('background', '#fff'); }
            ).click((e) => {
                const postId = parseInt($(e.currentTarget).attr('data-post-id'));
                $('#post-search-results').hide();
                $('#post-search').val('');
                this.loadPostData(postId);
            });
        },

        /**
         * Handle verify post ID
         */
        handleVerifyPostIds: function () {
            const postId = parseInt($('#post-id-input').val()) || 0;

            if (!postId) {
                this.showNotice('error', polytransExecuteWorkflow.strings.error, 'Please enter a post ID.');
                return;
            }

            if (!this.currentWorkflow) {
                this.showNotice('error', polytransExecuteWorkflow.strings.error, 'Please select a workflow first.');
                return;
            }

            // Show loading
            $('#verify-post-id').prop('disabled', true).text(polytransExecuteWorkflow.strings.verifying);

            this.loadPostData(postId);
        },

        /**
         * Load post data and validate
         */
        loadPostData: function (postId) {
            console.log('Loading post data for ID:', postId, 'Language:', this.currentWorkflow.language);

            $.ajax({
                url: polytransExecuteWorkflow.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_get_post_data',
                    nonce: polytransExecuteWorkflow.nonce,
                    post_id: postId,
                    target_language: this.currentWorkflow.language
                },
                success: (response) => {
                    console.log('Post data response:', response);
                    $('#verify-post-id').prop('disabled', false).text('Load Post');

                    if (response.success && response.data) {
                        this.handlePostDataLoaded(response.data);
                    } else {
                        const errorMsg = response.data && response.data.message ? response.data.message : 'Failed to load post data.';
                        this.showNotice('error', polytransExecuteWorkflow.strings.error, errorMsg);
                        $('#selected-post-display').hide();
                        $('#step-execute').hide();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('AJAX error:', status, error, xhr.responseText);
                    $('#verify-post-id').prop('disabled', false).text('Load Post');
                    this.showNotice('error', polytransExecuteWorkflow.strings.error, 'Failed to load post data: ' + error);
                }
            });
        },

        /**
         * Handle post data loaded
         */
        handlePostDataLoaded: function (data) {
            if (!data.original_post || !data.translated_post) {
                this.showNotice('error', polytransExecuteWorkflow.strings.noTranslation,
                    `This post does not have a translation in ${this.currentWorkflow.language}.`);
                $('#selected-post-display').hide();
                $('#step-execute').hide();
                return;
            }

            // Store IDs (both point to the same post for manual workflows)
            this.selectedOriginalPostId = data.original_post.ID;
            this.selectedTranslatedPostId = data.translated_post.ID;

            const post = data.translated_post;
            const languageName = polytransExecuteWorkflow.languages[post.language] || post.language.toUpperCase();

            // Display selected post
            const html = `
                <div style="display: flex; align-items: start; gap: 15px;">
                    <div style="flex: 1;">
                        <div style="font-size: 16px; font-weight: 600; color: #23282d; margin-bottom: 8px;">
                            ${this.escapeHtml(post.post_title)}
                        </div>
                        <div style="display: flex; gap: 15px; font-size: 13px; color: #666;">
                            <span><strong>ID:</strong> ${post.ID}</span>
                            <span><strong>Type:</strong> ${post.post_type}</span>
                            <span><strong>Language:</strong> ${languageName}</span>
                        </div>
                    </div>
                    <a href="${post.edit_url}" target="_blank" class="button button-secondary" style="flex-shrink: 0;">
                        View Post
                    </a>
                </div>
            `;

            $('#selected-posts-info').html(html);
            $('#selected-post-display').slideDown();

            // Show execution step
            this.displayExecutionReview(data);
            $('#step-execute').slideDown();
        },

        /**
         * Display execution review
         */
        displayExecutionReview: function (data) {
            const languageName = this.currentWorkflow.language
                ? (polytransExecuteWorkflow.languages[this.currentWorkflow.language] || this.currentWorkflow.language.toUpperCase())
                : (polytransExecuteWorkflow.strings.allLanguages || 'All languages');
            const stepsCount = this.currentWorkflow.steps ? this.currentWorkflow.steps.length : 0;

            const html = `
                <table class="form-table">
                    <tr>
                        <th scope="row">Post:</th>
                        <td>
                            <strong>"${this.escapeHtml(data.translated_post.post_title)}"</strong><br>
                            <small>ID: ${data.translated_post.ID} • ${data.translated_post.language.toUpperCase()} • ${data.translated_post.post_type}</small>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Workflow:</th>
                        <td>
                            <strong>${this.escapeHtml(this.currentWorkflow.name)}</strong> (${stepsCount} steps)<br>
                            <small>Target Language: ${languageName}</small>
                        </td>
                    </tr>
                </table>
            `;

            $('#execution-review').html(html);
        },

        /**
         * Handle execute workflow
         */
        handleExecute: function () {
            if (!this.selectedWorkflowId || !this.selectedOriginalPostId || !this.selectedTranslatedPostId) {
                this.showNotice('error', polytransExecuteWorkflow.strings.error, 'Please complete all steps before executing.');
                return;
            }

            // Disable execute button
            $('#execute-workflow-btn').prop('disabled', true).text(polytransExecuteWorkflow.strings.executing);

            // Show status step
            $('#step-status').slideDown();
            this.displayExecutionStatus('running', 'Workflow execution started...');

            // Start execution
            $.ajax({
                url: polytransExecuteWorkflow.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_execute_workflow_manual',
                    nonce: polytransExecuteWorkflow.nonce,
                    workflow_id: this.selectedWorkflowId,
                    original_post_id: this.selectedOriginalPostId,
                    translated_post_id: this.selectedTranslatedPostId,
                    target_language: this.currentWorkflow.language
                },
                success: (response) => {
                    if (response.success && response.data.execution_id) {
                        this.executionId = response.data.execution_id;
                        this.startPolling();
                    } else {
                        this.handleExecutionError(response.data);
                    }
                },
                error: () => {
                    this.handleExecutionError({ message: 'Failed to start workflow execution.' });
                }
            });
        },

        /**
         * Display execution status
         */
        displayExecutionStatus: function (status, message, elapsed) {
            let html = '';

            if (status === 'running') {
                html = `
                    <div style="text-align: center; padding: 20px;">
                        <div class="spinner is-active" style="float: none; margin: 0 auto 20px;"></div>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <strong>Status: Running</strong>
                        </p>
                        <p style="color: #666;">
                            ${this.escapeHtml(message)}
                        </p>
                        ${elapsed ? `<p><small>Elapsed time: ${elapsed}s</small></p>` : ''}
                        <p style="margin-top: 20px; color: #666; font-size: 14px;">
                            This may take a few minutes depending on workflow complexity and API response times.
                        </p>
                    </div>
                `;
            }

            $('#execution-status-content').html(html);
        },

        /**
         * Start polling for execution status
         */
        startPolling: function () {
            this.pollCount = 0;
            this.pollInterval = setInterval(() => {
                this.pollExecutionStatus();
            }, 1000); // Poll every 1 second
        },

        /**
         * Poll execution status
         */
        pollExecutionStatus: function () {
            this.pollCount++;

            if (this.pollCount > this.maxPolls) {
                this.stopPolling();
                this.handleExecutionTimeout();
                return;
            }

            $.ajax({
                url: polytransExecuteWorkflow.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_check_execution_status',
                    nonce: polytransExecuteWorkflow.nonce,
                    execution_id: this.executionId
                },
                success: (response) => {
                    if (response.success && response.data) {
                        const data = response.data;

                        if (data.status === 'completed') {
                            this.stopPolling();
                            this.handleExecutionComplete(data);
                        } else if (data.status === 'failed') {
                            this.stopPolling();
                            this.handleExecutionFailed(data);
                        } else {
                            // Still running, update elapsed time
                            const elapsed = data.elapsed || this.pollCount;
                            this.displayExecutionStatus('running', 'Please wait while the workflow executes...', elapsed);
                        }
                    }
                },
                error: () => {
                    // Continue polling on error
                }
            });
        },

        /**
         * Stop polling
         */
        stopPolling: function () {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        /**
         * Handle execution complete
         */
        handleExecutionComplete: function (data) {
            $('#step-status').hide();
            $('#step-execute').hide();

            const result = data.result || {};
            const elapsed = data.elapsed || 0;

            let html = `
                <div style="padding: 20px; text-align: center;">
                    <div style="font-size: 48px; color: #46b450; margin-bottom: 20px;">✓</div>
                    <h3 style="color: #46b450; margin-bottom: 10px;">Workflow Executed Successfully!</h3>
                    <p style="color: #666; margin-bottom: 20px;">
                        Completed in ${elapsed} seconds
                    </p>
            `;

            if (result.steps_executed) {
                html += `<p><strong>Steps Executed:</strong> ${result.steps_executed}</p>`;
            }

            if (result.step_results && result.step_results.length > 0) {
                html += '<div style="margin-top: 30px; text-align: left;">';
                html += '<h4>Step Results:</h4>';
                html += '<div style="border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">';

                result.step_results.forEach((step, index) => {
                    const statusIcon = step.success ? '✓' : '✗';
                    const statusColor = step.success ? '#46b450' : '#dc3232';

                    html += `
                        <div style="padding: 15px; border-bottom: 1px solid #ddd; ${index % 2 === 0 ? 'background: #f9f9f9;' : ''}">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="color: ${statusColor}; font-size: 20px;">${statusIcon}</span>
                                <strong>Step ${index + 1}: ${this.escapeHtml(step.step_name || 'Unnamed')}</strong>
                            </div>
                            ${step.execution_time ? `<div style="margin-top: 5px;"><small>Time: ${step.execution_time}s</small></div>` : ''}
                        </div>
                    `;
                });

                html += '</div></div>';
            }

            html += `
                    <div style="margin-top: 30px;">
                        <a href="${this.getPostEditUrl(this.selectedTranslatedPostId)}" class="button" target="_blank">
                            View Translated Post
                        </a>
                    </div>
                </div>
            `;

            $('#execution-results-content').html(html);
            $('#results-title').html('✓ ' + polytransExecuteWorkflow.strings.success);
            $('#step-results').slideDown();

            // Scroll to results
            $('html, body').animate({
                scrollTop: $('#step-results').offset().top - 100
            }, 500);
        },

        /**
         * Handle execution failed
         */
        handleExecutionFailed: function (data) {
            $('#step-status').hide();

            const result = data.result || {};
            const errorMessage = result.error || 'Unknown error occurred';

            const html = `
                <div style="padding: 20px; text-align: center;">
                    <div style="font-size: 48px; color: #dc3232; margin-bottom: 20px;">✗</div>
                    <h3 style="color: #dc3232; margin-bottom: 10px;">Workflow Execution Failed</h3>
                    <p style="color: #666; margin-bottom: 20px;">
                        ${this.escapeHtml(errorMessage)}
                    </p>
                </div>
            `;

            $('#execution-results-content').html(html);
            $('#results-title').html('✗ ' + polytransExecuteWorkflow.strings.failed);
            $('#step-results').slideDown();

            // Reset execute button
            $('#execute-workflow-btn').prop('disabled', false).text(polytransExecuteWorkflow.strings.execute);
        },

        /**
         * Handle execution error
         */
        handleExecutionError: function (error) {
            const message = error.message || 'Failed to execute workflow.';

            $('#step-status').hide();
            $('#execute-workflow-btn').prop('disabled', false).text(polytransExecuteWorkflow.strings.execute);

            this.showNotice('error', polytransExecuteWorkflow.strings.error, message);
        },

        /**
         * Handle execution timeout
         */
        handleExecutionTimeout: function () {
            $('#step-status').hide();
            $('#execute-workflow-btn').prop('disabled', false).text(polytransExecuteWorkflow.strings.execute);

            this.showNotice('error', polytransExecuteWorkflow.strings.timeout,
                'The execution is taking longer than expected. Please check the logs for details.');
        },

        /**
         * Validate current selection
         */
        validateCurrentSelection: function () {
            // This would check if the currently selected post pair is still valid
            // For the current workflow's target language
            // Implementation depends on how you store the current selection
        },

        /**
         * Reset wizard
         */
        resetWizard: function () {
            // Reset state
            this.selectedWorkflowId = null;
            this.selectedOriginalPostId = null;
            this.selectedTranslatedPostId = null;
            this.executionId = null;
            this.currentWorkflow = null;
            this.pollCount = 0;

            // Reset UI
            $('#workflow-select').val('');
            $('#post-search').val('');
            $('#original-post-id').val('');
            $('#translated-post-id').val('');
            $('#workflow-details').hide();
            $('#selected-post-display').hide();
            $('#step-post').hide();
            $('#step-execute').hide();
            $('#step-status').hide();
            $('#step-results').hide();
            $('#execute-workflow-btn').prop('disabled', false).text(polytransExecuteWorkflow.strings.execute);

            // Scroll to top
            $('html, body').animate({
                scrollTop: $('.execute-workflow-page').offset().top - 100
            }, 500);
        },

        /**
         * Show notice
         */
        showNotice: function (type, title, message) {
            const noticeClass = 'notice-' + type;
            const $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p><strong>' +
                this.escapeHtml(title) + '</strong> ' + this.escapeHtml(message) + '</p></div>');

            $('.execute-workflow-page > h1').after($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                $notice.fadeOut(() => $notice.remove());
            }, 5000);
        },

        /**
         * Get post edit URL
         */
        getPostEditUrl: function (postId) {
            return `post.php?post=${postId}&action=edit`;
        },

        /**
         * Escape HTML
         */
        escapeHtml: function (text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        },

        /**
         * Debounce function
         */
        debounce: function (func, wait) {
            let timeout;
            return function (...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        if ($('.execute-workflow-page').length) {
            ExecuteWorkflow.init();
        }
    });

})(jQuery);
