/**
 * Universal Provider Settings JavaScript
 * Works with all providers using data attributes
 * 
 * Usage:
 * - API Key field: <input data-provider="provider_id" data-field="api-key" />
 * - Validate button: <button data-provider="provider_id" data-action="validate-key" />
 * - Model select: <select data-provider="provider_id" data-field="model" />
 * - Refresh button: <button data-provider="provider_id" data-action="refresh-models" />
 * - Toggle visibility: <button data-provider="provider_id" data-action="toggle-visibility" />
 */
(function ($) {
    'use strict';

    var UniversalProviderManager = {
        initialized: false,
        providers: {},

        // Capability payloads keyed by provider, as returned alongside the model list.
        modelCapabilities: {},

        init: function () {
            if (this.initialized) {
                return;
            }

            this.bindEvents();
            this.initializeProviders();
            this.initialized = true;
        },

        /**
         * Bind event handlers using event delegation
         */
        bindEvents: function () {
            // Validate API key
            $(document).on('click', '[data-provider][data-action="validate-key"]', this.validateApiKey.bind(this));

            // Toggle API key visibility
            $(document).on('click', '[data-provider][data-action="toggle-visibility"]', this.toggleApiKeyVisibility.bind(this));

            // Refresh models
            $(document).on('click', '[data-provider][data-action="refresh-models"]', this.refreshModels.bind(this));

            // Load models when provider tab is shown
            $(document).on('click', '.provider-settings-tab', this.onProviderTabClick.bind(this));

            // The chosen model decides whether a reasoning effort applies at all,
            // and which levels that particular model accepts.
            $(document).on('change', '[data-provider][data-field="model"]', this.onModelChange.bind(this));
        },

        /**
         * Handle a model change in a provider tab.
         */
        onModelChange: function (e) {
            var providerId = $(e.currentTarget).data('provider');
            if (providerId) {
                this.syncEffortControl(providerId);
            }
        },

        /**
         * Initialize all providers found on the page
         */
        initializeProviders: function () {
            var self = this;

            // Find all provider sections
            $('[data-provider-id]').each(function () {
                var $section = $(this);
                var providerId = $section.data('provider-id');

                if (!providerId) {
                    return;
                }

                // Initialize provider
                self.providers[providerId] = {
                    initialized: false,
                    modelsLoaded: false
                };

                // Load models if model select exists
                var $modelSelect = $section.find('[data-provider="' + providerId + '"][data-field="model"]');
                if ($modelSelect.length > 0) {
                    // Always ensure "None selected" option exists
                    var $noneOption = $modelSelect.find('option[value=""]');
                    if ($noneOption.length === 0) {
                        // Add "None selected" if it doesn't exist
                        var $emptyOption = $('<option></option>')
                            .attr('value', '')
                            .text(self.i18n('none_selected', 'None selected'));
                        $modelSelect.prepend($emptyOption);
                    }

                    // Check if select already has models (optgroups indicate PHP-rendered models)
                    var hasOptgroups = $modelSelect.find('optgroup').length > 0;

                    // Only auto-load if select doesn't have optgroups (empty or only placeholder)
                    if (!hasOptgroups) {
                        self.loadModels(providerId);
                    } else {
                        // Select already has models - mark as loaded
                        if (self.providers[providerId]) {
                            self.providers[providerId].modelsLoaded = true;
                        }

                        // PHP rendered the options, so no model request was made and no
                        // capability data arrived with it. Fetch it anyway (server-cached),
                        // otherwise switching models could not re-derive the effort levels.
                        var $apiKeyInput = $('[data-provider="' + providerId + '"][data-field="api-key"]');
                        self.fetchModels(
                            providerId,
                            $apiKeyInput.length ? $apiKeyInput.val() : '',
                            $modelSelect.val() || '',
                            function () {
                                self.syncEffortControl(providerId);
                            }
                        );
                    }
                }
            });
        },

        /**
         * Handle provider tab click - load models if not already loaded
         */
        onProviderTabClick: function (e) {
            var $tab = $(e.currentTarget);
            var providerId = $tab.attr('id').replace('-tab', '');

            // Load models if not already loaded
            if (this.providers[providerId] && !this.providers[providerId].modelsLoaded) {
                this.loadModels(providerId);
            }
        },

        /**
         * Validate API key for a provider
         */
        validateApiKey: function (e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var providerId = $button.data('provider');

            if (!providerId) {
                console.error('Provider ID not found');
                return;
            }

            var $input = $('[data-provider="' + providerId + '"][data-field="api-key"]');
            var $message = $('[data-provider="' + providerId + '"][data-field="validation-message"]');

            if ($input.length === 0) {
                console.error('API key input not found for provider: ' + providerId);
                return;
            }

            var apiKey = $input.val().trim();

            if (!apiKey) {
                this.showMessage($message, 'error', this.i18n('please_enter_api_key', 'Please enter an API key'));
                return;
            }

            var originalText = $button.text();
            $button.prop('disabled', true).text(this.i18n('validating', 'Validating...'));
            $message.empty();

            var ajaxUrl = this.getAjaxUrl();
            var nonce = this.getNonce();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_validate_provider_key',
                    provider_id: providerId,
                    api_key: apiKey,
                    nonce: nonce
                },
                success: function (response) {
                    if (response.success) {
                        this.showMessage($message, 'success', response.data || this.i18n('api_key_valid', 'API key is valid!'));
                    } else {
                        this.showMessage($message, 'error', response.data || this.i18n('api_key_invalid', 'Invalid API key'));
                    }
                }.bind(this),
                error: function () {
                    this.showMessage($message, 'error', this.i18n('validation_failed', 'Failed to validate API key. Please try again.'));
                }.bind(this),
                complete: function () {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Toggle API key visibility
         */
        toggleApiKeyVisibility: function (e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var providerId = $button.data('provider');

            if (!providerId) {
                console.error('Provider ID not found');
                return;
            }

            var $input = $('[data-provider="' + providerId + '"][data-field="api-key"]');

            if ($input.length === 0) {
                console.error('API key input not found for provider: ' + providerId);
                return;
            }

            var currentType = $input.attr('type');

            if (currentType === 'password') {
                $input.attr('type', 'text');
                $button.text('🔒');
            } else {
                $input.attr('type', 'password');
                $button.text('👁');
            }
        },

        /**
         * Load models for a provider
         */
        loadModels: function (providerId) {
            var $select = $('[data-provider="' + providerId + '"][data-field="model"]');

            if ($select.length === 0) {
                return; // No model select for this provider
            }

            // Check if select already has models - if so, skip loading
            var hasOptgroups = $select.find('optgroup').length > 0;
            var modelCount = 0;
            $select.find('option').each(function () {
                var $option = $(this);
                var value = $option.val();
                var text = $option.text().trim();
                if (value !== '' && text !== 'Loading models...' && text !== 'None selected') {
                    modelCount++;
                }
            });

            // If select already has models, don't load again
            if (hasOptgroups || modelCount > 0) {
                if (typeof console !== 'undefined' && console.debug) {
                    console.debug('[TreeTank] Skipping loadModels for ' + providerId + ' - already has models (hasOptgroups: ' + hasOptgroups + ', modelCount: ' + modelCount + ')');
                }
                if (this.providers[providerId]) {
                    this.providers[providerId].modelsLoaded = true;
                }
                return;
            }

            // Get selected model - prioritize data attribute over current value
            // This ensures we preserve the server-rendered selection
            var selectedModel = $select.data('selected-model') || $select.val() || '';

            // Get API key
            var $apiKeyInput = $('[data-provider="' + providerId + '"][data-field="api-key"]');
            var apiKey = $apiKeyInput.length > 0 ? $apiKeyInput.val() : '';

            // Fetch models
            this.fetchModels(providerId, apiKey, selectedModel, function (models) {
                this.updateModelSelect($select, models, selectedModel);
                if (this.providers[providerId]) {
                    this.providers[providerId].modelsLoaded = true;
                }
            }.bind(this));
        },

        /**
         * Fetch models from API
         */
        fetchModels: function (providerId, apiKey, selectedModel, callback, forceRefresh) {
            var ajaxUrl = this.getAjaxUrl();
            var nonce = this.getNonce();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'polytrans_get_provider_models',
                    provider_id: providerId,
                    selected_model: selectedModel || '',
                    force_refresh: forceRefresh ? '1' : '0',
                    nonce: nonce
                },
                success: function (response) {
                    if (response.success && response.data && response.data.capabilities) {
                        this.modelCapabilities[providerId] = response.data.capabilities;
                    }
                    if (response.success && response.data && response.data.models) {
                        callback(response.data.models);
                    } else {
                        // Fallback to empty or default models
                        callback({});
                    }
                }.bind(this),
                error: function () {
                    // Fallback to empty models on error
                    callback({});
                }.bind(this)
            });
        },

        /**
         * Update model select dropdown
         */
        updateModelSelect: function ($select, groupedModels, selectedModel) {
            if (!$select.length) {
                return;
            }

            // Normalize selectedModel - ensure it's a string
            selectedModel = selectedModel || '';

            // Clear all existing options
            $select.empty();

            // Always add empty "None selected" option first
            var $emptyOption = $('<option></option>')
                .attr('value', '')
                .text(this.i18n('none_selected', 'None selected'));

            $select.append($emptyOption);

            // Track if we found and selected a model
            var modelSelected = false;

            // Check if we have models
            if (groupedModels && Object.keys(groupedModels).length > 0) {
                // Add models grouped by category
                for (var groupName in groupedModels) {
                    if (!groupedModels.hasOwnProperty(groupName)) {
                        continue;
                    }

                    var $optgroup = $('<optgroup></optgroup>').attr('label', groupName);
                    var models = groupedModels[groupName];

                    for (var modelId in models) {
                        if (!models.hasOwnProperty(modelId)) {
                            continue;
                        }

                        var modelLabel = models[modelId];
                        var $option = $('<option></option>')
                            .attr('value', modelId)
                            .text(modelLabel);

                        // Select if matches selectedModel (exact match)
                        if (selectedModel && modelId === selectedModel) {
                            $option.prop('selected', true);
                            modelSelected = true;
                        }

                        $optgroup.append($option);
                    }

                    $select.append($optgroup);
                }
            }

            // Select "None selected" if no model was selected or selectedModel is empty
            if (!modelSelected || !selectedModel) {
                $emptyOption.prop('selected', true);
            }

            // Update data attribute to reflect current selection
            var currentValue = $select.val() || '';
            $select.data('selected-model', currentValue);

            var providerId = $select.data('provider');
            if (providerId) {
                this.syncEffortControl(providerId);
            }
        },

        /**
         * Resolve the capability profile for a provider's currently selected model.
         *
         * Returns null when there is no capability data yet (models still loading)
         * or the provider is unknown to the capability layer.
         */
        getModelProfile: function (providerId, model) {
            var capabilities = this.modelCapabilities[providerId];
            if (!capabilities || !capabilities.profiles) {
                return null;
            }

            var normalized = String(model || '').toLowerCase().replace(/^models\//, '');
            var profileId = (capabilities.models || {})[normalized] || capabilities.fallback;

            return capabilities.profiles[profileId] || null;
        },

        /**
         * Show the reasoning effort selector only for models that accept one, with
         * that model's own levels. A previously saved level survives a model switch
         * when the new model also supports it, and is otherwise reset to the
         * provider default rather than silently sending an invalid value.
         */
        syncEffortControl: function (providerId) {
            var $row = $('[data-provider="' + providerId + '"][data-field="reasoning-effort-row"]');
            var $select = $('[data-provider="' + providerId + '"][data-field="reasoning-effort"]');

            if (!$row.length || !$select.length) {
                return;
            }

            // Without capability data there is nothing better than what the server
            // already rendered - leave the row alone rather than hiding a control
            // the model may well support.
            if (!this.modelCapabilities[providerId]) {
                return;
            }

            var $modelSelect = $('[data-provider="' + providerId + '"][data-field="model"]');
            var model = $modelSelect.val() || '';
            var profile = this.getModelProfile(providerId, model);
            var levels = (profile && profile.reasoning && Array.isArray(profile.reasoning.levels))
                ? profile.reasoning.levels
                : [];

            if (!levels.length) {
                $row.hide();
                return;
            }

            var previous = $select.val() || '';
            var available = levels.map(function (level) { return level.value; });
            var selected = available.indexOf(previous) !== -1 ? previous : '';

            $select.empty();
            $select.append($('<option></option>')
                .attr('value', '')
                .text(this.i18n('effort_provider_default', 'Provider default')));

            levels.forEach(function (level) {
                $select.append($('<option></option>')
                    .attr('value', level.value)
                    .text(level.label));
            });

            $select.val(selected);

            var $description = $('[data-provider="' + providerId + '"][data-field="reasoning-effort-description"]');
            if ($description.length) {
                // Rendered as a data attribute so appending a note never compounds:
                // the visible text already contains the note of the previous model.
                var base = $description.data('base-description') || '';
                var note = (profile.reasoning && profile.reasoning.note) || '';
                $description.text([base, note].filter(Boolean).join(' '));
            }

            $row.show();
        },

        /**
         * Refresh models for a provider
         */
        refreshModels: function (e) {
            e.preventDefault();

            var $button = $(e.currentTarget);
            var providerId = $button.data('provider');

            if (!providerId) {
                console.error('Provider ID not found');
                return;
            }

            var $select = $('[data-provider="' + providerId + '"][data-field="model"]');
            var $message = $('[data-provider="' + providerId + '"][data-field="model-message"]');

            if ($select.length === 0) {
                return;
            }

            // Get selected model - prioritize data attribute over current value
            var selectedModel = $select.data('selected-model') || $select.val() || '';
            var $apiKeyInput = $('[data-provider="' + providerId + '"][data-field="api-key"]');
            var apiKey = $apiKeyInput.length > 0 ? $apiKeyInput.val() : '';

            var originalText = $button.text();
            $button.prop('disabled', true).text(this.i18n('refreshing', 'Refreshing...'));

            // Force refresh when user clicks refresh button (clear cache)
            this.fetchModels(providerId, apiKey, selectedModel, function (models) {
                this.updateModelSelect($select, models, selectedModel);
                $button.prop('disabled', false).text(originalText);

                // Show success message
                if ($message.length > 0) {
                    this.showMessage($message, 'success', this.i18n('models_refreshed', 'Models refreshed'));
                }

                if (this.providers[providerId]) {
                    this.providers[providerId].modelsLoaded = true;
                }
            }.bind(this), true); // Pass true to force refresh (clear cache)
        },

        /**
         * Show message in container
         */
        showMessage: function ($container, type, message) {
            if (!$container || !$container.length) {
                return;
            }

            var className = type === 'success' ? 'notice-success' : 'notice-error';
            var dismissText = this.i18n('dismiss_notice', 'Dismiss this notice');
            var html = '<div class="notice ' + className + ' is-dismissible inline"><p>' +
                this.escapeHtml(message) +
                '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">' +
                dismissText +
                '</span></button></div>';

            $container.html(html);

            // Initialize dismiss functionality
            $container.find('.notice-dismiss').on('click', function (e) {
                e.preventDefault();
                $(this).closest('.notice').fadeOut(function () {
                    $(this).remove();
                });
            });
        },

        /**
         * Get AJAX URL
         */
        getAjaxUrl: function () {
            if (typeof PolyTransAjax !== 'undefined' && PolyTransAjax.ajaxurl) {
                return PolyTransAjax.ajaxurl;
            }
            if (typeof ajaxurl !== 'undefined') {
                return ajaxurl;
            }
            return null;
        },

        /**
         * Get nonce
         */
        getNonce: function () {
            // Try settings nonce first
            if (typeof PolyTransAjax !== 'undefined' && PolyTransAjax.nonce) {
                return PolyTransAjax.nonce;
            }
            // Try OpenAI nonce (for backward compatibility)
            if (typeof PolyTransAjax !== 'undefined' && PolyTransAjax.openai_nonce) {
                return PolyTransAjax.openai_nonce;
            }
            // Fallback to form nonce
            var $nonceInput = $('input[name="_wpnonce"]');
            if ($nonceInput.length > 0) {
                return $nonceInput.val();
            }
            return '';
        },

        /**
         * Internationalization helper
         */
        i18n: function (key, defaultValue) {
            if (typeof PolyTransAjax !== 'undefined' &&
                PolyTransAjax.i18n &&
                PolyTransAjax.i18n[key]) {
                return PolyTransAjax.i18n[key];
            }
            // Fallback to window object if available
            if (typeof window.polytransI18n !== 'undefined' &&
                window.polytransI18n[key]) {
                return window.polytransI18n[key];
            }
            return defaultValue;
        },

        /**
         * Escape HTML
         */
        escapeHtml: function (text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function (m) { return map[m]; });
        }
    };

    // Make globally accessible
    window.UniversalProviderManager = UniversalProviderManager;

    // Initialize on document ready
    $(function () {
        UniversalProviderManager.init();
    });

})(jQuery);

