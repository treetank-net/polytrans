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
            $('#assistant-generate-description-btn').on('click', this.handleAssistantDescriptionGeneration.bind(this));
            $('#assistant-generate-objective-btn').on('click', this.handleAssistantObjectiveGeneration.bind(this));

            // Response format change - show/hide schema field
            $('#assistant-response-format').on('change', this.handleResponseFormatChange.bind(this));

            // Migrate workflows
            $('#migrate-workflows-btn').on('click', this.handleMigration.bind(this));

            // Test assistant
            $('#run-assistant-test-btn').on('click', this.handleAssistantTest.bind(this));
            $('#assistant-test-recent-posts').on('change', this.handleAssistantRecentPostChange.bind(this));
            $('#assistant-test-source-language').on('change', this.loadRecentPostsForAssistantTest.bind(this));
            $('#assistant-refine-source-language').on('change', this.loadRecentPostsForAssistantTest.bind(this));
            $('#assistant-refine-refresh-posts').on('click', this.loadRecentPostsForAssistantTest.bind(this));
            $('#assistant-refine-select-all-posts').on('click', this.handleSelectAllRefinementPosts.bind(this));
            $('#assistant-refine-clear-posts').on('click', this.handleClearRefinementPosts.bind(this));
            $('#run-assistant-refinement-btn').on('click', this.handleAssistantRefinement.bind(this));
            $(document).on('click', '#assistant-refine-reeval-btn', this.handleAssistantReevaluateAgain.bind(this));
            $(document).on('click', '#assistant-refine-apply-btn', this.handleAssistantApplyPromptPack.bind(this));
            $(document).on('click', '.assistant-test-tab', this.handleAssistantModeSwitch.bind(this));
        },

        /**
         * Initialize assistant tester page.
         */
        initAssistantTester: function() {
            if (!$('#assistant-tester-container').length) {
                return;
            }
            this.handleAssistantModeSwitch(null, 'test');
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
                description: $('#assistant-description').val() || '',
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

        handleAssistantDescriptionGeneration: function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }

            this.openAssistantDescriptionModal({
                title: 'Generate Assistant Description',
                applySelector: '#assistant-description',
                currentDescription: $('#assistant-description').val() || '',
                applyLabel: 'Apply to Description'
            });
        },

        handleAssistantObjectiveGeneration: function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }

            this.openAssistantDescriptionModal({
                title: 'Generate Primary Purpose',
                applySelector: '#assistant-refine-objective',
                currentDescription: $('#assistant-refine-objective').val() || '',
                applyLabel: 'Apply as Primary Purpose'
            });
        },

        openAssistantDescriptionModal: function(config) {
            const prompts = polytransAssistants.descriptionPrompts || {};
            this.openDescriptionGeneratorModal({
                title: config.title || 'Generate Description',
                systemPrompt: prompts.system || '',
                promptTemplate: prompts.assistant || '',
                currentDescription: config.currentDescription || '',
                applyLabel: config.applyLabel || 'Apply',
                generateLabel: 'Generate Description',
                onGenerate: (systemPrompt, promptTemplate) => {
                    const assistantData = window.polytransAssistantData || {};
                    return $.ajax({
                        url: polytransAssistants.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'polytrans_generate_assistant_description',
                            nonce: polytransAssistants.nonce,
                            assistant_id: $('input[name="assistant_id"]').val() || assistantData.id || 0,
                            name: $('#assistant-name').val() || assistantData.name || '',
                            description: config.currentDescription || $('#assistant-description').val() || assistantData.description || '',
                            provider: $('#assistant-provider').val() || assistantData.provider || 'openai',
                            model: $('#assistant-model').val() || assistantData.model || '',
                            system_prompt: this.systemPromptEditor ? $(this.systemPromptEditor).val() : (assistantData.system_prompt || ''),
                            user_message_template: this.userMessageEditor ? $(this.userMessageEditor).val() : (assistantData.user_message_template || ''),
                            response_format: $('#assistant-response-format').val() || assistantData.expected_format || 'text',
                            expected_output_schema: $('#assistant-expected-output-schema').val() || assistantData.expected_output_schema || '',
                            description_system_prompt: systemPrompt,
                            description_prompt_template: promptTemplate
                        }
                    });
                },
                onApply: (description) => {
                    const $target = $(config.applySelector);
                    $target.val(description).trigger('change');
                },
                onSave: (description) => {
                    const assistantData = window.polytransAssistantData || {};
                    return $.ajax({
                        url: polytransAssistants.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'polytrans_save_assistant_description',
                            nonce: polytransAssistants.nonce,
                            assistant_id: $('input[name="assistant_id"]').val() || assistantData.id || 0,
                            description
                        }
                    });
                }
            });
        },

        openDescriptionGeneratorModal: function(config) {
            $('.polytrans-description-modal-backdrop').remove();

            const modalHtml = `
                <div class="polytrans-description-modal-backdrop">
                    <div class="polytrans-description-modal" role="dialog" aria-modal="true">
                        <div class="polytrans-description-modal-header">
                            <h2>${this.escapeHtml(config.title || 'Generate Description')}</h2>
                            <button type="button" class="button-link polytrans-description-modal-close" aria-label="Close">&times;</button>
                        </div>
                        <div class="polytrans-description-modal-body">
                            <label><strong>Generated Description</strong></label>
                            <textarea class="large-text polytrans-description-result" rows="4">${this.escapeHtml(config.currentDescription || '')}</textarea>
                            <details class="polytrans-description-prompts" open>
                                <summary>Generator Prompts</summary>
                                <label><strong>System Prompt</strong></label>
                                <textarea class="large-text code polytrans-description-system-prompt" rows="6">${this.escapeHtml(config.systemPrompt || '')}</textarea>
                                <label><strong>User Message Template</strong></label>
                                <textarea class="large-text code polytrans-description-prompt-template" rows="12">${this.escapeHtml(config.promptTemplate || '')}</textarea>
                            </details>
                            <details class="polytrans-description-rendered">
                                <summary>Rendered Prompt and Raw Response</summary>
                                <h4>Rendered System Prompt</h4>
                                <pre><code class="polytrans-description-rendered-system"></code></pre>
                                <h4>Rendered User Message</h4>
                                <pre><code class="polytrans-description-rendered-user"></code></pre>
                                <h4>Raw Response</h4>
                                <pre><code class="polytrans-description-raw-response"></code></pre>
                            </details>
                            <div class="polytrans-description-modal-error" style="display:none;"></div>
                        </div>
                        <div class="polytrans-description-modal-footer">
                            <button type="button" class="button button-primary polytrans-description-generate">${this.escapeHtml(config.generateLabel || 'Generate')}</button>
                            <button type="button" class="button button-primary polytrans-description-apply">${this.escapeHtml(config.applyLabel || 'Apply')}</button>
                            ${config.onSave ? '<button type="button" class="button polytrans-description-save">Apply & Save</button>' : ''}
                            <button type="button" class="button polytrans-description-modal-close">Cancel</button>
                        </div>
                    </div>
                </div>
            `;

            const $modal = $(modalHtml);
            $('body').append($modal);

            const close = () => $modal.remove();
            $modal.on('click', '.polytrans-description-modal-close', close);
            $modal.on('click', function(event) {
                if (event.target === $modal[0]) {
                    close();
                }
            });

            $modal.on('click', '.polytrans-description-generate', async () => {
                const $button = $modal.find('.polytrans-description-generate');
                const $error = $modal.find('.polytrans-description-modal-error');
                $button.prop('disabled', true).text('Generating...');
                $error.hide().text('');

                try {
                    const response = await config.onGenerate(
                        $modal.find('.polytrans-description-system-prompt').val() || '',
                        $modal.find('.polytrans-description-prompt-template').val() || ''
                    );
                    if (!response || !response.success) {
                        throw new Error(response?.data?.message || 'Description generation failed.');
                    }
                    const data = response.data || {};
                    $modal.find('.polytrans-description-result').val(data.description || '');
                    $modal.find('.polytrans-description-rendered-system').text(data.rendered_system_prompt || '');
                    $modal.find('.polytrans-description-rendered-user').text(data.rendered_prompt || '');
                    $modal.find('.polytrans-description-raw-response').text(data.raw_response || '');
                } catch (error) {
                    const message = this.resolveAjaxErrorMessage(error, 'Description generation failed.');
                    $error.text(message).show();
                } finally {
                    $button.prop('disabled', false).text(config.generateLabel || 'Generate');
                }
            });

            $modal.on('click', '.polytrans-description-apply', () => {
                const description = ($modal.find('.polytrans-description-result').val() || '').trim();
                if (!description) {
                    $modal.find('.polytrans-description-modal-error').text('Description is empty.').show();
                    return;
                }
                config.onApply(description);
                close();
            });

            $modal.on('click', '.polytrans-description-save', async () => {
                const description = ($modal.find('.polytrans-description-result').val() || '').trim();
                const $button = $modal.find('.polytrans-description-save');
                const $error = $modal.find('.polytrans-description-modal-error');

                if (!description) {
                    $error.text('Description is empty.').show();
                    return;
                }

                $button.prop('disabled', true).text('Saving...');
                $error.hide().text('');

                try {
                    config.onApply(description);
                    const response = await config.onSave(description);
                    if (!response || !response.success) {
                        throw new Error(response?.data?.message || 'Description save failed.');
                    }
                    window.polytransAssistantData = {
                        ...(window.polytransAssistantData || {}),
                        description
                    };
                    this.showNotice(response.data?.message || 'Description saved.', 'success');
                    close();
                } catch (error) {
                    const message = this.resolveAjaxErrorMessage(error, 'Description save failed.');
                    $error.text(message).show();
                } finally {
                    $button.prop('disabled', false).text('Apply & Save');
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
        loadRecentPostsForAssistantTest: function(e) {
            const $singleDropdown = $('#assistant-test-recent-posts');
            const $multiSelect = $('#assistant-refine-recent-posts');
            let language = $('#assistant-test-source-language').val() || '';

            if (e && e.currentTarget && e.currentTarget.id === 'assistant-refine-source-language') {
                language = $('#assistant-refine-source-language').val() || language;
            } else if ($('#assistant-mode-refine').is(':visible')) {
                language = $('#assistant-refine-source-language').val() || language;
            }

            if ($singleDropdown.length) {
                $singleDropdown.html('<option value="">Loading posts...</option>');
            }
            if ($multiSelect.length) {
                $multiSelect.html('<option value="">Loading posts...</option>');
            }

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
                        if ($singleDropdown.length) {
                            $singleDropdown.html('<option value="">No posts found</option>');
                        }
                        if ($multiSelect.length) {
                            $multiSelect.html('<option value="">No posts found</option>');
                        }
                        return;
                    }

                    window.polytransAssistantRecentPosts = response.data.posts;
                    let singleOptions = '<option value="">Select a post...</option>';
                    let multiOptions = '';
                    response.data.posts.forEach((post) => {
                        const dateStr = new Date(post.post_date).toLocaleDateString();
                        const option = `<option value="${post.id}">${this.escapeHtml(post.title)} (${dateStr})</option>`;
                        singleOptions += option;
                        multiOptions += option;
                    });

                    if ($singleDropdown.length) {
                        $singleDropdown.html(singleOptions);
                    }
                    if ($multiSelect.length) {
                        $multiSelect.html(multiOptions);
                    }
                },
                error: () => {
                    if ($singleDropdown.length) {
                        $singleDropdown.html('<option value="">Error loading posts</option>');
                    }
                    if ($multiSelect.length) {
                        $multiSelect.html('<option value="">Error loading posts</option>');
                    }
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
         * Resolve selected posts for refinement mode.
         */
        getSelectedAssistantRefinementPosts: function() {
            const selectedIds = $('#assistant-refine-recent-posts').val() || [];
            if (!selectedIds.length || !Array.isArray(window.polytransAssistantRecentPosts)) {
                return [];
            }

            const idSet = new Set(selectedIds.map((id) => String(id)));
            return window.polytransAssistantRecentPosts.filter((post) => idSet.has(String(post.id)));
        },

        /**
         * Switch between tester modes.
         */
        handleAssistantModeSwitch: function(e, forcedMode = null) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }

            const mode = forcedMode || $(e.currentTarget).data('mode') || 'test';

            $('.assistant-test-tab').removeClass('is-active');
            $(`.assistant-test-tab[data-mode="${mode}"]`).addClass('is-active');

            $('.assistant-mode-panel').hide();
            $(`#assistant-mode-${mode}`).show();
        },

        /**
         * Select every post in refinement selector.
         */
        handleSelectAllRefinementPosts: function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }
            const values = Array.isArray(window.polytransAssistantRecentPosts)
                ? window.polytransAssistantRecentPosts.map((post) => String(post.id))
                : [];
            $('#assistant-refine-recent-posts').val(values);
        },

        /**
         * Clear selected posts in refinement selector.
         */
        handleClearRefinementPosts: function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }
            $('#assistant-refine-recent-posts').val([]);
        },

        /**
         * Run prompt refinement flow:
         * Full iterations on the same selected posts.
         */
        handleAssistantRefinement: async function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }

            const $container = $('#assistant-tester-container');
            const assistantId = parseInt($container.data('assistant-id') || 0, 10);
            const sourceLanguage = ($('#assistant-refine-source-language').val() || '').trim();
            const targetLanguage = ($('#assistant-refine-target-language').val() || '').trim();
            const criteria = ($('#assistant-refine-criteria').val() || '').trim();
            const promptObjective = ($('#assistant-refine-objective').val() || '').trim();
            const evaluatorSystemPrompt = ($('#assistant-refine-evaluator-system-prompt').val() || '').trim();
            const evaluatorTemplate = ($('#assistant-refine-evaluator-template').val() || '').trim();
            const adjusterSystemPrompt = ($('#assistant-refine-adjuster-system-prompt').val() || '').trim();
            const adjusterTemplate = ($('#assistant-refine-adjuster-template').val() || '').trim();
            const configuredIterations = parseInt($('#assistant-refine-iterations').val() || '1', 10);
            const totalIterations = Number.isFinite(configuredIterations)
                ? Math.max(1, Math.min(configuredIterations, 10))
                : 1;
            const selectedPosts = this.getSelectedAssistantRefinementPosts();

            await this.runAssistantRefinementIterations({
                assistantId,
                sourceLanguage,
                targetLanguage,
                criteria,
                promptObjective,
                evaluatorSystemPrompt,
                evaluatorTemplate,
                adjusterSystemPrompt,
                adjusterTemplate,
                totalIterations,
                selectedPosts,
                initialPromptPack: null,
                existingIterations: [],
                initialBasePromptPack: null
            }, {
                $button: $('#run-assistant-refinement-btn'),
                runningLabel: 'Running Full Re-eval...',
                idleLabel: 'Run Refinement',
                successMessage: 'Prompt refinement + full re-eval completed.'
            });
        },

        /**
         * Continue with additional full re-eval iterations from the latest prompt pack.
         */
        handleAssistantReevaluateAgain: async function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }

            const session = this.lastAssistantRefinementSession || null;
            if (!session || !session.finalPromptPack) {
                this.showNotice('Run refinement first to use re-evaluate again.', 'error');
                return;
            }

            const configuredIterations = parseInt($('#assistant-refine-extra-iterations').val() || '1', 10);
            const totalIterations = Number.isFinite(configuredIterations)
                ? Math.max(1, Math.min(configuredIterations, 10))
                : 1;

            await this.runAssistantRefinementIterations({
                assistantId: session.assistantId,
                sourceLanguage: session.sourceLanguage,
                targetLanguage: session.targetLanguage,
                criteria: session.criteria,
                promptObjective: session.promptObjective,
                evaluatorSystemPrompt: session.evaluatorSystemPrompt,
                evaluatorTemplate: session.evaluatorTemplate,
                adjusterSystemPrompt: session.adjusterSystemPrompt,
                adjusterTemplate: session.adjusterTemplate,
                totalIterations,
                selectedPosts: session.selectedPosts,
                initialPromptPack: session.finalPromptPack,
                existingIterations: session.iterations,
                initialBasePromptPack: session.initialBasePromptPack,
                initialEvaluatedRuns: session.finalEvaluationRuns
            }, {
                $button: $('#assistant-refine-reeval-btn'),
                runningLabel: 'Re-evaluating...',
                idleLabel: 'Re-evaluate Again',
                successMessage: `Full re-eval completed (${totalIterations} extra iteration${totalIterations === 1 ? '' : 's'}).`
            });
        },

        /**
         * Apply final prompt pack from the latest refinement session.
         */
        handleAssistantApplyPromptPack: async function(e) {
            if (e && typeof e.preventDefault === 'function') {
                e.preventDefault();
            }

            const session = this.lastAssistantRefinementSession || null;
            if (!session) {
                this.showNotice('No final prompt pack to apply. Run refinement first.', 'error');
                return;
            }
            const selectedPromptPack = this.resolveAssistantPromptPackSelection(session);
            if (!selectedPromptPack) {
                this.showNotice('Select a valid prompt pack version to apply.', 'error');
                return;
            }

            const $button = $('#assistant-refine-apply-btn');
            const idleLabel = $button.text() || 'Apply Selected Prompt Pack';
            $button.prop('disabled', true).text('Applying...');

            try {
                const response = await $.ajax({
                    url: polytransAssistants.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'polytrans_apply_assistant_prompt_pack',
                        nonce: polytransAssistants.nonce,
                        assistant_id: session.assistantId,
                        system_prompt: selectedPromptPack.system_prompt || '',
                        user_message_template: selectedPromptPack.user_message_template || '',
                        expected_output_schema: selectedPromptPack.expected_output_schema || '{}'
                    }
                });

                if (!response || !response.success) {
                    throw new Error(response?.data?.message || 'Failed to apply prompt pack.');
                }

                this.showNotice('Selected prompt pack applied to assistant.', 'success');
            } catch (error) {
                const message = this.resolveAjaxErrorMessage(error, 'Failed to apply prompt pack.');
                this.showNotice(message, 'error');
            } finally {
                $button.prop('disabled', false).text(idleLabel);
            }
        },

        /**
         * Execute one refinement run (N full iterations).
         */
        runAssistantRefinementIterations: async function(config, ui) {
            const assistantId = parseInt(config.assistantId || 0, 10);
            const sourceLanguage = String(config.sourceLanguage || '').trim();
            const targetLanguage = String(config.targetLanguage || '').trim();
            const criteria = String(config.criteria || '').trim();
            const promptObjective = String(config.promptObjective || '').trim();
            const evaluatorSystemPrompt = String(config.evaluatorSystemPrompt || '').trim();
            const evaluatorTemplate = String(config.evaluatorTemplate || '').trim();
            const adjusterSystemPrompt = String(config.adjusterSystemPrompt || '').trim();
            const adjusterTemplate = String(config.adjusterTemplate || '').trim();
            const totalIterations = Number.isFinite(config.totalIterations)
                ? Math.max(1, Math.min(parseInt(config.totalIterations, 10), 10))
                : 1;
            const selectedPosts = Array.isArray(config.selectedPosts) ? config.selectedPosts : [];
            const existingIterations = Array.isArray(config.existingIterations) ? config.existingIterations.slice() : [];
            const initialEvaluatedRuns = Array.isArray(config.initialEvaluatedRuns) ? config.initialEvaluatedRuns.slice() : [];
            const baseIterationCount = existingIterations.length;
            const reuseInitialEvaluation = initialEvaluatedRuns.length > 0 && !!config.initialPromptPack;
            let currentPromptPack = config.initialPromptPack ? this.normalizePromptPack(config.initialPromptPack) : null;
            let initialBasePromptPack = config.initialBasePromptPack ? this.normalizePromptPack(config.initialBasePromptPack) : null;

            if (!assistantId) {
                this.showNotice('Assistant context is missing.', 'error');
                return;
            }
            if (!sourceLanguage || !targetLanguage) {
                this.showNotice('Source and target language are required.', 'error');
                return;
            }
            if (!criteria) {
                this.showNotice('Refinement criteria is required.', 'error');
                return;
            }
            if (!selectedPosts.length) {
                this.showNotice('Select at least one post for refinement.', 'error');
                return;
            }

            const $button = ui?.$button || $('#run-assistant-refinement-btn');
            const runningLabel = ui?.runningLabel || 'Running...';
            const idleLabel = ui?.idleLabel || 'Run';
            const successMessage = ui?.successMessage || 'Refinement completed.';
            const $progress = $('#assistant-refinement-progress');
            const $results = $('#assistant-refinement-results');
            const $primaryButton = $('#run-assistant-refinement-btn');
            const $reevalButton = $('#assistant-refine-reeval-btn');
            const $applyButton = $('#assistant-refine-apply-btn');
            const stepsPerIteration = (selectedPosts.length * 2) + 1;
            const finalVerificationSteps = selectedPosts.length * 2;
            const skippedInitialEvaluationSteps = reuseInitialEvaluation ? selectedPosts.length * 2 : 0;

            const progressState = {
                totalPosts: selectedPosts.length,
                completedPosts: 0,
                totalIterations,
                currentIteration: 1,
                absoluteIteration: baseIterationCount + 1,
                phase: 'execution',
                errors: [],
                totalSteps: (totalIterations * stepsPerIteration) + finalVerificationSteps - skippedInitialEvaluationSteps,
                completedSteps: 0,
                logs: []
            };

            this.pushRefinementLog(
                progressState,
                reuseInitialEvaluation
                    ? `Continuing refinement from final verification: ${totalIterations} adjustment iteration(s), ${selectedPosts.length} cached evaluation run(s).`
                    : `Starting full re-eval: ${totalIterations} iteration(s), ${selectedPosts.length} post(s) per iteration.`
            );

            const previousButtonText = $button.text();
            const spinner = $button.next('.spinner');
            $button.prop('disabled', true).text(runningLabel);
            if (spinner.length) {
                spinner.addClass('is-active');
            }
            $primaryButton.prop('disabled', true);
            $reevalButton.prop('disabled', true);
            $applyButton.prop('disabled', true);
            $progress.show();
            $results.hide().empty();
            this.renderAssistantRefinementProgress(progressState);

            const iterationResults = existingIterations.slice();

            try {
                for (let iterationOffset = 1; iterationOffset <= totalIterations; iterationOffset++) {
                    const iterationNumber = baseIterationCount + iterationOffset;
                    progressState.currentIteration = iterationOffset;
                    progressState.absoluteIteration = iterationNumber;
                    const shouldReuseEvaluation = reuseInitialEvaluation && iterationOffset === 1;
                    progressState.phase = shouldReuseEvaluation ? 'adjustment' : 'execution';
                    progressState.completedPosts = 0;
                    progressState.currentPost = '';
                    this.pushRefinementLog(
                        progressState,
                        shouldReuseEvaluation
                            ? `Iteration ${iterationNumber}: reusing final verification results and running prompt adjuster.`
                            : `Iteration ${iterationNumber}: running assistant and evaluator.`
                    );
                    this.renderAssistantRefinementProgress(progressState);

                    const evaluatedRuns = shouldReuseEvaluation ? initialEvaluatedRuns.slice() : [];
                    if (shouldReuseEvaluation) {
                        progressState.completedPosts = selectedPosts.length;
                    }
                    for (let index = 0; !shouldReuseEvaluation && index < selectedPosts.length; index++) {
                        const post = selectedPosts[index];
                        progressState.currentPost = post.title || `Post #${post.id}`;
                        this.pushRefinementLog(progressState, `Iteration ${iterationNumber}, post ${index + 1}/${selectedPosts.length}: assistant execution started.`);
                        this.renderAssistantRefinementProgress(progressState);

                        const assistantRunRequest = {
                            action: 'polytrans_run_assistant_refinement_post',
                            nonce: polytransAssistants.nonce,
                            assistant_id: assistantId,
                            selected_post_id: post.id,
                            source_language: sourceLanguage,
                            target_language: targetLanguage
                        };
                        if (currentPromptPack) {
                            assistantRunRequest.override_system_prompt = currentPromptPack.system_prompt || '';
                            assistantRunRequest.override_user_message_template = currentPromptPack.user_message_template || '';
                            assistantRunRequest.override_expected_output_schema = currentPromptPack.expected_output_schema || '';
                        }

                        const assistantRunResponse = await $.ajax({
                            url: polytransAssistants.ajaxUrl,
                            type: 'POST',
                            data: assistantRunRequest
                        });
                        if (!assistantRunResponse || !assistantRunResponse.success) {
                            const message = assistantRunResponse?.data?.message || `Assistant execution failed for post #${post.id}.`;
                            throw new Error(message);
                        }

                        const runData = assistantRunResponse.data || {};
                        const runId = String(runData.run_id || '').trim();
                        if (!runId) {
                            throw new Error(`Assistant run for post #${post.id} did not return run_id.`);
                        }

                        progressState.completedSteps += 1;
                        this.pushRefinementLog(
                            progressState,
                            `Iteration ${iterationNumber}, post ${index + 1}/${selectedPosts.length}: assistant done (run_id: ${runId}).`
                        );
                        this.renderAssistantRefinementProgress(progressState);

                        const evaluateRequest = {
                            action: 'polytrans_evaluate_assistant_run',
                            nonce: polytransAssistants.nonce,
                            assistant_id: assistantId,
                            run_id: runId,
                            criteria: criteria,
                            prompt_objective: promptObjective,
                            evaluator_system_prompt: evaluatorSystemPrompt,
                            evaluator_prompt_template: evaluatorTemplate
                        };

                        const evaluateResponse = await $.ajax({
                            url: polytransAssistants.ajaxUrl,
                            type: 'POST',
                            data: evaluateRequest
                        });
                        if (!evaluateResponse || !evaluateResponse.success) {
                            const message = evaluateResponse?.data?.message || `Evaluation failed for post #${post.id}.`;
                            throw new Error(message);
                        }

                        const evaluatedRun = Object.assign({}, runData, {
                            run_id: String(evaluateResponse?.data?.run_id || runId),
                            evaluation: evaluateResponse?.data?.evaluation || null,
                            final_post_candidate: evaluateResponse?.data?.final_post_candidate || runData?.final_post_candidate || null,
                        });
                        evaluatedRuns.push(evaluatedRun);

                        progressState.completedPosts = index + 1;
                        progressState.completedSteps += 1; // evaluator
                        const score = evaluateResponse?.data?.evaluation?.score;
                        this.pushRefinementLog(
                            progressState,
                            `Iteration ${iterationNumber}, post ${index + 1}/${selectedPosts.length}: evaluator done${score !== null && score !== undefined ? ` (score ${score})` : ''}.`
                        );
                        this.renderAssistantRefinementProgress(progressState);
                    }

                    progressState.phase = 'adjustment';
                    progressState.currentPost = '';
                    this.pushRefinementLog(progressState, `Iteration ${iterationNumber}: running prompt adjuster.`);
                    this.renderAssistantRefinementProgress(progressState);

                    const adjustRequest = {
                        action: 'polytrans_adjust_assistant_prompt',
                        nonce: polytransAssistants.nonce,
                        assistant_id: assistantId,
                        criteria: criteria,
                        prompt_objective: promptObjective,
                        adjuster_system_prompt: adjusterSystemPrompt,
                        adjuster_prompt_template: adjusterTemplate,
                        evaluations: JSON.stringify(evaluatedRuns),
                        refinement_history: JSON.stringify(this.buildAssistantRefinementHistory(iterationResults))
                    };
                    if (currentPromptPack) {
                        adjustRequest.current_system_prompt = currentPromptPack.system_prompt || '';
                        adjustRequest.current_user_message_template = currentPromptPack.user_message_template || '';
                        adjustRequest.current_expected_output_schema = currentPromptPack.expected_output_schema || '';
                    }

                    const adjustResponse = await $.ajax({
                        url: polytransAssistants.ajaxUrl,
                        type: 'POST',
                        data: adjustRequest
                    });
                    if (!adjustResponse || !adjustResponse.success) {
                        const message = adjustResponse?.data?.message || 'Prompt adjuster failed.';
                        throw new Error(message);
                    }

                    progressState.completedSteps += 1;
                    const adjustment = adjustResponse.data || {};
                    const parsed = adjustment.parsed || {};
                    const hasValidPack = !!parsed.is_valid_pack;
                    const nextPromptPack = hasValidPack ? this.normalizePromptPack(parsed) : null;
                    const scoredRuns = evaluatedRuns.filter((run) => run?.evaluation && run.evaluation.score !== null && run.evaluation.score !== undefined);
                    const averageScore = scoredRuns.length
                        ? (scoredRuns.reduce((sum, run) => sum + Number(run.evaluation.score || 0), 0) / scoredRuns.length)
                        : null;

                    if (!initialBasePromptPack) {
                        initialBasePromptPack = this.normalizePromptPack(adjustment.input_prompt_pack || currentPromptPack || {});
                    }

                    iterationResults.push({
                        iteration: iterationNumber,
                        runs: evaluatedRuns,
                        adjustment: adjustment,
                        average_score: averageScore,
                        input_prompt_pack: this.normalizePromptPack(adjustment.input_prompt_pack || currentPromptPack || {}),
                        output_prompt_pack: nextPromptPack
                    });

                    this.pushRefinementLog(
                        progressState,
                        `Iteration ${iterationNumber}: adjuster finished${hasValidPack ? '' : ' (invalid prompt pack format)'}.`
                    );

                    if (!hasValidPack && iterationOffset < totalIterations) {
                        throw new Error(`Iteration ${iterationNumber}: adjuster response is not a valid prompt pack.`);
                    }
                    if (nextPromptPack) {
                        currentPromptPack = nextPromptPack;
                    }

                    this.renderAssistantRefinementProgress(progressState);
                }

                const finalIteration = iterationResults.length ? iterationResults[iterationResults.length - 1] : null;
                const finalAdjustment = finalIteration?.adjustment || {};
                const finalParsed = finalAdjustment.parsed || {};
                const finalOutputPromptPack = finalParsed.is_valid_pack
                    ? this.normalizePromptPack(finalParsed)
                    : (finalIteration?.output_prompt_pack || null);
                const finalPromptPack = finalOutputPromptPack || currentPromptPack || null;

                let finalEvaluationRuns = [];
                if (finalOutputPromptPack) {
                    progressState.phase = 'final_evaluation';
                    progressState.currentPost = '';
                    this.pushRefinementLog(progressState, 'Final verification: evaluating the selected final prompt pack.');
                    this.renderAssistantRefinementProgress(progressState);
                    finalEvaluationRuns = await this.runAssistantFinalVerification({
                        assistantId,
                        sourceLanguage,
                        targetLanguage,
                        criteria,
                        promptObjective,
                        evaluatorSystemPrompt,
                        evaluatorTemplate,
                        selectedPosts,
                        promptPack: finalOutputPromptPack,
                        progressState
                    });
                } else {
                    progressState.totalSteps = Math.max(progressState.completedSteps, progressState.totalSteps - finalVerificationSteps);
                }
                progressState.phase = 'completed';
                progressState.currentPost = '';
                this.pushRefinementLog(progressState, finalOutputPromptPack ? 'Final verification completed.' : 'Full re-eval completed. Final verification skipped because the last adjuster output was not a valid prompt pack.');
                this.renderAssistantRefinementProgress(progressState);

                this.lastAssistantRefinementSession = {
                    assistantId,
                    sourceLanguage,
                    targetLanguage,
                    criteria,
                    promptObjective,
                    evaluatorSystemPrompt,
                    evaluatorTemplate,
                    adjusterSystemPrompt,
                    adjusterTemplate,
                    selectedPosts,
                    iterations: iterationResults,
                    finalPromptPack,
                    initialBasePromptPack,
                    finalEvaluationRuns
                };

                this.renderAssistantRefinementResults({
                    assistantId,
                    criteria,
                    promptObjective,
                    iterations: iterationResults,
                    selectedPosts: selectedPosts,
                    initialBasePromptPack,
                    finalEvaluationRuns
                });
                this.showNotice(successMessage, 'success');
            } catch (error) {
                const message = this.resolveAjaxErrorMessage(error, 'Refinement failed.');
                progressState.phase = 'failed';
                progressState.errors.push(message);
                this.pushRefinementLog(progressState, `FAILED: ${message}`);
                this.renderAssistantRefinementProgress(progressState);
                this.renderAssistantRefinementError(message, iterationResults);
                this.showNotice(message, 'error');
            } finally {
                $button.prop('disabled', false).text(previousButtonText || idleLabel);
                if (spinner.length) {
                    spinner.removeClass('is-active');
                }
                $primaryButton.prop('disabled', false);
                $reevalButton.prop('disabled', false);
                $applyButton.prop('disabled', false);
            }
        },

        /**
         * Run one final evaluation pass for the latest prompt pack without another adjustment.
         */
        runAssistantFinalVerification: async function(config) {
            const finalRuns = [];
            const posts = Array.isArray(config.selectedPosts) ? config.selectedPosts : [];
            const promptPack = this.normalizePromptPack(config.promptPack || {});
            const progressState = config.progressState || null;

            for (let index = 0; index < posts.length; index++) {
                const post = posts[index];
                if (progressState) {
                    progressState.currentPost = post.title || `Post #${post.id}`;
                    this.pushRefinementLog(progressState, `Final verification, post ${index + 1}/${posts.length}: assistant execution started.`);
                    this.renderAssistantRefinementProgress(progressState);
                }

                const runResponse = await $.ajax({
                    url: polytransAssistants.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'polytrans_run_assistant_refinement_post',
                        nonce: polytransAssistants.nonce,
                        assistant_id: config.assistantId,
                        selected_post_id: post.id,
                        source_language: config.sourceLanguage,
                        target_language: config.targetLanguage,
                        override_system_prompt: promptPack.system_prompt || '',
                        override_user_message_template: promptPack.user_message_template || '',
                        override_expected_output_schema: promptPack.expected_output_schema || ''
                    }
                });
                if (!runResponse || !runResponse.success) {
                    throw new Error(runResponse?.data?.message || `Final verification run failed for post #${post.id}.`);
                }

                const runData = runResponse.data || {};
                const runId = String(runData.run_id || '').trim();
                if (!runId) {
                    throw new Error(`Final verification run for post #${post.id} did not return run_id.`);
                }
                if (progressState) {
                    progressState.completedSteps += 1;
                    this.pushRefinementLog(progressState, `Final verification, post ${index + 1}/${posts.length}: assistant done (run_id: ${runId}).`);
                    this.renderAssistantRefinementProgress(progressState);
                }

                const evaluateResponse = await $.ajax({
                    url: polytransAssistants.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'polytrans_evaluate_assistant_run',
                        nonce: polytransAssistants.nonce,
                        assistant_id: config.assistantId,
                        run_id: runId,
                        criteria: config.criteria,
                        prompt_objective: config.promptObjective,
                        evaluator_system_prompt: config.evaluatorSystemPrompt,
                        evaluator_prompt_template: config.evaluatorTemplate
                    }
                });
                if (!evaluateResponse || !evaluateResponse.success) {
                    throw new Error(evaluateResponse?.data?.message || `Final verification evaluation failed for post #${post.id}.`);
                }

                const finalRun = Object.assign({}, runData, {
                    run_id: String(evaluateResponse?.data?.run_id || runId),
                    evaluation: evaluateResponse?.data?.evaluation || null,
                    final_post_candidate: evaluateResponse?.data?.final_post_candidate || runData?.final_post_candidate || null,
                });
                finalRuns.push(finalRun);

                if (progressState) {
                    progressState.completedSteps += 1;
                    const score = evaluateResponse?.data?.evaluation?.score;
                    this.pushRefinementLog(progressState, `Final verification, post ${index + 1}/${posts.length}: evaluator done${score !== null && score !== undefined ? ` (score ${score})` : ''}.`);
                    this.renderAssistantRefinementProgress(progressState);
                }
            }

            return finalRuns;
        },

        /**
         * Keep chronological logs of refinement steps.
         */
        pushRefinementLog: function(state, message) {
            if (!state || !Array.isArray(state.logs)) {
                return;
            }
            const timeLabel = new Date().toLocaleTimeString();
            state.logs.push(`[${timeLabel}] ${String(message || '')}`);
            if (state.logs.length > 400) {
                state.logs.splice(0, state.logs.length - 400);
            }
        },

        /**
         * Normalize jQuery/AJAX error payload into readable message.
         */
        resolveAjaxErrorMessage: function(error, fallbackMessage = 'Request failed.') {
            if (!error) {
                return fallbackMessage;
            }
            if (typeof error === 'string') {
                return error;
            }
            if (error.responseJSON?.data?.message) {
                return String(error.responseJSON.data.message);
            }
            if (error.responseJSON?.message) {
                return String(error.responseJSON.message);
            }
            if (error.statusText && error.status && Number(error.status) >= 400) {
                return `${fallbackMessage} (${error.status} ${error.statusText})`;
            }
            if (error.message) {
                return String(error.message);
            }
            return fallbackMessage;
        },

        /**
         * Render refinement progress summary.
         */
        renderAssistantRefinementProgress: function(state) {
            const phaseLabel = {
                execution: 'Running assistant + evaluator',
                adjustment: 'Running prompt adjuster',
                final_evaluation: 'Final verification',
                completed: 'Completed',
                failed: 'Failed'
            }[state.phase] || 'Preparing';

            const visibleIteration = state.absoluteIteration || state.currentIteration || 1;
            const iterationLine = state.totalIterations > 1
                ? `<div><strong>Iteration in current run:</strong> ${this.escapeHtml(String(state.currentIteration || 1))}/${this.escapeHtml(String(state.totalIterations || 1))}</div><div><strong>Absolute iteration:</strong> ${this.escapeHtml(String(visibleIteration))}</div>`
                : `<div><strong>Iteration:</strong> ${this.escapeHtml(String(visibleIteration))}</div>`;

            const currentPostLine = state.currentPost
                ? `<div><strong>Current post:</strong> ${this.escapeHtml(state.currentPost)}</div>`
                : '';

            const errorLine = state.errors && state.errors.length
                ? `<div style="color:#d63638;"><strong>Error:</strong> ${this.escapeHtml(state.errors[state.errors.length - 1])}</div>`
                : '';

            const totalSteps = Number(state.totalSteps || 0);
            const completedSteps = Math.min(Number(state.completedSteps || 0), totalSteps || Number(state.completedSteps || 0));
            const progressPercent = totalSteps > 0
                ? Math.max(0, Math.min((completedSteps / totalSteps) * 100, 100))
                : 0;
            const logs = Array.isArray(state.logs) ? state.logs : [];
            const logsHtml = logs.length
                ? logs.map((line) => `<li>${this.escapeHtml(line)}</li>`).join('')
                : `<li>${this.escapeHtml('Waiting to start...')}</li>`;

            $('#assistant-refinement-progress').html(`
                <div class="execution-details">
                    <div class="execution-detail">
                        <span class="value">${this.escapeHtml(String(state.completedPosts || 0))}/${this.escapeHtml(String(state.totalPosts || 0))}</span>
                        <span class="label">Posts Processed</span>
                    </div>
                    <div class="execution-detail">
                        <span class="value">${this.escapeHtml(phaseLabel)}</span>
                        <span class="label">Phase</span>
                    </div>
                    <div class="execution-detail">
                        <span class="value">${this.escapeHtml(String(completedSteps))}/${this.escapeHtml(String(totalSteps || 0))}</span>
                        <span class="label">Steps Completed</span>
                    </div>
                </div>
                ${iterationLine}
                ${currentPostLine}
                ${errorLine}
                <div class="assistant-refine-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${this.escapeHtml(String(Math.round(progressPercent)))}">
                    <div class="assistant-refine-progress-fill" style="width:${progressPercent}%;"></div>
                </div>
                <div class="assistant-refine-progress-label">${this.escapeHtml(progressPercent.toFixed(1))}%</div>
                <div class="assistant-refine-log-wrap">
                    <h5>Execution Log</h5>
                    <ol class="assistant-refine-log">${logsHtml}</ol>
                </div>
            `).show();
        },

        /**
         * Render refinement result payload.
         */
        renderAssistantRefinementResults: function(data) {
            const iterations = Array.isArray(data.iterations) ? data.iterations : [];
            const selectedPosts = Array.isArray(data.selectedPosts) ? data.selectedPosts : [];
            const finalIteration = iterations.length ? iterations[iterations.length - 1] : null;
            const finalAdjustment = finalIteration?.adjustment || {};
            const finalParsed = finalAdjustment.parsed || {};
            const finalIsValidPack = !!finalParsed.is_valid_pack;
            const finalEvaluationRuns = Array.isArray(data.finalEvaluationRuns) ? data.finalEvaluationRuns : [];
            const finalScoredRuns = finalEvaluationRuns.filter((run) => run?.evaluation && run.evaluation.score !== null && run.evaluation.score !== undefined);
            const finalAverageScore = finalScoredRuns.length
                ? (finalScoredRuns.reduce((sum, run) => sum + Number(run.evaluation.score || 0), 0) / finalScoredRuns.length)
                : null;
            const applyVersionOptions = [
                `<option value="initial">Original prompt (before refinement)</option>`,
                ...iterations
                    .filter((round) => round.output_prompt_pack)
                    .map((round) => `<option value="iteration:${this.escapeHtml(String(round.iteration || 0))}" ${round === finalIteration ? 'selected' : ''}>After adjustment ${this.escapeHtml(String(round.iteration || 0))}</option>`)
            ].join('');

            const avgTableRows = iterations.map((round) => {
                const iterationNumber = parseInt(round.iteration || 0, 10);
                const evaluatedPromptLabel = iterationNumber <= 1
                    ? 'Original prompt (before refinement)'
                    : `After adjustment ${iterationNumber - 1}`;
                const producedPromptLabel = round.output_prompt_pack
                    ? `After adjustment ${iterationNumber}`
                    : 'n/a';
                const avg = round.average_score === null || round.average_score === undefined
                    ? 'n/a'
                    : Number(round.average_score).toFixed(2);
                return `
                    <tr>
                        <td>${this.escapeHtml(evaluatedPromptLabel)}</td>
                        <td>${this.escapeHtml(avg)}</td>
                        <td>${this.escapeHtml(String((round.runs || []).length))}</td>
                        <td>${this.escapeHtml(producedPromptLabel)}</td>
                    </tr>
                `;
            }).join('');

            const roundDetailsHtml = iterations.map((round) => {
                const iterationNumber = parseInt(round.iteration || 0, 10);
                const evaluatedPromptLabel = iterationNumber <= 1
                    ? 'Original prompt (before refinement)'
                    : `After adjustment ${iterationNumber - 1}`;
                const producedPromptLabel = round.output_prompt_pack
                    ? `After adjustment ${iterationNumber}`
                    : 'No valid adjusted prompt produced';
                const runs = Array.isArray(round.runs) ? round.runs : [];
                const adjustment = round.adjustment || {};
                const parsed = adjustment.parsed || {};
                const isValidPack = !!parsed.is_valid_pack;
                const combinedPack = isValidPack
                    ? this.formatPromptPackArtifact(parsed, adjustment.adjust_expected_output_schema !== false)
                    : (adjustment.adjuster_response || '');
                const inputPromptPack = this.normalizePromptPack(round.input_prompt_pack || {});
                const outputPromptPack = round.output_prompt_pack ? this.normalizePromptPack(round.output_prompt_pack) : null;

                const runsHtml = runs.map((run, idx) => {
                    const score = run?.evaluation?.score;
                    const feedback = run?.evaluation?.feedback || '';
                    const evaluatorSystemPrompt = run?.evaluation?.rendered_system_prompt || '';
                    const evaluatorPrompt = run?.evaluation?.rendered_prompt || '';
                    const runId = String(run?.run_id || '').trim();
                    const assistantOutput = typeof run?.assistant_output === 'string'
                        ? run.assistant_output
                        : JSON.stringify(run?.assistant_output || {}, null, 2);
                    const finalPostCandidate = run?.final_post_candidate || null;
                    const finalPostCandidateText = finalPostCandidate ? JSON.stringify(finalPostCandidate, null, 2) : '';

                    return `
                        <details class="assistant-test-details">
                            <summary>Post #${idx + 1}: ${this.escapeHtml(run.post_title || `ID ${run.post_id || 0}`)}${score !== null && score !== undefined ? ` | Score: ${this.escapeHtml(String(score))}` : ''}${runId ? ` | Run ID: ${this.escapeHtml(runId)}` : ''}</summary>
                            ${runId ? `<h5>Run ID</h5><pre><code>${this.escapeHtml(runId)}</code></pre>` : ''}
                            <h5>Evaluator Feedback</h5>
                            <pre><code>${this.escapeHtml(feedback)}</code></pre>
                            <div class="assistant-refinement-rendered-prompt-grid">
                                <div>
                                    <h5>Rendered Evaluator System Prompt</h5>
                                    <pre><code>${this.escapeHtml(evaluatorSystemPrompt)}</code></pre>
                                </div>
                                <div>
                                    <h5>Rendered Evaluator User Message</h5>
                                    <pre><code>${this.escapeHtml(evaluatorPrompt)}</code></pre>
                                </div>
                            </div>
                            <h5>Assistant Output</h5>
                            <pre><code>${this.escapeHtml(assistantOutput)}</code></pre>
                            ${finalPostCandidateText ? `<h5>Final Post Candidate</h5><pre><code>${this.escapeHtml(finalPostCandidateText)}</code></pre>` : ''}
                        </details>
                    `;
                }).join('');

                const promptComparisonHtml = outputPromptPack
                    ? `
                        <details class="assistant-test-details" open>
                            <summary>Prompt Diff (Input vs Adjusted)</summary>
                            ${this.renderPromptComparisonBlock('System Prompt', inputPromptPack.system_prompt, outputPromptPack.system_prompt)}
                            ${this.renderPromptComparisonBlock('User Message Template', inputPromptPack.user_message_template, outputPromptPack.user_message_template)}
                            ${this.renderPromptComparisonBlock('Expected Output Schema', inputPromptPack.expected_output_schema, outputPromptPack.expected_output_schema)}
                        </details>
                    `
                    : `
                        <div class="single-content">
                            <h6>Prompt Diff</h6>
                            <div class="comparison-content">No side-by-side diff for this iteration because adjuster output is not a valid 3-part prompt pack.</div>
                        </div>
                    `;

                return `
                    <details class="assistant-test-details" ${round.iteration === iterations.length ? 'open' : ''}>
                        <summary>${this.escapeHtml(evaluatedPromptLabel)} | Avg score: ${this.escapeHtml(round.average_score === null || round.average_score === undefined ? 'n/a' : Number(round.average_score).toFixed(2))} | Produced: ${this.escapeHtml(producedPromptLabel)}</summary>
                        ${runsHtml}
                        ${promptComparisonHtml}

                        <details class="assistant-test-details">
                            <summary>Evaluated Prompt Pack: ${this.escapeHtml(evaluatedPromptLabel)}</summary>
                            <h5>System Prompt</h5>
                            <pre><code>${this.escapeHtml(inputPromptPack.system_prompt || '')}</code></pre>
                            <h5>User Message Template</h5>
                            <pre><code>${this.escapeHtml(inputPromptPack.user_message_template || '')}</code></pre>
                            <h5>Expected Output Schema</h5>
                            <pre><code>${this.escapeHtml(inputPromptPack.expected_output_schema || '')}</code></pre>
                        </details>

                        <details class="assistant-test-details">
                            <summary>Adjustment ${this.escapeHtml(String(iterationNumber))} Output: ${this.escapeHtml(producedPromptLabel)}</summary>
                            ${isValidPack ? '' : '<p style="color:#d63638;"><strong>Adjuster response is not a valid prompt pack. Raw output shown below.</strong></p>'}
                            <h5>System Prompt</h5>
                            <pre><code>${this.escapeHtml(parsed.system_prompt || '')}</code></pre>
                            <h5>User Message Template</h5>
                            <pre><code>${this.escapeHtml(parsed.user_message_template || '')}</code></pre>
                            <h5>Expected Output Schema</h5>
                            <pre><code>${this.escapeHtml(parsed.expected_output_schema || '')}</code></pre>
                            <h5>Prompt Pack JSON</h5>
                            <pre><code>${this.escapeHtml(combinedPack)}</code></pre>
                        </details>
                    </details>
                `;
            }).join('');

            const finalUsage = finalAdjustment.usage && Object.keys(finalAdjustment.usage).length
                ? JSON.stringify(finalAdjustment.usage, null, 2)
                : 'No usage data returned.';
            const finalIncludeSchema = finalAdjustment.adjust_expected_output_schema !== false;
            const initialPromptPack = this.normalizePromptPack(iterations[0]?.input_prompt_pack || {});
            const finalPromptPack = this.normalizePromptPack(finalParsed || {});
            const finalVerificationPromptLabel = finalIteration
                ? `After adjustment ${parseInt(finalIteration.iteration || 0, 10)}`
                : 'latest adjusted prompt';

            const finalCombinedPack = finalIsValidPack
                ? this.formatPromptPackArtifact(finalParsed, finalIncludeSchema)
                : (finalAdjustment.adjuster_response || '');
            const finalVerificationRows = finalEvaluationRuns.map((run, index) => {
                const score = run?.evaluation?.score;
                const runId = String(run?.run_id || '').trim();
                return `
                    <tr>
                        <td>${this.escapeHtml(String(index + 1))}</td>
                        <td>${this.escapeHtml(run.post_title || `ID ${run.post_id || 0}`)}</td>
                        <td>${this.escapeHtml(score === null || score === undefined ? 'n/a' : String(score))}</td>
                        <td>${this.escapeHtml(runId)}</td>
                    </tr>
                `;
            }).join('');

            $('#assistant-refinement-results').html(`
                <div class="test-results success">
                    <h4>Refinement Results (Full Re-eval)</h4>
                    <div class="execution-details">
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(String(iterations.length))}</span>
                            <span class="label">Iterations</span>
                        </div>
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(String(selectedPosts.length))}</span>
                            <span class="label">Posts / Iteration</span>
                        </div>
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(finalAdjustment.provider || 'unknown')}</span>
                            <span class="label">Adjuster Provider</span>
                        </div>
                        <div class="execution-detail">
                            <span class="value">${this.escapeHtml(finalAdjustment.model || 'default')}</span>
                            <span class="label">Adjuster Model</span>
                        </div>
                    </div>

                    <div class="assistant-test-section">
                        <h5>Criteria</h5>
                        <pre><code>${this.escapeHtml(data.criteria || '')}</code></pre>
                    </div>

                    <div class="assistant-test-section">
                        <h5>Primary Purpose</h5>
                        <pre><code>${this.escapeHtml(data.promptObjective || '')}</code></pre>
                    </div>

                    <div class="assistant-test-section assistant-refinement-actions">
                        <h5>Next Actions</h5>
                        <div class="assistant-refinement-actions-row">
                            <label for="assistant-refine-extra-iterations"><strong>Re-evaluate Again</strong></label>
                            <input type="number" id="assistant-refine-extra-iterations" class="small-text" min="1" max="10" step="1" value="1">
                            <button type="button" id="assistant-refine-reeval-btn" class="button">Re-evaluate Again</button>
                            <label for="assistant-refine-apply-version"><strong>Apply Version</strong></label>
                            <select id="assistant-refine-apply-version">${applyVersionOptions}</select>
                            <button type="button" id="assistant-refine-apply-btn" class="button button-primary" ${applyVersionOptions ? '' : 'disabled'}>Apply Selected Prompt Pack</button>
                        </div>
                        <p class="description">
                            Re-evaluate runs additional full iterations on the same selected posts using the latest prompt pack. Apply saves the selected prompt pack version to this assistant.
                        </p>
                    </div>

                    <div class="assistant-test-section">
                        <h5>Final Verification</h5>
                        <p><strong>Evaluated prompt version:</strong> ${this.escapeHtml(finalVerificationPromptLabel)}</p>
                        <p><strong>Average score:</strong> ${this.escapeHtml(finalAverageScore === null ? 'n/a' : finalAverageScore.toFixed(2))}</p>
                        <table class="widefat striped">
                            <thead><tr><th>#</th><th>Post</th><th>Score</th><th>Run ID</th></tr></thead>
                            <tbody>${finalVerificationRows || '<tr><td colspan="4">No final verification data.</td></tr>'}</tbody>
                        </table>
                    </div>

                    <div class="assistant-test-section">
                        <h5>Prompt Version Score Comparison</h5>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Evaluated Prompt Version</th>
                                    <th>Average Score</th>
                                    <th>Posts</th>
                                    <th>Adjustment Produced</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${avgTableRows || '<tr><td colspan="4">No score data.</td></tr>'}
                            </tbody>
                        </table>
                    </div>

                    ${roundDetailsHtml}

                    <details class="assistant-test-details" open>
                        <summary>Final Proposed Prompt Pack (Diff vs Initial)</summary>
                        ${finalIsValidPack ? '' : '<p style="color:#d63638;"><strong>Final adjuster response is not a valid prompt pack. Showing raw output below.</strong></p>'}
                        ${finalIsValidPack ? `
                            ${this.renderPromptComparisonBlock('System Prompt', initialPromptPack.system_prompt, finalPromptPack.system_prompt)}
                            ${this.renderPromptComparisonBlock('User Message Template', initialPromptPack.user_message_template, finalPromptPack.user_message_template)}
                            ${finalIncludeSchema ? this.renderPromptComparisonBlock('Expected Output Schema', initialPromptPack.expected_output_schema, finalPromptPack.expected_output_schema) : ''}
                        ` : ''}
                        <h5>Final Prompt Pack JSON</h5>
                        <pre><code>${this.escapeHtml(finalCombinedPack)}</code></pre>
                    </details>

                    <details class="assistant-test-details">
                        <summary>Final Adjuster Prompts</summary>
                        ${finalAdjustment.adjuster_system_prompt_rendered ? `<h5>Rendered Adjuster System Prompt</h5><pre><code>${this.escapeHtml(finalAdjustment.adjuster_system_prompt_rendered)}</code></pre>` : ''}
                        <h5>Rendered Adjuster User Message</h5>
                        <pre><code>${this.escapeHtml(finalAdjustment.adjuster_prompt_rendered || '')}</code></pre>
                    </details>

                    <details class="assistant-test-details">
                        <summary>Final Adjuster Raw Response</summary>
                        <pre><code>${this.escapeHtml(finalAdjustment.adjuster_response || '')}</code></pre>
                    </details>

                    <details class="assistant-test-details">
                        <summary>Final Adjuster Usage</summary>
                        <pre><code>${this.escapeHtml(finalUsage)}</code></pre>
                    </details>
                </div>
            `).show();
        },

        /**
         * Render refinement error block.
         */
        renderAssistantRefinementError: function(message, partialIterations = []) {
            const partialInfo = Array.isArray(partialIterations) && partialIterations.length
                ? `<p><strong>Completed iterations before failure:</strong> ${this.escapeHtml(String(partialIterations.length))}</p>`
                : '';

            $('#assistant-refinement-results').html(`
                <div class="test-results error">
                    <h4>Refinement - Failed</h4>
                    <div class="step-error-content">${this.escapeHtml(message || 'Refinement failed.')}</div>
                    ${partialInfo}
                </div>
            `).show();
        },

        resolveAssistantPromptPackSelection: function(session) {
            const selection = String($('#assistant-refine-apply-version').val() || 'final');
            if (selection === 'initial') {
                return session.initialBasePromptPack ? this.normalizePromptPack(session.initialBasePromptPack) : null;
            }

            const match = selection.match(/^iteration:(\d+)$/);
            if (match) {
                const iterationNumber = parseInt(match[1], 10);
                const iteration = Array.isArray(session.iterations)
                    ? session.iterations.find((item) => parseInt(item.iteration || 0, 10) === iterationNumber)
                    : null;
                return iteration?.output_prompt_pack ? this.normalizePromptPack(iteration.output_prompt_pack) : null;
            }

            return session.finalPromptPack ? this.normalizePromptPack(session.finalPromptPack) : null;
        },

        /**
         * Normalize prompt pack shape for refinement iterations.
         */
        normalizePromptPack: function(pack) {
            const input = pack || {};
            return {
                system_prompt: String(input.system_prompt || ''),
                user_message_template: String(input.user_message_template || ''),
                expected_output_schema: String(input.expected_output_schema || '{}')
            };
        },

        /**
         * Build compact prompt-version history for the adjuster.
         */
        buildAssistantRefinementHistory: function(iterations) {
            if (!Array.isArray(iterations)) {
                return [];
            }

            return iterations.map((round) => {
                const iterationNumber = parseInt(round?.iteration || 0, 10);
                const runs = Array.isArray(round?.runs) ? round.runs : [];

                return {
                    iteration: iterationNumber,
                    evaluated_prompt_version: iterationNumber <= 1
                        ? 'Original prompt (before refinement)'
                        : `After adjustment ${iterationNumber - 1}`,
                    evaluated_prompt_pack: this.normalizePromptPack(round?.input_prompt_pack || {}),
                    average_score: round?.average_score ?? null,
                    post_scores: runs.map((run) => ({
                        post_id: parseInt(run?.post_id || 0, 10),
                        post_title: String(run?.post_title || ''),
                        score: run?.evaluation?.score ?? null
                    })),
                    produced_prompt_version: round?.output_prompt_pack ? `After adjustment ${iterationNumber}` : null,
                    produced_prompt_pack: round?.output_prompt_pack ? this.normalizePromptPack(round.output_prompt_pack) : null
                };
            });
        },

        formatPromptPackArtifact: function(pack, includeSchema) {
            const normalized = this.normalizePromptPack(pack);
            const artifact = {
                system_prompt: normalized.system_prompt,
                user_message_template: normalized.user_message_template
            };
            if (includeSchema !== false) {
                artifact.expected_output_schema = normalized.expected_output_schema;
            }
            return JSON.stringify(artifact, null, 2);
        },

        /**
         * Render side-by-side prompt comparison block with highlighted changes.
         */
        renderPromptComparisonBlock: function(label, beforeText, afterText) {
            const before = String(beforeText ?? '');
            const after = String(afterText ?? '');
            const diff = this.buildSideBySideLineDiff(before, after);

            return `
                <div class="assistant-prompt-compare-block ${diff.hasChanges ? 'has-changes' : 'no-changes'}">
                    <h5>${this.escapeHtml(label || '')}${diff.hasChanges ? '' : ' (unchanged)'}</h5>
                    <div class="content-comparison assistant-prompt-comparison">
                        <div class="comparison-side before">
                            <h6>Before</h6>
                            <div class="comparison-content assistant-diff-content">${diff.beforeHtml}</div>
                        </div>
                        <div class="comparison-side after">
                            <h6>After</h6>
                            <div class="comparison-content assistant-diff-content">${diff.afterHtml}</div>
                        </div>
                    </div>
                </div>
            `;
        },

        /**
         * Build side-by-side line diff and highlight changed fragments.
         */
        buildSideBySideLineDiff: function(beforeText, afterText) {
            const beforeLines = String(beforeText ?? '').split('\n');
            const afterLines = String(afterText ?? '').split('\n');
            const ops = this.diffLinesLcs(beforeLines, afterLines);

            const beforeRows = [];
            const afterRows = [];
            let hasChanges = false;
            let index = 0;

            const wrapLine = (contentHtml, lineClass = '') =>
                `<div class="assistant-diff-line ${lineClass}">${contentHtml || '&nbsp;'}</div>`;
            const placeholder = `<span class="assistant-diff-placeholder">∅</span>`;

            while (index < ops.length) {
                const op = ops[index];
                if (op.type === 'equal') {
                    const escaped = this.escapeHtml(op.line ?? '');
                    beforeRows.push(wrapLine(escaped, 'equal'));
                    afterRows.push(wrapLine(escaped, 'equal'));
                    index += 1;
                    continue;
                }

                const deleted = [];
                const inserted = [];
                while (index < ops.length && ops[index].type !== 'equal') {
                    if (ops[index].type === 'delete') {
                        deleted.push(String(ops[index].line ?? ''));
                    } else if (ops[index].type === 'insert') {
                        inserted.push(String(ops[index].line ?? ''));
                    }
                    index += 1;
                }

                const maxRows = Math.max(deleted.length, inserted.length);
                for (let row = 0; row < maxRows; row++) {
                    const beforeLine = row < deleted.length ? deleted[row] : null;
                    const afterLine = row < inserted.length ? inserted[row] : null;

                    if (beforeLine !== null && afterLine !== null) {
                        const linePair = this.renderChangedLinePair(beforeLine, afterLine);
                        hasChanges = hasChanges || linePair.changed;
                        beforeRows.push(wrapLine(linePair.beforeHtml, linePair.changed ? 'changed' : 'equal'));
                        afterRows.push(wrapLine(linePair.afterHtml, linePair.changed ? 'changed' : 'equal'));
                        continue;
                    }

                    if (beforeLine !== null) {
                        hasChanges = true;
                        beforeRows.push(
                            wrapLine(`<span class="assistant-diff-remove">${this.escapeHtml(beforeLine)}</span>`, 'changed')
                        );
                        afterRows.push(wrapLine(placeholder, 'placeholder'));
                        continue;
                    }

                    if (afterLine !== null) {
                        hasChanges = true;
                        beforeRows.push(wrapLine(placeholder, 'placeholder'));
                        afterRows.push(
                            wrapLine(`<span class="assistant-diff-add">${this.escapeHtml(afterLine)}</span>`, 'changed')
                        );
                    }
                }
            }

            if (!beforeRows.length && !afterRows.length) {
                beforeRows.push(wrapLine('&nbsp;', 'equal'));
                afterRows.push(wrapLine('&nbsp;', 'equal'));
            }

            return {
                hasChanges,
                beforeHtml: beforeRows.join(''),
                afterHtml: afterRows.join(''),
            };
        },

        /**
         * Line-level LCS diff operations.
         */
        diffLinesLcs: function(beforeLines, afterLines) {
            const a = Array.isArray(beforeLines) ? beforeLines : [];
            const b = Array.isArray(afterLines) ? afterLines : [];
            const n = a.length;
            const m = b.length;
            const matrix = Array.from({ length: n + 1 }, () => Array(m + 1).fill(0));

            for (let i = n - 1; i >= 0; i--) {
                for (let j = m - 1; j >= 0; j--) {
                    if (a[i] === b[j]) {
                        matrix[i][j] = matrix[i + 1][j + 1] + 1;
                    } else {
                        matrix[i][j] = Math.max(matrix[i + 1][j], matrix[i][j + 1]);
                    }
                }
            }

            const ops = [];
            let i = 0;
            let j = 0;
            while (i < n && j < m) {
                if (a[i] === b[j]) {
                    ops.push({ type: 'equal', line: a[i] });
                    i += 1;
                    j += 1;
                } else if (matrix[i + 1][j] >= matrix[i][j + 1]) {
                    ops.push({ type: 'delete', line: a[i] });
                    i += 1;
                } else {
                    ops.push({ type: 'insert', line: b[j] });
                    j += 1;
                }
            }

            while (i < n) {
                ops.push({ type: 'delete', line: a[i] });
                i += 1;
            }
            while (j < m) {
                ops.push({ type: 'insert', line: b[j] });
                j += 1;
            }

            return ops;
        },

        /**
         * Highlight changed fragments in a pair of lines.
         */
        renderChangedLinePair: function(beforeLine, afterLine) {
            const a = String(beforeLine ?? '');
            const b = String(afterLine ?? '');
            if (a === b) {
                return {
                    changed: false,
                    beforeHtml: this.escapeHtml(a),
                    afterHtml: this.escapeHtml(b),
                };
            }

            const minLen = Math.min(a.length, b.length);
            let prefix = 0;
            while (prefix < minLen && a[prefix] === b[prefix]) {
                prefix += 1;
            }

            let suffix = 0;
            const aTailLimit = a.length - prefix;
            const bTailLimit = b.length - prefix;
            while (
                suffix < aTailLimit &&
                suffix < bTailLimit &&
                a[a.length - 1 - suffix] === b[b.length - 1 - suffix]
            ) {
                suffix += 1;
            }

            const aChangedEnd = a.length - suffix;
            const bChangedEnd = b.length - suffix;
            const aPrefix = a.slice(0, prefix);
            const bPrefix = b.slice(0, prefix);
            const aChanged = a.slice(prefix, aChangedEnd);
            const bChanged = b.slice(prefix, bChangedEnd);
            const aSuffix = a.slice(aChangedEnd);
            const bSuffix = b.slice(bChangedEnd);

            const beforeHtml = `${this.escapeHtml(aPrefix)}${aChanged ? `<span class="assistant-diff-remove">${this.escapeHtml(aChanged)}</span>` : ''}${this.escapeHtml(aSuffix)}`;
            const afterHtml = `${this.escapeHtml(bPrefix)}${bChanged ? `<span class="assistant-diff-add">${this.escapeHtml(bChanged)}</span>` : ''}${this.escapeHtml(bSuffix)}`;

            return {
                changed: true,
                beforeHtml,
                afterHtml,
            };
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
