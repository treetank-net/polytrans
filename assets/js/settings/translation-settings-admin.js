(function ($) {
    $(function () {
        // Tab functionality
        function initTabs() {
            $('.nav-tab').on('click', function (e) {
                e.preventDefault();

                // Remove active class from all tabs and content
                $('.nav-tab').removeClass('nav-tab-active');
                $('.tab-content').removeClass('active').hide();

                // Add active class to clicked tab
                $(this).addClass('nav-tab-active');

                // Show corresponding content
                var targetId = $(this).attr('href');
                $(targetId).addClass('active').show();

                // Trigger Language Paths filtering when switching to Language Paths tab
                if (targetId === '#language-paths-settings') {
                    if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
                        window.PolyTransLanguagePaths.updateLanguagePairVisibility();
                    }
                    // Load assistants when Language Paths tab is shown
                    if (window.PolyTransAssistants && window.PolyTransAssistants.loadAssistants) {
                        window.PolyTransAssistants.loadAssistants();
                    }
                }

                // Store active tab in localStorage
                try {
                    localStorage.setItem('polytrans-active-tab', targetId);
                } catch (e) { }
            });

            // Restore active tab from localStorage
            try {
                var activeTab = localStorage.getItem('polytrans-active-tab');
                if (activeTab && $(activeTab).length) {
                    $('.nav-tab[href="' + activeTab + '"]').trigger('click');
                }
            } catch (e) { }
        }

        function updateLangConfigVisibility() {
            var allowed = [];
            $('input[name="allowed_targets[]"]:checked').each(function () {
                allowed.push($(this).val());
            });
            $('#translation-settings-table tbody tr').each(function () {
                var lang = $(this).data('lang');
                if (allowed.indexOf(lang) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }

        // Add data-lang to each row for easy lookup (use value from input[name^='status'] for robustness)
        $('#translation-settings-table tbody tr').each(function () {
            var lang = $(this).find('select[name^="status["]').attr('name');
            if (lang) {
                lang = lang.match(/status\[(.+)\]/);
                if (lang && lang[1]) {
                    $(this).attr('data-lang', lang[1]);
                }
            }
        });

        $('input[name="allowed_targets[]"]').on('change', function () {
            updateLangConfigVisibility();
            // Trigger Language Paths filtering
            if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
                window.PolyTransLanguagePaths.updateLanguagePairVisibility();
            }
        });

        $('input[name="allowed_sources[]"]').on('change', function () {
            // Trigger Language Paths filtering
            if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
                window.PolyTransLanguagePaths.updateLanguagePairVisibility();
            }
        });

        updateLangConfigVisibility();

        // Language Paths filtering based on path rules
        window.PolyTransLanguagePaths = {
            updateLanguagePairVisibility: function () {
                // Only run if we're on Language Paths tab
                if (!$('#language-paths-settings').is(':visible')) {
                    return;
                }

                // 1. Get allowed source/target languages
                var allowedSources = [];
                var allowedTargets = [];
                $('input[name="allowed_sources[]"]:checked').each(function () {
                    allowedSources.push($(this).val());
                });
                $('input[name="allowed_targets[]"]:checked').each(function () {
                    allowedTargets.push($(this).val());
                });

                // If no allowed sources/targets, show all pairs (fallback)
                if (allowedSources.length === 0 || allowedTargets.length === 0) {
                    $('.language-pair-row').show();
                    $('.no-pairs-message').remove();
                    return;
                }

                // 2. Get all rules from the DOM
                var rules = [];
                $('#path-rules-container .path-rule-row, #path-rules-table .path-rule-row').each(function (i) {
                    var $rule = $(this);
                    var source = $rule.find('.path-rule-source').val();
                    var target = $rule.find('.path-rule-target').val();
                    var intermediate = $rule.find('.path-rule-intermediate').val() || '';
                    if (source && target) {
                        rules.push({
                            source: source,
                            target: target,
                            intermediate: intermediate === '' ? 'none' : intermediate,
                            index: i
                        });
                    }
                });

                // 3. If no rules, show all pairs (backward compatibility)
                if (rules.length === 0) {
                    $('.language-pair-row').show();
                    $('.no-pairs-message').remove();
                    return;
                }

                // 4. Expand all rules into all possible pairs
                var pairToRules = {};
                rules.forEach(function (rule) {
                    var sources = (rule.source === 'all') ? allowedSources : [rule.source];
                    var targets = (rule.target === 'all') ? allowedTargets : [rule.target];
                    sources.forEach(function (src) {
                        targets.forEach(function (tgt) {
                            if (src === tgt) return;
                            if (rule.intermediate === 'none' || rule.intermediate === '') {
                                // Direct pair
                                var key = src + '_to_' + tgt;
                                if (!pairToRules[key]) pairToRules[key] = [];
                                pairToRules[key].push({
                                    type: 'direct',
                                    rule: rule
                                });
                            } else {
                                // Via intermediate: src->inter and inter->tgt
                                // Only create pairs if source/target are different from intermediate
                                if (src !== rule.intermediate) {
                                    var key1 = src + '_to_' + rule.intermediate;
                                    if (!pairToRules[key1]) pairToRules[key1] = [];
                                    pairToRules[key1].push({
                                        type: 'via',
                                        rule: rule,
                                        inter: rule.intermediate
                                    });
                                }
                                if (rule.intermediate !== tgt) {
                                    var key2 = rule.intermediate + '_to_' + tgt;
                                    if (!pairToRules[key2]) pairToRules[key2] = [];
                                    pairToRules[key2].push({
                                        type: 'via',
                                        rule: rule,
                                        inter: rule.intermediate
                                    });
                                }
                            }
                        });
                    });
                });

                // 5. Show/hide language pair rows
                var visiblePairs = 0;
                $('.language-pair-row').each(function () {
                    var $row = $(this);
                    var pairKey = $row.data('pair');
                    var matchingRules = pairToRules[pairKey] || [];

                    if (matchingRules.length > 0) {
                        $row.show();
                        visiblePairs++;
                    } else {
                        $row.hide();
                    }
                });

                // Show message if no pairs visible
                var $table = $('.language-pair-row').closest('table');
                var $noPairsMsg = $table.find('.no-pairs-message');
                if (visiblePairs === 0 && $('.language-pair-row').length > 0) {
                    if ($noPairsMsg.length === 0) {
                        $table.find('tbody').prepend(
                            '<tr class="no-pairs-message"><td colspan="2" style="text-align: center; padding: 20px; color: #666;"><em>' +
                            'No language pairs match the current path rules. Adjust your rules or add a rule that covers the pairs you need.' +
                            '</em></td></tr>'
                        );
                    }
                } else {
                    $noPairsMsg.remove();
                }
            }
        };

        // Assistant loading for Language Paths tab
        window.PolyTransAssistants = {
            assistants: [],
            assistantsLoaded: false,

            loadAssistants: function () {
                // Only load if we're on Language Paths tab and assistants not already loaded
                if (!$('#language-paths-settings').is(':visible')) {
                    return;
                }

                if (this.assistantsLoaded) {
                    this.populateAssistantSelects();
                    return;
                }

                var $loading = $('#assistants-loading');
                var $error = $('#assistants-error');

                $loading.show();
                $error.hide();

                var apiKey = $('#openai-api-key').val() || '';

                // Get ajax_url from multiple possible sources
                var ajaxUrl = null;
                if (typeof polytrans_openai !== 'undefined' && polytrans_openai.ajax_url) {
                    ajaxUrl = polytrans_openai.ajax_url;
                } else if (typeof PolyTransAjax !== 'undefined' && PolyTransAjax.ajaxurl) {
                    ajaxUrl = PolyTransAjax.ajaxurl;
                } else if (typeof ajaxurl !== 'undefined') {
                    ajaxUrl = ajaxurl;
                } else {
                    ajaxUrl = admin_url('admin-ajax.php'); // WordPress global
                }

                // Get nonce - OpenAI uses 'polytrans_openai_nonce'
                var nonce = null;
                if (typeof polytrans_openai !== 'undefined' && polytrans_openai.nonce) {
                    nonce = polytrans_openai.nonce;
                } else if (typeof PolyTransAjax !== 'undefined' && PolyTransAjax.openai_nonce) {
                    nonce = PolyTransAjax.openai_nonce;
                } else {
                    // Fallback: try form nonce (may not work for OpenAI endpoint)
                    var $nonceInput = $('input[name="_wpnonce"]');
                    if ($nonceInput.length) {
                        nonce = $nonceInput.val();
                    }
                }

                if (!ajaxUrl) {
                    console.error('ajaxurl is not available');
                    $error.show().find('p').text('AJAX URL not available');
                    $loading.hide();
                    return;
                }

                var self = this;
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'polytrans_load_assistants',
                        api_key: apiKey,
                        nonce: nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            self.assistants = response.data;
                            self.assistantsLoaded = true;
                            self.populateAssistantSelects();
                        } else {
                            console.error('Assistant loading failed:', response.data);
                            $error.show().find('p').text(response.data || 'Unknown error');
                            self.handleAssistantLoadingError(response.data || 'Failed to load assistants');
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error('AJAX error loading assistants:', {
                            xhr: xhr,
                            status: status,
                            error: error,
                            responseText: xhr.responseText
                        });
                        $error.show().find('p').text('Network error: ' + error);
                        self.handleAssistantLoadingError('Network error: ' + error);
                    },
                    complete: function () {
                        $loading.hide();
                    }
                });
            },

            populateAssistantSelects: function () {
                var groupedAssistants = this.assistants || {};

                // Use a slight delay to ensure DOM is ready
                var self = this;
                setTimeout(function () {
                    var $selects = $('.openai-assistant-select');

                    if ($selects.length === 0) {
                        return;
                    }

                    $selects.each(function () {
                        var $select = $(this);
                        var pairKey = $select.data('pair');
                        var currentValue = $select.data('selected') || '';
                        var $hiddenInput = $('.openai-assistant-hidden[data-pair="' + pairKey + '"]');

                        // Clear existing options
                        $select.empty();
                        $select.append($('<option></option>')
                            .attr('value', '')
                            .text('No provider/assistant selected'));

                        // Add Translation Providers group
                        if (groupedAssistants.providers && groupedAssistants.providers.length > 0) {
                            var $providersGroup = $('<optgroup label="Translation Providers"></optgroup>');
                            groupedAssistants.providers.forEach(function (provider) {
                                var option = $('<option></option>')
                                    .attr('value', provider.id)
                                    .text(provider.name + (provider.description ? ' - ' + provider.description : ''));
                                $providersGroup.append(option);
                            });
                            $select.append($providersGroup);
                        }

                        // Add Managed Assistants group
                        if (groupedAssistants.managed && groupedAssistants.managed.length > 0) {
                            var $managedGroup = $('<optgroup label="Managed Assistants"></optgroup>');
                            groupedAssistants.managed.forEach(function (assistant) {
                                var option = $('<option></option>')
                                    .attr('value', assistant.id)
                                    .text(assistant.name + ' (' + assistant.model + ')');
                                $managedGroup.append(option);
                            });
                            $select.append($managedGroup);
                        }

                        // Add OpenAI API Assistants group
                        if (groupedAssistants.openai && groupedAssistants.openai.length > 0) {
                            var $openaiGroup = $('<optgroup label="OpenAI API Assistants"></optgroup>');
                            groupedAssistants.openai.forEach(function (assistant) {
                                var option = $('<option></option>')
                                    .attr('value', assistant.id)
                                    .text(assistant.name + ' (' + assistant.model + ')');
                                $openaiGroup.append(option);
                            });
                            $select.append($openaiGroup);
                        }

                        // Add Claude Projects group (future)
                        if (groupedAssistants.claude && groupedAssistants.claude.length > 0) {
                            var $claudeGroup = $('<optgroup label="Claude Projects"></optgroup>');
                            groupedAssistants.claude.forEach(function (assistant) {
                                var option = $('<option></option>')
                                    .attr('value', assistant.id)
                                    .text(assistant.name + ' (' + assistant.model + ')');
                                $claudeGroup.append(option);
                            });
                            $select.append($claudeGroup);
                        }

                        // Add Gemini Tuned Models group (future)
                        if (groupedAssistants.gemini && groupedAssistants.gemini.length > 0) {
                            var $geminiGroup = $('<optgroup label="Gemini Tuned Models"></optgroup>');
                            groupedAssistants.gemini.forEach(function (assistant) {
                                var option = $('<option></option>')
                                    .attr('value', assistant.id)
                                    .text(assistant.name + ' (' + assistant.model + ')');
                                $geminiGroup.append(option);
                            });
                            $select.append($geminiGroup);
                        }

                        // Set the selected value
                        if (currentValue) {
                            $select.val(currentValue);
                        }

                        // Handle selection changes - update the hidden input
                        $select.off('change.assistants').on('change.assistants', function () {
                            var newValue = $(this).val();
                            if ($hiddenInput.length) {
                                $hiddenInput.val(newValue);
                            }
                        });
                    });
                }, 100);
            },

            handleAssistantLoadingError: function (errorMessage) {
                $('.openai-assistant-select').each(function () {
                    var $select = $(this);
                    var pairKey = $select.data('pair');
                    var currentValue = $select.data('selected') || '';
                    var $hiddenInput = $('.openai-assistant-hidden[data-pair="' + pairKey + '"]');

                    // Clear and show error option
                    $select.empty();
                    $select.append($('<option></option>')
                        .attr('value', '')
                        .text('⚠ Failed to load assistants'));

                    // If there was a current value, add it as an option so it's preserved
                    if (currentValue) {
                        $select.append($('<option></option>')
                            .attr('value', currentValue)
                            .text('Current: ' + currentValue)
                            .prop('selected', true));
                    }
                });
            }
        };

        // Initialize tabs
        initTabs();

        // Load assistants if Language Paths tab is already visible on page load
        if ($('#language-paths-settings').is(':visible')) {
            window.PolyTransAssistants.loadAssistants();
        }

        // Secret generator button logic (handles multiple buttons via data-target)
        function randomSecret(length) {
            var array = new Uint8Array(length);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(array);
            } else {
                for (var i = 0; i < length; i++) array[i] = Math.floor(Math.random() * 256);
            }
            return btoa(String.fromCharCode.apply(null, array)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '').substring(0, length);
        }

        $('.generate-secret-btn').each(function() {
            var btn = this;
            var targetId = $(btn).data('target');
            var input = document.getElementById(targetId);
            if (!input) return;

            var initialSecret = input.getAttribute('data-initial') || '';
            var confirmOverwrite = initialSecret.trim() !== '';

            $(btn).on('click', function(e) {
                e.preventDefault();
                // Only ask once, and only if there was a value set before entering the form
                if (confirmOverwrite && input.value && input.value.trim() !== '') {
                    if (!window.confirm('A secret was already set before opening this page. Do you want to overwrite it with a new one?')) {
                        return;
                    }
                    confirmOverwrite = false; // Only ask once
                }
                input.value = randomSecret(32);
                input.focus();
            });
        });

        // Toggle secret visibility (show/hide)
        $('.toggle-secret-btn').each(function() {
            var btn = $(this);
            var targetId = btn.data('target');
            var input = document.getElementById(targetId);
            if (!input) return;

            btn.on('click', function(e) {
                e.preventDefault();
                if (input.type === 'password') {
                    input.type = 'text';
                    btn.text(polytransSettings.i18n?.hide || 'Hide');
                } else {
                    input.type = 'password';
                    btn.text(polytransSettings.i18n?.show || 'Show');
                }
            });
        });

        // Handle secret method changes to show/hide custom header field (receiver)
        $('select[name="translation_receiver_secret_method"]').on('change', function() {
            var selectedMethod = $(this).val();
            var customHeaderSection = $('#receiver-custom-header-section');

            if (selectedMethod === 'header_custom') {
                customHeaderSection.show();
            } else {
                customHeaderSection.hide();
            }
        });

        // Handle secret method changes to show/hide custom header field (endpoint)
        $('select[name="translation_endpoint_secret_method"]').on('change', function() {
            var selectedMethod = $(this).val();
            var customHeaderSection = $('#endpoint-custom-header-section');

            if (selectedMethod === 'header_custom') {
                customHeaderSection.show();
            } else {
                customHeaderSection.hide();
            }
        });

        // Handle transport mode changes to show/hide external settings
        $('select[name="translation_transport_mode"]').on('change', function() {
            var selectedMode = $(this).val();
            var externalSettings = $('#external-mode-settings');
            var internalWarning = $('#internal-mode-warning');

            if (selectedMode === 'external') {
                externalSettings.show();
                internalWarning.hide();
            } else {
                externalSettings.hide();
                internalWarning.show();
            }
        });

        // Handle server role changes to show/hide translation-only warning and virtual workflows
        $('select[name="translation_server_role"]').on('change', function() {
            var selectedRole = $(this).val();
            var translationOnlyWarning = $('#translation-only-warning');
            var virtualWorkflowsSection = $('#virtual-workflows-section');

            if (selectedRole === 'translation_only') {
                translationOnlyWarning.show();
                virtualWorkflowsSection.show();
            } else {
                translationOnlyWarning.hide();
                virtualWorkflowsSection.hide();
            }
        });

        // Handle dispatch mode changes to show/hide cleanup option
        $('select[name="outgoing_translation_dispatch_mode"]').on('change', function() {
            var selectedMode = $(this).val();
            var cleanupSection = $('#after-workflows-cleanup-section');

            if (selectedMode === 'after_workflows') {
                cleanupSection.show();
            } else {
                cleanupSection.hide();
            }
            updateNoWorkflowsWarning();
        });

        // Handle workflow mode and database changes for warning
        $('select[name="received_translation_workflow_mode"], select[name="external_same_database"]').on('change', function() {
            updateNoWorkflowsWarning();
        });

        // Function to show/hide "no workflows" warning
        function updateNoWorkflowsWarning() {
            var dispatchMode = $('select[name="outgoing_translation_dispatch_mode"]').val();
            var workflowMode = $('select[name="received_translation_workflow_mode"]').val();
            var sameDatabase = $('select[name="external_same_database"]').val();
            var warningDiv = $('#no-workflows-warning');

            // Show warning when: immediate + skip_workflows + same_database
            if (dispatchMode === 'immediate' && workflowMode === 'skip_workflows' && sameDatabase === 'yes') {
                warningDiv.show();
            } else {
                warningDiv.hide();
            }
        }

        // Initial check on page load
        updateNoWorkflowsWarning();

        // Path Rules Management
        // Function to update visual representation of path rule
        function updatePathRuleVisual($row) {
            var source = $row.find('.path-rule-source').val();
            var target = $row.find('.path-rule-target').val();
            var intermediate = $row.find('.path-rule-intermediate').val() || '';
            
            var sourceText = source === 'all' ? PolyTransAjax.i18n.all || 'All' : $row.find('.path-rule-source option:selected').text();
            var targetText = target === 'all' ? PolyTransAjax.i18n.all || 'All' : $row.find('.path-rule-target option:selected').text();
            var intermediateText = intermediate === '' ? (PolyTransAjax.i18n.none_direct || 'None (Direct)') : $row.find('.path-rule-intermediate option:selected').text();
            
            var $visual = $row.find('.path-rule-visual');
            if (intermediate === '') {
                $visual.html('<span class="path-rule-direct">' + sourceText + ' → ' + targetText + '</span>');
            } else {
                $visual.html(
                    '<span>' + sourceText + '</span>' +
                    '<span class="path-rule-arrow">→</span>' +
                    '<span class="path-rule-intermediate">' + intermediateText + '</span>' +
                    '<span class="path-rule-arrow">→</span>' +
                    '<span>' + targetText + '</span>'
                );
            }
        }
        
        // Update visual for existing rules on change
        $(document).on('change', '.path-rule-source, .path-rule-target, .path-rule-intermediate', function() {
            var $row = $(this).closest('.path-rule-row');
            updatePathRuleVisual($row);
        });
        
        // Initialize visuals for existing rules
        $('.path-rule-row').each(function() {
            updatePathRuleVisual($(this));
        });
        
        // Add path rule
        $('#add-path-rule').on('click', function() {
            var ruleIndex = $('#path-rules-container tbody tr, #path-rules-table tbody tr').length;
            var langs = window.polytransLanguages || {};
            var langOptions = '';
            for (var langCode in langs) {
                langOptions += '<option value="' + langCode + '">' + langs[langCode] + '</option>';
            }
            
            var newRow = $('<tr class="path-rule-row">' +
                '<td>' +
                    '<select name="openai_path_rules[' + ruleIndex + '][source]" class="path-rule-source path-rule-select" required>' +
                        '<option value="all">' + (PolyTransAjax.i18n.all || 'All') + '</option>' +
                        langOptions +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<select name="openai_path_rules[' + ruleIndex + '][target]" class="path-rule-target path-rule-select" required>' +
                        '<option value="all">' + (PolyTransAjax.i18n.all || 'All') + '</option>' +
                        langOptions +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<select name="openai_path_rules[' + ruleIndex + '][intermediate]" class="path-rule-intermediate path-rule-select">' +
                        '<option value="">' + (PolyTransAjax.i18n.none_direct || 'None (Direct)') + '</option>' +
                        langOptions +
                    '</select>' +
                    '<div class="path-rule-visual"></div>' +
                '</td>' +
                '<td>' +
                    '<button type="button" class="button remove-rule">' + (PolyTransAjax.i18n.remove || 'Remove') + '</button>' +
                '</td>' +
            '</tr>');
            
            var $container = $('#path-rules-container tbody, #path-rules-table tbody');
            if ($container.length === 0) {
                // Create tbody if it doesn't exist
                $('#path-rules-container, #path-rules-table').append('<tbody></tbody>');
                $container = $('#path-rules-container tbody, #path-rules-table tbody');
            }
            $container.append(newRow);
            
            // Initialize visual for new row
            updatePathRuleVisual(newRow);
            
            // Trigger filtering after adding new rule
            if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
                window.PolyTransLanguagePaths.updateLanguagePairVisibility();
            }
        });

        // Remove path rule
        $(document).on('click', '.remove-rule', function() {
            $(this).closest('tr').remove();
            // Trigger filtering after removing rule
            if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
                window.PolyTransLanguagePaths.updateLanguagePairVisibility();
            }
        });

        // Trigger filtering when path rule values change
        $(document).on('change', '.path-rule-source, .path-rule-target, .path-rule-intermediate', function() {
            if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
                window.PolyTransLanguagePaths.updateLanguagePairVisibility();
            }
        });

        // Initial filtering on page load
        if (window.PolyTransLanguagePaths && window.PolyTransLanguagePaths.updateLanguagePairVisibility) {
            window.PolyTransLanguagePaths.updateLanguagePairVisibility();
        }
    });
})(jQuery);
