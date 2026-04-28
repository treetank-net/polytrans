/**
 * AI Assistants Admin Interface
 * 
 * Handles the admin UI for managing AI assistants.
 * Part of Phase 1: AI Assistants Management System.
 */

(function($) {
    'use strict';

    const AssistantsAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initEditor();
            this.initAssistantTester();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Delete assistant
            $(document).on('click', '.assistant-delete', this.handleDelete.bind(this));

            // Save assistant form
            $('#assistant-editor-form').on('submit', this.handleSave.bind(this));


            // Provider change - load models dynamically
            $('#assistant-provider').on('change', this.handleProviderChange.bind(this));
            
            // Refresh models button
            $('#refresh-models').on('click', this.handleRefreshModels.bind(this));

            // Response format change - show/hide schema field
            $('#assistant-response-format').on('change', this.handleResponseFormatChange.bind(this));

            // Migrate workflows
            $('#migrate-workflows-btn').on('click', this.handleMigration.bind(this));

            // Test assistant
            $('#run-assistant-test-btn').on('click', this.handleAssistantTest.bind(this));
            $('#assistant-test-recent-posts').on('change', this.handleAssistantRecentPostChange.bind(this));
            $('#assistant-test-source-language').on('change', this.loadRecentPostsForAssistantTest.bind(this));
        },

        /**
         * Initialize assistant tester page.
         */
        initAssistantTester: function() {
            if (!$('#assistant-tester-container').length) {
                return;
            }
            this.loadRecentPostsForAssistantTest();
        },

        /**
         * Initialize editor (if on editor page)
         */
        initEditor: function() {
            if (!window.polytransAssistantData) {
                return;
            }

            // Initialize schema field visibility based on response format
            this.handleResponseFormatChange();

            // Initialize system prompt visibility based on current provider
            const currentProvider = window.polytransAssistantData?.provider || $('#assistant-provider').val() || 'openai';
            this.updateSystemPromptVisibility(currentProvider);

            // Create system prompt textarea with variable sidebar
            const systemContainer = document.getElementById('system-prompt-editor-container');
            if (systemContainer) {
                // Check if provider supports system prompt
                const providerManifests = polytransAssistants.providerManifests || {};
                const manifest = providerManifests[currentProvider];
                const supportsSystemPrompt = manifest ? (manifest.supports_system_prompt !== false) : true; // Default to true for backward compatibility
                
                const systemTextarea = $('<textarea>')
                    .attr('id', 'assistant-system-prompt')
                    .attr('name', 'system_prompt')
                    .attr('rows', 8)
                    .attr('required', supportsSystemPrompt) // Only required if provider supports it
                    .addClass('large-text code prompt-editor-textarea')
                    .css('width', '100%')
                    .val(window.polytransAssistantData.system_prompt || '');
                
                const wrapper = $('<div>').addClass('field-wrapper');
                wrapper.append(systemTextarea);
                wrapper.append(this.renderVariableSidebar());
                $(systemContainer).append(wrapper);
                
                this.systemPromptEditor = systemTextarea[0];
            }

            // Create user message template textarea with variable sidebar
            const userContainer = document.getElementById('user-message-editor-container');
            if (userContainer) {
                const userTextarea = $('<textarea>')
                    .attr('id', 'assistant-user-message')
                    .attr('name', 'user_message_template')
                    .attr('rows', 10)
                    .addClass('large-text code prompt-editor-textarea')
                    .css('width', '100%')
                    .val(window.polytransAssistantData.user_message_template || '');
                
                const wrapper = $('<div>').addClass('field-wrapper');
                wrapper.append(userTextarea);
                wrapper.append(this.renderVariableSidebar());
                $(userContainer).append(wrapper);
                
                this.userMessageEditor = userTextarea[0];
            }
            
            // Initialize variable pill click handlers
            this.initVariablePills();
        },

        /**
         * Render variable sidebar
         */
        renderVariableSidebar: function() {
            // Use variables from PolyTransPromptEditor module
            const variables = typeof PolyTransPromptEditor !== 'undefined' 
                ? PolyTransPromptEditor.variables 
                : [];

            const pills = variables.map(v => 
                `<span class="var-pill" data-variable="{{ ${v.name} }}" title="${v.desc}">${v.name}</span>`
            ).join('');

            return $('<div>').addClass('variable-sidebar').html(pills);
        },

        /**
         * Initialize variable pill click handlers
         */
        initVariablePills: function() {
            let lastFocusedTextarea = null;

            // Track last focused textarea
            $(document).on('focus', '.prompt-editor-textarea', function() {
                lastFocusedTextarea = this;
            });

            // Handle variable pill clicks
            $(document).on('click', '.var-pill', function() {
                const variable = $(this).data('variable');
                const textarea = lastFocusedTextarea || $(this).closest('.field-wrapper').find('textarea')[0];

                if (textarea) {
                    const start = textarea.selectionStart;
                    const end = textarea.selectionEnd;
                    const text = textarea.value;
                    const before = text.substring(0, start);
                    const after = text.substring(end, text.length);

                    textarea.value = before + variable + after;
                    textarea.selectionStart = textarea.selectionEnd = start + variable.length;
                    textarea.focus();
                }
            });
        },

        /**
         * Handle provider change - load models dynamically and update system prompt visibility
         */
        handleProviderChange: function(e) {
            const provider = $(e.target).val();
            const $modelField = $('#assistant-model');
            const currentModel = $modelField.data('selected-model') || $modelField.val();

            // Update system prompt visibility based on provider support
            this.updateSystemPromptVisibility(provider);

            // Show loading state
            $modelField.prop('disabled', true);
            $modelField.html('<option value="">Loading models...</option>');

            // Load models via AJAX
            $.ajax({
                url: polytransAssistants.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_get_provider_models',
                    provider_id: provider,
                    selected_model: currentModel,
                    nonce: polytransAssistants.nonce
                },
                success: (response) => {
                    if (response.success && response.data && response.data.models) {
                        this.populateModelSelect($modelField, response.data.models, currentModel);
                    } else {
                        // Fallback to empty select
                        $modelField.html('<option value="">Use Global Setting</option>');
                        console.error('Failed to load models:', response);
                    }
                    $modelField.prop('disabled', false);
                },
                error: (xhr, status, error) => {
                    console.error('AJAX error loading models:', error);
                    $modelField.html('<option value="">Use Global Setting</option>');
                    $modelField.prop('disabled', false);
                }
            });
        },

        /**
         * Update system prompt field visibility based on provider support
         */
        updateSystemPromptVisibility: function(provider) {
            const $systemPromptRow = $('#system-prompt-row');
            const $systemPromptField = $('#assistant-system-prompt');
            const $requiredStar = $('.system-prompt-required');
            
            // Check if provider supports system prompt
            const providerManifests = polytransAssistants.providerManifests || {};
            const manifest = providerManifests[provider];
            // Check for system_prompt capability, fallback to supports_system_prompt for backward compatibility
            let supportsSystemPrompt = true; // Default to true for backward compatibility
            if (manifest) {
                if (manifest.capabilities && Array.isArray(manifest.capabilities)) {
                    supportsSystemPrompt = manifest.capabilities.includes('system_prompt');
                } else {
                    // Fallback to supports_system_prompt for backward compatibility
                    supportsSystemPrompt = manifest.supports_system_prompt !== false;
                }
            }
            
            if (supportsSystemPrompt) {
                // Show system prompt field
                $systemPromptRow.show();
                if ($systemPromptField.length) {
                    $systemPromptField.prop('required', true);
                }
                $requiredStar.show();
            } else {
                // Hide system prompt field (provider doesn't support it)
                $systemPromptRow.hide();
                if ($systemPromptField.length) {
                    $systemPromptField.prop('required', false);
                    $systemPromptField.val(''); // Clear value since it won't be used
                }
                $requiredStar.hide();
            }
        },

        /**
         * Populate model select dropdown
         */
        populateModelSelect: function($select, groupedModels, selectedModel) {
            $select.empty();
            
            // Add "Use Global Setting" option
            const globalSelected = (!selectedModel || selectedModel === '') ? 'selected' : '';
            $select.append($('<option></option>')
                .attr('value', '')
                .prop('selected', globalSelected)
                .text('Use Global Setting'));

            // Add grouped models
            for (const [groupName, models] of Object.entries(groupedModels)) {
                const $optgroup = $('<optgroup></optgroup>').attr('label', groupName);
                
                for (const [modelValue, modelLabel] of Object.entries(models)) {
                    const isSelected = (selectedModel === modelValue) ? 'selected' : '';
                    $optgroup.append($('<option></option>')
                        .attr('value', modelValue)
                        .prop('selected', isSelected)
                        .text(modelLabel));
                }
                
                $select.append($optgroup);
            }
        },

        /**
         * Handle refresh models button click
         */
        handleRefreshModels: function(e) {
            e.preventDefault();
            const provider = $('#assistant-provider').val();
            const $modelField = $('#assistant-model');
            const currentModel = $modelField.val();

            if (!provider) {
                alert('Please select a provider first.');
                return;
            }

            // Trigger provider change handler to reload models
            $('#assistant-provider').trigger('change');
        },

        /**
         * Handle response format change - show/hide schema field
         */
        handleResponseFormatChange: function() {
            const format = $('#assistant-response-format').val();
            const $schemaRow = $('#expected-output-schema-row');
            
            if (format === 'json') {
                $schemaRow.show();
            } else {
                $schemaRow.hide();
            }
        },

        /**
         * Handle delete assistant
         */
        handleDelete: function(e) {
            e.preventDefault();

            if (!confirm(polytransAssistants.strings.confirmDelete)) {
                return;
            }

            const $button = $(e.currentTarget);
            const assistantId = $button.data('assistant-id');

            $button.prop('disabled', true).text(polytransAssistants.strings.loading);

            $.ajax({
                url: polytransAssistants.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_delete_assistant',
                    nonce: polytransAssistants.nonce,
                    assistant_id: assistantId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row from table
                        $button.closest('tr').fadeOut(function() {
                            $(this).remove();
                        });
                        AssistantsAdmin.showNotice(response.data.message, 'success');
                    } else {
                        AssistantsAdmin.showNotice(response.data.message, 'error');
                        $button.prop('disabled', false).text(polytransAssistants.strings.delete);
                    }
                },
                error: function() {
                    AssistantsAdmin.showNotice(polytransAssistants.strings.deleteError, 'error');
                    $button.prop('disabled', false).text(polytransAssistants.strings.delete);
                }
            });
        },

        /**
         * Handle save assistant
         */
        handleSave: function(e) {
            e.preventDefault();

            const $form = $(e.currentTarget);
            const $submitBtn = $form.find('button[type="submit"]');

            // Get system prompt from textarea
            let systemPrompt = '';
            if (this.systemPromptEditor) {
                systemPrompt = $(this.systemPromptEditor).val();
            }

            // Get user message template from textarea
            let userMessage = '';
            if (this.userMessageEditor) {
                userMessage = $(this.userMessageEditor).val();
            }

            // Validate required fields
            const name = $('#assistant-name').val().trim();
            const provider = $('#assistant-provider').val();
            
            if (!name || !provider || !systemPrompt) {
                this.showNotice(polytransAssistants.strings.requiredField, 'error');
                return;
            }

            $submitBtn.prop('disabled', true).text(polytransAssistants.strings.loading);

            // Get expected output schema if format is JSON
            let expectedOutputSchema = null;
            const responseFormat = $('#assistant-response-format').val();
            if (responseFormat === 'json') {
                const schemaText = $('#assistant-expected-output-schema').val().trim();
                if (schemaText) {
                    // Check if schema contains Twig syntax (dynamic template)
                    const hasTwigSyntax = schemaText.includes('{%') || schemaText.includes('{{');
                    if (hasTwigSyntax) {
                        // Store as raw string - will be interpolated at runtime
                        expectedOutputSchema = schemaText;
                    } else {
                        // Validate as pure JSON
                        try {
                            expectedOutputSchema = JSON.parse(schemaText);
                            expectedOutputSchema = JSON.stringify(expectedOutputSchema);
                        } catch (e) {
                            this.showNotice('Invalid JSON in Expected Output Schema: ' + e.message, 'error');
                            $submitBtn.prop('disabled', false).text(polytransAssistants.strings.save);
                            return;
                        }
                    }
                }
            }

            // Prepare form data
            const formData = {
                action: 'polytrans_save_assistant',
                nonce: polytransAssistants.nonce,
                assistant_id: $form.find('input[name="assistant_id"]').val(),
                name: name,
                provider: provider,
                model: $('#assistant-model').val(),
                system_prompt: systemPrompt,
                user_message_template: userMessage,
                response_format: responseFormat,
                expected_output_schema: expectedOutputSchema || null,
                config: {
                    temperature: parseFloat($('#assistant-temperature').val()) || 0.7
                }
            };

            $.ajax({
                url: polytransAssistants.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        AssistantsAdmin.showNotice(response.data.message, 'success');
                        
                        // Redirect to list page after short delay
                        setTimeout(function() {
                            window.location.href = 'admin.php?page=polytrans-assistants';
                        }, 1500);
                    } else {
                        AssistantsAdmin.showNotice(response.data.message, 'error');
                        $submitBtn.prop('disabled', false).text(polytransAssistants.strings.save);
                    }
                },
                error: function() {
                    AssistantsAdmin.showNotice(polytransAssistants.strings.saveError, 'error');
                    $submitBtn.prop('disabled', false).text(polytransAssistants.strings.save);
                }
            });
        },


        /**
         * Show admin notice
         */
        showNotice: function(message, type) {
            const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Handle workflow migration
         */
        handleMigration: function(e) {
            e.preventDefault();

            if (!confirm('This will migrate all legacy workflow steps to managed assistants. This action cannot be undone. Continue?')) {
                return;
            }

            const $button = $(e.currentTarget);
            const $spinner = $button.next('.spinner');

            $button.prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: polytransAssistants.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_migrate_workflows',
                    nonce: polytransAssistants.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');

                    if (response.success) {
                        AssistantsAdmin.showNotice(response.data.message, 'success');
                        
                        // Reload page after short delay to show updated list
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        AssistantsAdmin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    AssistantsAdmin.showNotice('Migration failed. Please check logs.', 'error');
                }
            });
        },

        /**
         * Handle assistant test run
         */
        handleAssistantTest: function(e) {
            e.preventDefault();

            const $container = $('#assistant-tester-container');
            const $button = $(e.currentTarget);
            const $spinner = $button.next('.spinner');
            const assistantId = $container.data('assistant-id');
            const selectedPost = this.getSelectedAssistantPost();
            const content = (selectedPost?.content || '').trim();
            const title = selectedPost?.title || '';

            if (!content) {
                this.showNotice('Select an existing post with non-empty content.', 'error');
                return;
            }

            $button.prop('disabled', true).text('Running Test...');
            $spinner.addClass('is-active');
            $('#assistant-test-results').hide().empty();

            $.ajax({
                url: polytransAssistants.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_test_assistant',
                    nonce: polytransAssistants.nonce,
                    assistant_id: assistantId,
                    source_language: $('#assistant-test-source-language').val(),
                    target_language: $('#assistant-test-target-language').val(),
                    selected_post_id: selectedPost?.id || 0,
                    title: title,
                    content: content
                },
                success: (response) => {
                    if (response.success) {
                        this.renderAssistantTestResults(response.data);
                        this.showNotice('Assistant test completed.', 'success');
                    } else {
                        const message = response.data?.message || 'Assistant test failed.';
                        this.renderAssistantTestError(message);
                        this.showNotice(message, 'error');
                    }
                },
                error: () => {
                    const message = 'Assistant test failed. Please check logs.';
                    this.renderAssistantTestError(message);
                    this.showNotice(message, 'error');
                },
                complete: () => {
                    $button.prop('disabled', false).text('Run Test');
                    $spinner.removeClass('is-active');
                }
            });
        },

        /**
         * Load recent posts for assistant tester.
         */
        loadRecentPostsForAssistantTest: function() {
            const $dropdown = $('#assistant-test-recent-posts');
            const language = $('#assistant-test-source-language').val();
            $dropdown.html('<option value="">Loading posts...</option>');

            $.ajax({
                url: polytransAssistants.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_get_recent_posts_for_assistant_test',
                    nonce: polytransAssistants.nonce,
                    language: language,
                    limit: 20
                },
                success: (response) => {
                    if (!response.success || !response.data?.posts) {
                        $dropdown.html('<option value="">No posts found</option>');
                        return;
                    }

                    window.polytransAssistantRecentPosts = response.data.posts;
                    let options = '<option value="">Select a post...</option>';
                    response.data.posts.forEach((post) => {
                        const dateStr = new Date(post.post_date).toLocaleDateString();
                        options += `<option value="${post.id}">${this.escapeHtml(post.title)} (${dateStr})</option>`;
                    });
                    $dropdown.html(options);
                },
                error: () => {
                    $dropdown.html('<option value="">Error loading posts</option>');
                }
            });
        },

        /**
         * Display selected post details in assistant tester.
         */
        handleAssistantRecentPostChange: function() {
            const post = this.getSelectedAssistantPost();
            if (!post) {
                $('#assistant-selected-post-info').hide();
                return;
            }

            const metaKeys = Object.keys(post.meta || {});
            const metaHtml = metaKeys.length
                ? `<div><strong>Meta:</strong> ${this.escapeHtml(metaKeys.join(', '))}</div>`
                : '';

            $('#assistant-selected-post-details').html(`
                <div><strong>Title:</strong> ${this.escapeHtml(post.title || '')}</div>
                <div><strong>Type:</strong> ${this.escapeHtml(post.post_type || '')} | <strong>ID:</strong> ${this.escapeHtml(String(post.id || ''))}</div>
                <div><strong>Content preview:</strong> ${this.escapeHtml(post.description || '')}</div>
                ${metaHtml}
            `);
            $('#assistant-selected-post-info').show();
        },

        /**
         * Resolve selected post object for assistant tester.
         */
        getSelectedAssistantPost: function() {
            const id = $('#assistant-test-recent-posts').val();
            if (!id || !Array.isArray(window.polytransAssistantRecentPosts)) {
                return null;
            }
            return window.polytransAssistantRecentPosts.find((post) => String(post.id) === String(id)) || null;
        },

        /**
         * Render assistant test result payload
         */
        renderAssistantTestResults: function(data) {
            const output = typeof data.output === 'string'
                ? data.output
                : JSON.stringify(data.output, null, 2);
            const usage = data.usage && Object.keys(data.usage).length
                ? JSON.stringify(data.usage, null, 2)
                : 'No usage data returned.';

            const html = `
                <div class="test-results success">
                    <h4>Test Results - Success</h4>
                    <div class="execution-details">
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(data.provider || 'unknown')}</span>
                            <span class="label">Provider</span>
                        </div>
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(data.model || 'default')}</span>
                            <span class="label">Model</span>
                        </div>
                        <div class="execution-detail">
                            <span class="value">${(data.execution_time || 0).toFixed(3)}s</span>
                            <span class="label">Execution Time</span>
                        </div>
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(data.expected_format || 'text')}</span>
                            <span class="label">Format</span>
                        </div>
                    </div>

                    <div class="assistant-test-section">
                        <h5>Output</h5>
                        <pre><code>${this.escapeHtml(output)}</code></pre>
                    </div>

                    <details class="assistant-test-details">
                        <summary>Interpolated Prompts</summary>
                        <h5>System Prompt</h5>
                        <pre><code>${this.escapeHtml(data.interpolated_system_prompt || '')}</code></pre>
                        <h5>User Message</h5>
                        <pre><code>${this.escapeHtml(data.interpolated_user_message || '')}</code></pre>
                    </details>

                    <details class="assistant-test-details">
                        <summary>Test Context</summary>
                        <pre><code>${this.escapeHtml(JSON.stringify(data.context || {}, null, 2))}</code></pre>
                    </details>

                    <details class="assistant-test-details">
                        <summary>Usage</summary>
                        <pre><code>${this.escapeHtml(usage)}</code></pre>
                    </details>
                </div>
            `;

            $('#assistant-test-results').html(html).show();
        },

        /**
         * Render assistant test failure
         */
        renderAssistantTestError: function(message) {
            $('#assistant-test-results').html(`
                <div class="test-results error">
                    <h4>Test Results - Failed</h4>
                    <div class="step-error-content">${this.escapeHtml(message)}</div>
                </div>
            `).show();
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            text = String(text ?? '');
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        AssistantsAdmin.init();
    });

})(jQuery);
