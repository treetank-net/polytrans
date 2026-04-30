# Changelog

All notable changes to the PolyTrans plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Description generator modals now include an Apply & Save action that persists generated assistant, workflow, or workflow-step descriptions immediately.

## [1.13.5] - 2026-04-30

### Added
- Assistant editor now exposes and saves assistant descriptions used as the default primary purpose during refinement.
- Assistant and workflow refinement screens can generate concise primary-purpose descriptions from the current prompt or workflow context.
- Workflow editor can generate descriptions for the whole workflow and individual workflow steps.
- Refinement prompt settings now include configurable system and user-message templates for assistant, workflow, and workflow-step description generators.
- Description generation results expose editable prompts, rendered prompts, and raw model output before applying the generated description.
- Unit coverage for description generator prompt defaults, context building, and response parsing.

### Fixed
- CI now runs Pest unit and architecture suites separately to avoid memory exhaustion in the combined run.

## [1.13.4] - 2026-04-30

### Added
- Assistant and workflow prompt refinement now include an editable primary-purpose field, loaded from the assistant description or selected workflow step description when available.
- Workflow steps now have an optional description field used as the default alignment goal during workflow prompt refinement.
- Default evaluator and adjuster prompts now evaluate both the primary purpose and the refinement criteria, reducing prompt drift toward criteria-only behavior.
- Assistant and workflow refinement now run a final verification pass after the last valid adjustment without performing another adjustment.
- Refinement results now let admins apply the original prompt pack or the prompt pack produced by any completed iteration.

### Changed
- Refinement progress logs now include the final verification phase and its per-post run IDs and scores.
- Prompt-refinement settings document the new `{{ prompt_objective }}` template variable for assistant and workflow refiners.

## [1.13.2] - 2026-04-29

### Added
- Workflow prompt refinement now supports custom inline `ai_assistant` steps, not only managed assistants.
- Custom workflow refinement targets can be selected in the workflow tester and are labeled separately from managed targets.
- Prompt-pack context for custom workflow AI steps includes their output contract for evaluator/adjuster awareness.

### Changed
- Applying a workflow refinement result now updates the selected target appropriately: managed assistants update the assistant record, while custom AI steps update the local workflow step.
- Custom AI step refinement preserves the step output contract (`expected_format` and `output_variables`) instead of allowing the adjuster to mutate downstream workflow assumptions.
- Workflow refinement prompts now describe the target as a selected assistant step, covering both managed and custom inline workflow steps.
- Predefined assistant workflow steps are labeled as deprecated in the workflow editor.

### Fixed
- Custom workflow AI step prompt overrides are applied during full workflow refinement runs without mutating the stored workflow.
- Applying a refined custom step now checks for prompt conflicts before saving, preventing silent overwrites when the workflow changed during refinement.

## [1.13.1] - 2026-04-29

### Added
- Workflow editor migration action for converting legacy AI assistant steps in the current workflow to managed assistants.
- Single-workflow migration endpoint that reuses the existing managed assistant migration logic without requiring a global migration from the assistants list.

## [1.13.0] - 2026-04-29

### Added
- Dedicated `Refinement Prompts` settings tab for configuring assistant and workflow refinement prompts.
- Configurable system prompts and user-message prompt templates for assistant evaluator, assistant adjuster, workflow evaluator, and workflow adjuster roles.
- Built-in fallback prompt previews and variable references for assistant and workflow refinement prompt templates.
- Assistant refinement UI now exposes evaluator and adjuster system prompts next to their user-message templates and sends both through AJAX.
- Rendered evaluator and adjuster system prompts are now visible in assistant refinement results.
- Shared prompt refinement helpers for prompt defaults, settings lookup, prompt-pack normalization/parsing, prompt rendering, score extraction, and chat execution.
- Unit coverage for prompt refinement settings, literal Twig examples, assistant refinement candidates, workflow refinement context snapshots, and post test contexts.

### Changed
- Assistant and workflow refinement system prompts now render with the same Twig context as their corresponding user-message templates.
- Prompt refinement logic was moved out of admin menu controllers into dedicated assistant and workflow refinement services.
- Assistant test context and recent-post loading are now shared through dedicated testing helpers.
- Admin menu architecture tests now prevent prompt refinement execution logic from drifting back into menu controllers.
- GitLab CI now mirrors `main` to the `treetank-net/polytrans` GitHub repository after successful pushes.

### Fixed
- Default adjuster system prompt now protects the literal `{{ content }}` Twig example with `{% verbatim %}` so it is not interpolated during system-prompt rendering.

## [1.12.0] - 2026-04-28

### Added
- Prompt refinement mode for managed assistants, including multi-post full re-evaluation loops, evaluator prompts, prompt adjuster prompts, progress logs, score comparison, prompt diffs, and apply action.
- Prompt refinement mode for workflow tester, allowing a selected managed-assistant step to be refined in the context of a full workflow run.
- Workflow refinement run IDs persisted through transients so workflow execution and evaluation can happen as separate AJAX steps.
- Workflow refinement context for evaluators and adjusters, including target step, surrounding workflow steps, output actions, run summaries, and final output snapshots.
- Side-by-side prompt diff views for assistant and workflow refinement iterations and final proposed prompt packs.

### Changed
- Prompt adjusters now return prompt packs as JSON (`system_prompt`, `user_message_template`, optional `expected_output_schema`) instead of using fragile `---` section separators.
- Workflow refinement prompts now evaluate a selected target step by its contribution to the complete workflow outcome.
- Workflow refinement payloads and prompt-context JSON are compacted and size-limited to avoid oversized transient values and model token-limit failures.
- Managed assistant workflow steps can run with temporary prompt overrides during refinement without mutating stored assistant configuration.
- Assistant expected-output schema validation now treats empty schema strings as empty instead of invalid JSON.

### Fixed
- Workflow refinement parser no longer breaks when prompts legitimately contain `---`.
- Workflow refinement persistence no longer fails on large raw workflow results by storing compact snapshots.
- Managed assistant workflow step schema parsing now uses temporary override schema during refinement runs.
- Internal assistant migration metadata is no longer sent as provider API parameters.
- Migrated assistant configurations no longer store `migrated_from` inside runtime API parameters.

## [1.11.0] - 2026-04-28

### Added
- Managed Assistant single test runner in the Assistants UI, including existing-post selection and one-click execution.
- Dedicated assistant test page (`action=test`) with detailed result panels for output, interpolated prompts, context, and usage.
- New AJAX endpoints for assistant testing: `polytrans_test_assistant` and `polytrans_get_recent_posts_for_assistant_test`.

### Changed
- Assistant test execution context now includes a structured `payload` namespace (`post`, `translation`, `runtime`) for future evaluation/refinement workflows.
- CI unit test job now runs the full Pest suite instead of only `tests/Unit`.
- `phpunit.xml.dist` updated for PHPUnit 10 schema and explicit Unit/Architecture test suites.
- Provider layer cleanup toward PSR-4 symbols (`LogsManager`, `ProviderRegistry`, `OpenAISettingsProvider::class`) with architecture test allowlist updates.
- Contributor instructions now explicitly recommend Docker-based Pest runs and document that `AGENTS.md` is a symlink to `CLAUDE.md`.

### Fixed
- Assistant test run now forwards `selected_post_id`, so context can be built from the real selected WordPress post.
- Assistant test context `original.meta`/`translated.meta` is now an associative array, which makes Twig `for` loops and `set ... merge(...)` prompt patterns work reliably.
- Assistant tester validation now correctly supports real-post execution path and no longer depends on fallback-only content checks.

## [1.10.1] - 2026-04-24

### Fixed
- Preserve uppercase post meta keys in post-processing workflow output actions so values saved as `TRANSLATION_REVIEW` remain available via `{{ translated.meta.TRANSLATION_REVIEW }}` in subsequent workflow steps.

## [1.10.0] - 2026-03-25

### Added
- Permalink Manager integration: translated slug is registered in PM URIs with correct category translations and language structure

## [1.9.9] - 2026-03-25

### Added
- `TranslationPayloadBuilder` — single source of truth for translation payload data (title, slug, content, excerpt, meta, featured_image)
- `polytrans_translation_payload` filter to customize payload before sending to providers
- Post slug included in external translation requests (was missing from TranslationHandler)
- Featured image data included in external translation requests (was missing from TranslationHandler)
- `use WP_REST_Response` import in TranslationExtension (fixes namespace error)

### Fixed
- Slug cleared by wp_update_post during post setup (Polylang language assignment, status changes) — slug now set at end of translation flow in TranslationCoordinator
- TranslationHandler and BackgroundProcessor now use consistent payload structure

## [1.9.8] - 2026-03-25

### Fixed
- Post slug not being set for pending posts — WordPress clears post_name during wp_insert_post/wp_update_post for non-published statuses; now uses direct DB update with cache flush

## [1.9.7] - 2026-03-25

### Changed
- Plugin author updated to treetank with Author URI

## [1.9.6] - 2026-03-25

### Added
- Post slug support in translation flow: `PostCreator` accepts optional `slug` field from translated data for explicit slug control (fixes Cyrillic/non-Latin URL encoding)
- `update_post_slug` action in `WorkflowOutputProcessor` for workflow-based slug updates
- Schema mapping target `post.slug` supported in managed assistant response schemas

### Fixed
- External services documentation completeness, excluded Google provider from ZIP

## [1.9.5] - 2026-03-16

### Added
- Prioritized tag autocomplete: PolyTrans tags appear first with cached double-query
- Custom AJAX endpoint `polytrans_suggest_tags` with transient cache (1h TTL)
- Prefix matches ranked above substring matches in tag suggestions

### Fixed
- Tag autocomplete inserting comma instead of tag name (WP tags-suggest.js compatibility)

## [1.9.4] - 2026-03-15

### Changed
- Disabled Google Translate provider (unsupported public API, not suitable for production use)
- Default translation provider changed to OpenAI
- All inline `<script>` and `<style>` tags converted to `wp_add_inline_script()`/`wp_add_inline_style()`
- Removed `load_plugin_textdomain()` calls (handled automatically by WordPress 4.6+)
- `composer.json` now included in distribution ZIP

### Fixed
- Added `current_user_can()` permission checks to 5 AJAX handlers in TranslationHandler and TagTranslation
- Escaped all output variables in WorkflowDebug.php and TranslationScheduler.php
- Fixed `printf()` with HTML in WorkflowMetabox — now uses `wp_kses()` + `sprintf()`
- Removed dead code registration for `ajax_get_chat_providers`

### Removed
- Google Translate provider from active providers and documentation
- Roadmap docs from distribution ZIP

## [1.9.0] - 2026-03-04

### Changed
- Comprehensive WordPress Plugin Check compliance: resolved all 326 real issues across 45 files
- Output escaping: all `echo` statements now use proper `esc_html()`, `esc_attr()`, `esc_url()` wrappers
- Input sanitization: all `$_POST`/`$_GET`/`$_REQUEST` access wrapped with `sanitize_text_field(wp_unslash())`
- SQL queries: proper `$wpdb->prepare()` usage and phpcs annotations for custom table operations
- Nonce verification: added `check_admin_referer()` to all admin form handlers
- i18n: added translator comments for all placeholders, converted to ordered placeholders (`%1$s`, `%2$d`)
- Template output: documented Twig template escaping with phpcs annotations
- README.md: added Plugin Check Notes section documenting conscious architectural decisions

### Fixed
- Plugin Check result: 0 errors (down from 326), remaining 66 warnings are documented custom table operations

## [1.8.9] - 2026-03-04

### Added
- Detach translation feature — allows unlinking a post from its translation source so it can be used as a new translation source
- ABSPATH guard to WorkflowBridge.php for direct access protection

### Changed
- Plugin headers: added GPLv2+ license declaration, removed invalid Network header
- README.md: added WordPress Plugin Check compatible headers (Stable Tag, License, Tested up to, Contributors)
- CI/CD: release artifacts now uploaded to GitLab Package Registry for stable download URLs (no more job-ID-dependent links)
- Sync scripts now use `.rsync-exclude` to skip dev files when syncing to WordPress instances
- Updated `.gitattributes` with comprehensive export-ignore rules for dev files

### Fixed
- WordPress Plugin Check compliance: hidden files, application files, missing headers, unexpected markdown files no longer synced to WordPress

## [1.8.8] - 2026-02-05

### Added
- Show/Hide toggle for secret fields in Advanced settings (Endpoint Secret, Receiver Secret)

## [1.8.7] - 2026-02-05

### Changed
- Meta filtering for managed assistants now uses output schema - interpolates schema with `original.meta`, extracts keys with `target: "meta.*"`, passes only those fields to AI

## [1.8.6] - 2026-02-05

### Fixed
- Clean meta for managed assistants - filter out ACF field keys (`_pageComponents_*`), internal WP fields, empty values, and serialized PHP arrays to reduce payload size and prevent AI confusion

## [1.8.5] - 2026-02-05

### Added
- Meta pattern for ACF group fields (e.g., `loginLink_textBefore`, `ctaButton_text`)

### Changed
- Managed assistants now receive unfiltered meta - when a `managed_*` assistant is configured on the translation path, all meta fields are passed without filtering, allowing output schemas to access any field via Twig templates

## [1.8.4] - 2026-02-05

### Added
- Extended Flynt/ACF meta patterns for text fields (title, subtitle, description, label, etc.)
- Extended Flynt/ACF meta patterns for nested repeater fields
- Filter hook `polytrans_meta_patterns` allowing themes/plugins to extend translation patterns

## [1.8.3] - 2026-02-04

### Fixed
- Workflow steps now reject empty AI responses instead of treating them as success (prevents wiping post content)
- Workflow steps now reject truncated AI responses (OpenAI `finish_reason: length`, Claude `stop_reason: max_tokens`)
- Added fallback validation in ManagedAssistantStep for empty responses

## [1.8.2] - 2026-02-04

### Fixed
- Email notifications not being sent after translation workflows complete for local translations (BackgroundProcessor)
- `notification_timing` setting was not being saved to the database despite form field existing

## [1.8.1] - 2026-02-02

### Added
- Flynt flat meta keys examples (`translation-schema-flynt-flat.twig`, `translation-user-message-flynt-flat.twig`)
- Updated Flynt documentation with both serialized array and flat meta keys approaches

### Fixed
- CI: Add php-dom extension for PHPUnit tests
- CI: Add fallback bootstrap for unit tests without WordPress environment
- CI: Fix php-syntax-check exit code

## [1.8.0] - 2026-02-02

Major refactoring: Workflow system with Dependency Injection for virtual/external context support.

### Added
- **Flynt/ACF Flexible Content support**: Translation context now includes `postComponents_*_contentHtml` and `pageComponents_*_contentHtml` meta fields
  - New `POLYTRANS_ADDITIONAL_META_PATTERNS` constant for regex-based meta filtering
  - New `TranslationHandler::filter_meta_for_translation()` method
- **Twig templates in Assistant schema**: Expected Output Schema now supports Twig syntax for dynamic field generation
  - `ManagedAssistantStep` interpolates schema before JSON parsing
  - `AssistantManager` validates and preserves Twig templates
  - `assistants-admin.js` detects Twig syntax and skips JSON validation

### Changed
- `BackgroundProcessor` uses new `filter_meta_for_translation()` instead of hardcoded SEO keys
- Replaced legacy class aliases with proper namespaces in `polytrans.php`

### Added
- **Workflow Context System**: New abstraction layer for workflow execution
  - `WorkflowContextInterface` - unified data access via dot notation
  - `VirtualWorkflowContext` - pure JSON transformation without WordPress
  - Supports both database-backed and stateless execution

- **Workflow Executor**: Orchestrates step execution with error handling
  - `WorkflowExecutor` - manages step registration and execution
  - `WorkflowResult` - detailed execution results with stats
  - Continue-on-error and skip-incompatible options

- **Workflow Step Interface**: Clean abstraction for workflow steps
  - `WorkflowStepInterface` - contract for all workflow steps
  - `AbstractWorkflowStep` - base class with common functionality
  - `is_external_compatible()` - flag for virtual context compatibility
  - `get_required_services()` - declare service dependencies
  - `get_required_paths()` - declare required data paths

- **Taxonomy Resolution Service**: Translation matching for categories/tags
  - `TaxonomyResolverInterface` - contract for term resolution
  - `TaxonomyResolver` - Polylang-based implementation
  - `TaxonomyResolution` - result object with matched/unresolved/unknown status
  - `ResolveTaxonomyStep` - workflow step using the resolver

- **Legacy Step Adapter**: Bridge pattern for existing PostProcessing steps
  - `LegacyStepAdapter` - wraps old steps to work with new context
  - Automatic context conversion (array ↔ object)
  - Output mapping back to context

- **Database Context**: WordPress post wrapper (Phase 3)
  - `DatabaseWorkflowContext` - loads post data, buffers changes
  - `commit()` for atomic database updates
  - Auto-detection of languages via Polylang

- **Workflow Runner**: High-level integration API
  - `run_virtual()` - execute on JSON payload (stateless)
  - `run_on_post()` - execute on WordPress post
  - `check_virtual_compatibility()` - validate workflow for external use

- **Workflow Bridge**: Integration with existing PolyTrans system
  - `WorkflowBridge` singleton for hooking into translation flow
  - Filter `polytrans_before_dispatch_payload` for virtual workflow processing
  - UI setting "Enable Virtual Workflows" in Translation Only mode
  - Automatic filtering of non-compatible workflows in virtual mode

### Architecture
- Services injected into context (DI pattern)
- Steps are pure transformations on JSON data
- Virtual context = no WordPress side effects
- Database context = wrapper with buffered commits
- Legacy steps automatically wrapped via adapter

### Future Considerations
- **Workflow mode flag**: Add `mode: "external" | "internal" | "both"` to workflow definition
- **Context-dependent step config**: `assistant_id_external` or `config_external` for steps needing different params in virtual vs database context
- **UI indicators**: Badge showing external-compatible steps/workflows in editor

### File Structure
```
includes/Workflows/
├── Context/
│   ├── WorkflowContextInterface.php
│   ├── AbstractWorkflowContext.php
│   ├── VirtualWorkflowContext.php
│   └── DatabaseWorkflowContext.php
├── Services/
│   ├── TaxonomyResolverInterface.php
│   └── TaxonomyResolver.php
├── Steps/
│   ├── WorkflowStepInterface.php
│   ├── AbstractWorkflowStep.php
│   ├── ResolveTaxonomyStep.php
│   └── LegacyStepAdapter.php
├── WorkflowExecutor.php
├── WorkflowRegistry.php
├── WorkflowRunner.php
└── WorkflowBridge.php
```

## [1.7.5] - 2026-01-30

### Changed
- **Translation Only Mode Warning**: Expanded UI info to clearly list all implications of stateless mode
  - No local posts created
  - Workflows won't run for external requests
  - Dispatch mode ignored (always immediate)
  - Added visual icon and bullet point formatting

## [1.7.4] - 2026-01-30

### Added
- **Translation Server Role**: New setting to configure how this server handles incoming translation requests
  - `Full Storage` (default): Creates posts locally, runs workflows, full processing
  - `Translation Only`: Stateless mode - translates and forwards without local storage or workflows
  - Ideal for dedicated translation servers that don't need to store content

### Changed
- In `Translation Only` mode, `after_workflows` dispatch is automatically converted to `immediate`

## [1.7.3] - 2026-01-30

### Added
- **Notification Timing Control**: New setting in Email Settings to control when notifications are sent
  - `After workflows complete` (default): Notifications sent after all post-processing finishes
  - `Immediately after translation`: Notifications sent right after post creation, before workflows

### Changed
- Notifications now respect the timing setting - default behavior changed to send after workflows

### Fixed
- `post_processing` status now shown in `after_workflows` dispatch mode (when translator runs workflows)

## [1.7.2] - 2026-01-30

### Added
- **Post-processing Status**: New `post_processing` status shows when translation is created but workflows are still running
  - Purple spinner indicates workflows in progress
  - Edit button appears early - post can be previewed while workflows run
  - Status changes to `completed` only after all workflows finish

### Changed
- **Status Flow**: `update_status()` moved from Coordinator to ReceiverExtension, called after workflows complete
- Stuck translation checks now include `post_processing` status

## [1.7.1] - 2026-01-30

### Changed
- **UI Reorganization**: External mode settings now grouped in fieldsets and hidden when Internal mode is selected
- **Clearer Labels**: "Skip workflows" renamed to "Trust external server (skip local workflows)" for clarity
- **Internal Mode Warning**: Added info notice explaining that internal mode may not work on all hosting environments

### Fixed
- Warning message updated to use new label terminology

## [1.7.0] - 2026-01-30

Major refactoring of external translation flow to support various database architectures (shared vs separate).
Introduces separate credentials for translation endpoint vs receiver endpoint.
Commits: bb1b2ea onwards.

### Added
- **Separate Endpoint Credentials**: Translation endpoint (SOURCE → TRANSLATOR) can now have separate secret configuration
  - New fields: Endpoint Secret, Endpoint Secret Method, Endpoint Custom Header Name
  - Falls back to Receiver credentials if not specified (backwards compatible)
  - Receiver credentials now passed in translation payload to translator
  - Translator uses credentials from payload when sending to receiver

- **External Server Database Setting**: New option to specify if external translation server shares the same database
  - Helps clarify workflow behavior in different architectures
  - Shows warning when immediate + skip_workflows + same_database (workflows won't run anywhere)

- **After Workflows Cleanup Mode**: New setting to control ephemeral post cleanup in "after workflows" dispatch mode
  - `Delete after dispatch` (default): Removes temporary local post after content is captured
  - `Keep local post`: Preserves the post locally (useful for external mode on restricted environments)

- **Shared Database Verification**: Translation Extension now verifies post existence before updating meta
  - Prevents orphan meta entries when translating server has separate database
  - Only updates original post status if post exists and has pending translation status
  - Logs skip operations for debugging

### Changed
- **Receiver Flexibility**: Receiver now supports separate database architectures
  - `RequestValidator` no longer requires original post to exist locally
  - `StatusManager` verifies post existence before updating status
  - `LanguageManager` checks for original post before setting up Polylang relationships
  - `PostCreator` uses default values when original post not available locally

### Fixed
- **External Translation Hook**: Removed erroneous `polytrans_translation_completed` hook firing on SENDER in immediate mode
  - The hook was firing with a post ID that only exists on the TARGET server
  - Workflows would fail trying to operate on non-existent local post
  - RECEIVER already fires this hook correctly where the post actually exists

- **After-workflows dispatch: prevent duplicate workflows**
  - Added `workflows_executed` flag in payload when using after_workflows dispatch mode
  - Receiver checks flag and skips workflows if already executed by sender
  - Prevents duplicate workflow execution on shared database architectures

- **After-workflows dispatch: prevent duplicate notifications and stale relationships**
  - Added `ephemeral` flag for temporary posts created during after_workflows processing
  - TranslationCoordinator skips notifications and status updates for ephemeral posts
  - LanguageManager skips Polylang relationship setup for ephemeral posts
  - Receiver handles final relationships, notifications and status updates

## [1.6.22] - 2026-01-30

### Fixed
- **Workflows List**: Fixed "All languages" not displaying for workflows without specific language

## [1.6.21] - 2026-01-30

### Added
- **Quick Toggle Button**: Added enable/disable toggle button on workflows list for quick status changes
  - One-click toggle without opening the editor
  - Visual feedback with icon and status column update

### Changed
- **Workflows List**: "All languages" now displayed instead of empty language field

## [1.6.20] - 2026-01-29

### Fixed
- **Metabox Quick Execute**: Fixed target_language context using post's actual language instead of workflow's language setting
  - Ensures correct language context when executing "All languages" workflows from metabox

## [1.6.19] - 2026-01-29

### Fixed
- **Metabox "All Languages" Support**: Fixed workflows with "All languages" not showing in post metabox
  - Metabox now correctly displays workflows targeting all languages alongside language-specific ones

## [1.6.18] - 2026-01-29

### Added
- **All Languages Workflow Support**: Workflows can now target all languages instead of a specific one
  - New "All languages" option in workflow language dropdown
  - Workflows with no language specified will run for any translation target
  - Backward compatible - existing workflows with specific language continue to work unchanged

## [1.6.17] - 2026-01-28

### Fixed
- **Gutenberg + Polylang Compatibility**: Fixed duplicate `lang` parameter issue causing AJAX failures
  - Changed AJAX parameter from `lang` to `target_lang` to avoid conflict with Polylang's automatic `lang` injection in block editor
  - Added backward compatibility fallback to support old `lang` parameter
  - Fixes "Cannot translate to the same language" error when clearing/retrying translations in Gutenberg editor
  - Affects: Translation scheduler clear/retry buttons, tag translation save
- **Plugin Activation**: Fixed "generated 1060 characters of unexpected output" warning
  - Added index existence check in `LogsManager::ensure_table_indexes()` before attempting to create database indexes
  - Prevents duplicate key SQL errors during plugin activation/reactivation
  - Improved index verification to check both existence and definition

## [1.6.16] - 2026-01-20

### Added
- **Dirty Check Field Whitelist**: Added configurable whitelist of form field patterns to ignore when checking for unsaved changes
  - Prevents plugins like AIOSEO, Yoast, Rank Math from triggering false "Unsaved Changes" warnings in translation scheduler
  - Built-in whitelist includes: `aioseo`, `yoast`, `wpseo`, `rank_math`, `seopress`, `tsf`, `acf-`, `woocommerce`, `elementor`
  - Custom patterns can be configured in Settings → Advanced → Dirty Check Field Whitelist
  - Supports partial matching (case-insensitive)

### Fixed
- Fixed missing `featured_image` support in managed assistant translation examples
  - Added `featured_image` to `translation-user-message-full.twig` template
  - Added `featured_image` schema to `translation-schema-full.json`
  - Managed assistants now handle featured image metadata (alt, title, caption, description) like regular assistants

## [1.6.15] - 2025-12-16

### Added
- **Configurable API Timeout**: Added `api_timeout` setting in Advanced Settings (30-600 seconds, default: 180)
  - Applies to all AI providers (OpenAI, Claude, Gemini)
  - Automatic retry on timeout (single retry)
  - Centralized timeout handling via HttpClient

### Changed
- **HttpClient Refactoring**: Centralized HTTP request handling and retry logic
  - Created `HttpClient` and `HttpResponse` utility classes
  - Migrated OpenAI, Claude, and Gemini clients to use HttpClient
  - Removed duplicate retry logic from provider classes
  - Improved error handling and consistency across providers
- **AI Assistant Workflow Step**: Enhanced provider selection
  - Added provider dropdown in UI for AI Assistant (Custom) steps
  - Auto-selects random enabled provider with chat capability if none selected
  - Shows warning when provider is auto-selected
  - Fixed provider field sanitization in workflow steps

### Fixed
- Fixed LogsManager usage in AiAssistantStep (changed from get_instance() to static method)
- Fixed provider field not being saved in workflow steps
- Fixed missing max_tokens sanitization in workflow steps

## [1.6.14] - 2025-12-16

### Changed
- Migrated TagTranslation and SettingsMenu to Twig templates
  - Refactored TagTranslation::admin_page() to use TemplateRenderer
  - Refactored SettingsMenu::render_overview() to use TemplateRenderer
  - Created templates/admin/tag-translation/page.twig
  - Created templates/admin/settings/overview.twig
  - Updated CSS with new classes for Twig templates
  - Reduced SettingsMenu.php from 184 to 161 lines (-23 lines, 12% reduction)
- **All major PHP files with mixed HTML have been migrated to Twig!** 🎉

## [1.6.13] - 2025-12-16

### Changed
- Migrated PostprocessingMenu to Twig templates
  - Refactored all render methods to use TemplateRenderer
  - Created templates/admin/workflows/*.twig templates
  - Updated CSS with new classes, removed inline styles
  - Reduced PostprocessingMenu.php from 1437 to 1091 lines (-346 lines, 24% reduction)
- Added urlencode() function to TemplateRenderer for Twig templates
- Updated TWIG_MIGRATION_STATUS.md documentation

## [1.6.12] - 2025-12-16

### Added
- **Twig Template System for Translation Settings**: Migrated TranslationSettings page to Twig templates
  - Extracted HTML to `templates/admin/settings/` directory with modular templates for each tab
  - Extracted inline CSS to `assets/css/settings/translation-settings-admin.css` (path rules, workflow steps, notification filters)
  - Extracted inline JavaScript to `assets/js/settings/translation-settings-admin.js` (path rules management, secret method changes)
  - Added Twig functions: `wp_editor()`, `get_language_pairs()`, `get_language_name()`, `action()`, `in_array()`
  - Clean separation of concerns: HTML in Twig, CSS in separate file, JS in separate file

### Changed
- **TranslationSettings Architecture**: Refactored `TranslationSettings::output_page()` and all `render_*()` methods to use `TemplateRenderer`
  - Reduced PHP file size from 1393 to 579 lines (-814 lines, ~58% reduction)
  - All HTML rendering now handled by Twig templates
  - Improved code maintainability and readability
  - Provider custom UI handling moved to PHP (before template rendering)

## [1.6.11] - 2025-12-15

### Added
- **Twig Template System for Logs Page**: Migrated logs admin page to Twig templates
  - Extracted HTML to `templates/admin/logs/page.twig` and `table.twig`
  - Extracted CSS to `assets/css/logs-admin.css`
  - Extracted JavaScript to `assets/js/logs-admin.js`
  - Clean separation of concerns: HTML in Twig, CSS in separate file, JS in separate file
  - Improved code maintainability and readability

### Changed
- **Logs Page Architecture**: Refactored `LogsManager::admin_logs_page()` to use `TemplateRenderer`
  - All HTML rendering now handled by Twig templates
  - Assets properly enqueued via `admin_enqueue_scripts` hook
  - Consistent with other admin pages using Twig templates

## [1.6.10] - 2025-12-15

### Fixed
- **Logs Pagination Counter**: Fixed pagination counter not updating in top navigation after clicking pagination links
  - Top pagination now updates correctly when clicking pagination links or refreshing logs table
  - AJAX endpoint now returns top pagination HTML along with table HTML
  - Both top and bottom pagination counters now stay synchronized
  - Fixed issue where top pagination showed incorrect page numbers and links

## [1.6.9] - 2025-12-15

### Fixed
- **Translation Button Double-Click Prevention**: Fixed issue where translate button could be clicked twice before being disabled
  - Button is now immediately disabled on first click to prevent double-click
  - Added `translateButtonLocked` flag to prevent other handlers from re-enabling button during translation process
  - Button remains locked for minimum 5 seconds or until translation status is confirmed
  - Other handlers (scope change, form dirty check) now respect the lock flag
  - Prevents accidental duplicate translation requests

## [1.6.8] - 2025-12-15

### Added
- **Twig Template System**: Migrated AI Assistants admin pages to Twig templates
  - Added `TemplateRenderer` class with WordPress function/filter integration
  - Added `TemplateAssets` class for managing asset enqueuing from templates
  - Created `templates/admin/assistants/list.twig` for assistants list view
  - Created `templates/admin/assistants/editor.twig` for assistant editor form
  - Templates support WordPress functions (`esc_html`, `__`, `admin_url`, etc.)
  - Asset management via Twig functions (`enqueue_assets`, `localize_script`, `add_inline_script`)

### Changed
- **Code Quality**: Improved namespace usage throughout codebase
  - Fixed full namespace references in `TemplateRenderer.php` (added `use Twig\TwigFilter`)
  - Fixed full namespace references in `AssistantsMenu.php` (added `use PolyTrans\Providers\SettingsProviderInterface`)
  - All classes now use proper `use` statements instead of full namespace paths
  - Improved PSR-4 compliance

### Fixed
- **JSON Formatting**: Fixed Expected Output Schema field to preserve indentation and newlines
  - Added custom `textarea_safe` Twig filter that escapes only `<` and `>`
  - JSON formatting with proper indentation now preserved in textarea elements
  - Matches formatting behavior of User Message Template field

## [1.6.7] - 2025-12-15

### Removed
- **Test Assistant Functionality**: Removed unused test assistant button and related code
  - Removed `testSuccess` and `testError` i18n strings from AssistantsMenu
  - Removed test-results-container CSS styles
  - Test assistant functionality was never fully implemented and caused errors
  - Workflow testing functionality remains intact (has separate UI)

## [1.6.6] - 2025-12-15

### Fixed
- **Gemini Model Filtering**: Fixed filtering to use camelCase `generateContent` instead of `GENERATE_CONTENT`
  - Gemini API returns methods in camelCase format (e.g., `generateContent`, `generateImage`)
  - Image/video generation models are now correctly filtered out
  - Models like "nano-banana" and "gemini-3-pro-image-preview" are excluded

### Fixed
- **Model Refresh**: Fixed refresh button to force refresh models from API instead of using cache
  - Added `force_refresh` parameter to AJAX endpoint
  - Cache is cleared before loading models when refresh button is clicked
  - Automatic loading still uses cache (1 hour), manual refresh always fetches fresh data

## [1.6.5] - 2025-12-15

### Added
- **Gemini Provider Support**: Full integration of Google Gemini as a translation provider
  - `GeminiProvider`: Translation provider interface implementation
  - `GeminiSettingsProvider`: Settings provider with API key validation and model loading from API
  - `GeminiChatClientAdapter`: Chat client adapter for Gemini GenerateContent API
  - `GeminiAssistantClientAdapter`: Assistant client adapter for Gemini Agents API
  - Gemini models loaded dynamically from `/v1beta/models` endpoint with filtering and grouping
  - Support for Gemini 1.0, 1.5, and 2.x models
  - Gemini provider available in Language Paths and Managed Assistants
  - Gemini Agents API support (agents can be loaded and used)

### Technical
- **Gemini API Integration**: 
  - Chat API: Uses `generateContent` endpoint with `contents` and `systemInstruction`
  - Agents API: Uses agent endpoints for predefined agents
  - Model loading: Fetches from Google Generative AI API with caching
  - API key validation: Validates via `/models` endpoint

## [1.6.4] - 2025-12-15

### Fixed
- **Model Selection UI**: Fixed "None selected" option disappearing after automatic model refresh
  - Added "None selected" option preservation in universal provider settings JavaScript
  - Option is now always available in model dropdown, even after AJAX refresh
  - Improved detection of PHP-rendered models to prevent unnecessary AJAX calls

### Removed
- **Deprecated Files**: Removed deprecated OpenAI-specific JavaScript and CSS files
  - Removed `assets/js/translator/openai-integration.js` (replaced by universal system)
  - Removed `assets/css/translator/openai-integration.css` (replaced by universal CSS)
  - OpenAI now fully uses universal provider settings system

### Technical
- **Code Cleanup**: Disabled `enqueue_assets()` in `OpenAISettingsProvider`
  - OpenAI settings now handled entirely by universal provider system
  - Reduces code duplication and improves maintainability

## [1.6.3] - 2025-12-15

### Changed
- **Model Loading**: Removed all hardcoded fallback models - models are now loaded exclusively from API
  - Models are cached using WordPress transients (1 hour cache)
  - If API fails or returns no models, empty array is returned (no fallback)
  - Applies to both OpenAI and Claude providers

### Fixed
- **Default Model Selection**: Changed default model from hardcoded values to "None selected"
  - OpenAI: Changed from `'gpt-4o-mini'` to empty string `''`
  - Claude: Changed from `'claude-3-5-sonnet-20241022'` to empty string `''`
  - Users must explicitly select a model in settings

### Improved
- **Model Validation**: Improved validation to gracefully handle empty model selection
  - Invalid models no longer fallback to hardcoded defaults
  - Empty model selection is allowed and validated
  - Chat clients return clear error message when model is not selected

### Technical
- **Caching**: Added WordPress transient caching for model lists (1 hour TTL)
  - Cache key includes API key hash for security
  - Reduces API calls while keeping models up-to-date
  - Cache automatically expires after 1 hour
- **Error Handling**: Chat clients now return structured error when model is missing
  - Error code: `model_not_selected`
  - User-friendly error message in admin language

## [1.6.2] - 2025-12-15

### Added
- **Claude Provider Support**: Full integration of Claude (Anthropic) as a translation provider
  - `ClaudeProvider`: Translation provider interface implementation
  - `ClaudeSettingsProvider`: Settings provider with API key validation and model loading from API
  - `ClaudeChatClientAdapter`: Chat client adapter for Claude Messages API
  - Claude models loaded dynamically from `/v1/models` endpoint with filtering and grouping
  - Support for Claude 3.x models (Opus, Sonnet, Haiku, 3.5 series)
  - Claude provider available in Language Paths and Managed Assistants
  - Informational notice in settings explaining Claude requires Managed Assistants (no dedicated Assistants API)

### Changed
- **Provider Registration**: Claude provider and chat client now registered via hooks/filters
- **Response Parsing**: Added Claude response format handling (`content[0].text` structure)
- **Model Fallbacks**: Added Claude-specific fallback models for offline scenarios

### Technical
- Claude provider uses PSR-4 autoloading (no require_once needed)
- Claude chat client integrated via `ChatClientFactory`
- Backward compatibility aliases added for Claude classes

## [1.6.1] - 2025-12-15

### Changed
- **System Prompt as Capability**: Refactored `supports_system_prompt` boolean to `system_prompt` capability in provider manifest
  - More consistent with other capabilities (`translation`, `chat`, `assistants`)
  - Providers now declare `'system_prompt'` in capabilities array if they support it
  - Backward compatibility maintained with fallback to `supports_system_prompt` field

### Fixed
- **Model Selection**: Fixed issue where "Loading models..." option remained selected after models loaded
  - Now shows "None selected" option and doesn't auto-select first model
  - Empty model selection is allowed (uses default from settings)
- **Provider Model Loading**: Fixed `get_model_options()` to use `load_models()` from `SettingsProviderInterface` instead of hardcoded fallback
  - Provider-specific fallback models based on provider_id
  - Proper error handling and logging for model loading failures

### Improved
- **Provider Capabilities Documentation**: Updated documentation with accurate provider capabilities
  - DeepSeek: `['chat', 'system_prompt']` (has system prompt support, no Assistants API)
  - Gemini: `['assistants', 'chat', 'system_prompt']` (has Agents API)
- **Provider Settings UI**: Added informational notice for providers without Assistants API
  - Shows message explaining that Managed Assistants must be used
  - Includes link to create Managed Assistant
  - Automatically displayed based on provider manifest capabilities
- **Empty Model Handling**: Improved handling of empty model selection
  - Chat clients now fall back to default model from settings if model is empty
  - Validation accepts empty model (uses default from `get_default_settings()`)
  - Better UX with "None selected" option in model dropdown

### Technical
- **Code Quality**: Removed hardcoded `require_once` statements in favor of PSR-4 autoloading
  - All provider classes now use Composer autoloader
  - Consistent with modern PHP standards

## [1.6.0] - 2025-12-11

### Added
- **Universal Provider JS System**: Complete universal JavaScript system for all provider settings tabs using data attributes
- **Universal Chat Client Factory**: Factory pattern for chat API clients, allowing easy addition of new providers without modifying core code
- **ChatClientInterface**: New interface for chat/completion API clients (OpenAI, Claude, Gemini, etc.)
- **Provider Extensibility**: Full extensibility system allowing external plugins to add custom providers via hooks and filters
- **Universal Provider Settings UI**: Automatic UI generation based on provider manifest (API key + model selection)
- **Provider Capabilities System**: Clear distinction between `translation`, `chat`, and `assistants` capabilities
- **System Prompt Support Detection**: Dynamic UI that hides/shows system prompt field based on provider capabilities
- **Backward Compatibility**: Settings saved in both old (OpenAI-specific) and new (universal) formats with fallback reading
- **Provider Registry via Hooks**: All providers (including OpenAI) now register via hooks for consistency
- **Documentation**: Complete developer guides (`PROVIDER_EXTENSIBILITY_GUIDE.md`, `PROVIDER_CAPABILITIES.md`)
- **Example Plugin**: Full DeepSeek provider example (`docs/examples/polytrans-deepseek/`)
- **Quick Start Guides**: `QUICK_START_ADD_PROVIDER.md` and `EXTERNAL_PLUGIN_QUICK_START.md`

### Changed
- **OpenAI Integration**: OpenAI now uses universal JS/CSS system instead of custom `openai-integration.js/css`
- **AssistantExecutor**: Refactored to use `ChatClientFactory` instead of hardcoded switch statements
- **Provider Settings**: All providers now use universal CSS classes (`.provider-config-section`, `.provider-api-key-section`, etc.)
- **Settings Saving**: Settings are now saved for all providers with UI, not just the selected default provider
- **Nonce Handling**: Universal endpoints now accept multiple nonce types for broader compatibility
- **OpenAI Registration**: OpenAI provider and chat client now register via hooks/filters like external plugins

### Fixed
- **Settings Persistence**: Fixed issue where saving settings (e.g., default model) didn't persist changes
- **Provider Key Validation**: Fixed 400 error on `polytrans_validate_provider_key` by accepting multiple nonce types
- **Language Paths Filtering**: Fixed filtering logic to correctly show only language pairs based on defined paths
- **System Prompt Validation**: Conditional validation for system prompt based on provider's `supports_system_prompt` capability
- **Interface Implementation**: Added missing `validate_api_key()`, `load_assistants()`, and `load_models()` methods to `OpenAISettingsProvider`

### Removed
- **OpenAISettingsUI.php**: Removed deprecated class (replaced by universal UI system)
- **Hardcoded Provider Logic**: Removed hardcoded switch statements in favor of factory pattern

### Technical
- **Factory Pattern**: Implemented factory pattern for chat clients, making it easy to add new providers
- **Data Attributes**: Universal JS system uses data attributes (`data-provider`, `data-field`, `data-action`) for all interactions
- **Event Delegation**: Universal JS uses event delegation for better performance and extensibility
- **Filter System**: All providers register via filters (`polytrans_chat_client_factory_create`, `polytrans_register_providers`)
- **Manifest System**: Provider manifests define capabilities, endpoints, and configuration details
- **Backward Compatibility**: Settings read with fallback mechanism (new format → old format)

## [1.5.8] - 2025-12-11

### Added
- **Workflow Predefined Assistants**: Predefined AI Assistant workflow step now supports all provider assistants (OpenAI API, Claude, Gemini) grouped by provider
- **Universal Assistant Loading**: Endpoint `polytrans_load_assistants` now works for workflow editor with proper nonce handling
- **Provider Grouping**: Assistants in workflow dropdown are now properly grouped by type (OpenAI API Assistants, Managed Assistants, etc.)

### Fixed
- **Workflow Nonce**: Fixed security check failure for `polytrans_load_assistants` in workflow editor by adding `openai_nonce` to workflow localization
- **Assistant Grouping**: Fixed issue where OpenAI API assistants were incorrectly grouped with managed assistants in workflow dropdown
- **Translation Providers in Workflow**: Excluded translation providers (Google Translate) from Predefined Assistant workflow step - only AI assistants are shown
- **Managed Assistants Filtering**: Predefined Assistant workflow step now correctly excludes managed assistants (they have separate step type)
- **CSS Selector**: Fixed `select[id*="assistant-id"] option:first-child` selector to only target direct children (not optgroup children) using `> option:first-child`
- **Array Type Error**: Fixed `in_array()` error when `enabled_providers` was null by ensuring it's always an array

### Changed
- **PredefinedAssistantStep**: Refactored to support multiple provider types (managed assistants, OpenAI API assistants, future: Claude/Gemini)
- **Endpoint Parameters**: `polytrans_load_assistants` now accepts `exclude_managed` and `exclude_providers` parameters for workflow context
- **API Key Fallback**: Endpoint `polytrans_load_assistants` now falls back to settings API key if not provided in POST request

### Technical
- Improved error handling in `ajax_load_openai_assistants` with try-catch for API calls
- Added provider field to assistant objects in grouped response
- Enhanced assistant grouping logic to use both `group` and `provider` fields for proper categorization

## [1.5.7] - 2025-12-11

### Fixed
- **Model Refresh**: Fixed issue where model select dropdown disappeared after clicking "Refresh" button
- Added dedicated message container for refresh notifications to prevent UI replacement
- Refresh success message is now dismissible with close button

### Changed
- **UI Naming**: Renamed "Language Pairs" tab to "Language Paths" for better clarity
- Updated tab IDs and selectors from `language-pairs-*` to `language-paths-*` to match new naming

## [1.5.6] - 2025-12-11

### Removed
- **Max Tokens**: Removed `max_tokens` parameter from all assistant and workflow configurations
- OpenAI API now uses default max_tokens behavior (no limit set by plugin)

### Added
- **Model Support**: Added support for GPT-5 series models (gpt-5, gpt-5o, gpt-5-turbo, etc.)
- **Dynamic Model Loading**: Models are now fetched from OpenAI API when available, with fallback to hardcoded list
- **Model Refresh**: Added "Refresh" button to reload models from OpenAI API

## [1.5.5] - 2025-12-11

### Fixed
- **External Translation**: Fixed namespace resolution errors for WordPress REST API classes
- Added leading backslash to `WP_REST_Response` and `WP_REST_Request` in `TranslationReceiverExtension`

## [1.5.4] - 2025-12-11

### Fixed
- **External Translation**: Fixed fatal error in REST API endpoint when receiving translations from external services
- Fixed namespace resolution error for `PolyTrans_Logs_Manager` in `TranslationReceiverExtension`

## [1.5.3] - 2025-12-11

### Fixed
- **JSON Parsing**: Fixed parsing of JSON responses with double-escaped characters (common in code blocks)
- Added normalization of escaped characters (`\\r\\n` → `\r\n`, `\\n` → `\n`) before JSON parsing
- Improved error logging for JSON parsing failures with detailed error codes and response previews

### Technical
- Enhanced `JsonResponseParser` to handle double/triple-escaped characters in JSON responses
- Added JSON normalization in `AssistantExecutor` for better compatibility with AI responses
- Improved error reporting with JSON error codes and response snippets

## [1.5.2] - 2025-12-11

### Fixed
- **Plugin Activation**: Fixed fatal error during plugin activation caused by missing old `class-logs-manager.php` file
- Replaced old `require_once` statements with Bootstrap initialization for PSR-4 autoloading

## [1.5.1] - 2025-12-11

### Changed
- **Notification Filters**: Simplified Author Notification Filters to use only email domain filtering (removed user role filtering)
- **UI Improvements**: Moved Author Notification Filters section below email templates for better UX
- **Translation Schema**: Updated schema to match actual data structure (meta and featured_image as arrays instead of object/string)

### Fixed
- **Background Process Logging**: Replaced file-based error logging with transient-based system for compatibility with S3 uploads
- **Translation Response Parsing**: Fixed schema mismatches causing type coercion warnings for meta and featured_image fields
- **Meta Translation**: Fixed issue where meta fields were not being sent/received correctly in translation responses

### Technical
- Improved error detection for background processes using transients instead of log files
- Added comprehensive logging for translation response debugging
- Removed redundant JSON encoding/decoding for meta fields

## [1.5.0] - 2025-12-10

### 🎉 Major Release: PSR-4 Architecture Migration

This release represents a **complete architectural refactoring** of the PolyTrans plugin to modern PHP standards (PSR-4). All 60+ classes have been migrated to namespaced structure with full backward compatibility.

### Added - PSR-4 Architecture

- **Complete PSR-4 Migration**: All classes now follow PSR-4 autoloading standard
  - Base namespace: `PolyTrans\`
  - Organized into logical modules: `Assistants\`, `Core\`, `Debug\`, `Menu\`, `PostProcessing\`, `Providers\`, `Receiver\`, `Scheduler\`, `Templating\`
  - Zero manual `require_once` statements (except WordPress core)
  - Composer-based autoloading for all classes
  
- **Three-Tier Autoloading System**:
  1. Composer Autoloader - Vendor dependencies (Twig, etc.)
  2. PSR-4 Autoloader - Namespaced classes (`PolyTrans\*`)
  3. LegacyAutoloader - Temporary backward compatibility (empty, will be removed)

- **Backward Compatibility Layer**: 
  - All old class names still work via aliases (e.g., `PolyTrans_Workflow_Manager` → `PolyTrans\PostProcessing\WorkflowManager`)
  - Zero breaking changes for existing code
  - Seamless upgrade path

- **New Directory Structure**:
  ```
  includes/
  ├── Assistants/          # PolyTrans\Assistants\
  ├── Core/                # PolyTrans\Core\
  ├── Debug/               # PolyTrans\Debug\
  ├── Menu/                # PolyTrans\Menu\
  ├── PostProcessing/      # PolyTrans\PostProcessing\
  ├── Providers/           # PolyTrans\Providers\
  ├── Receiver/            # PolyTrans\Receiver\
  ├── Scheduler/           # PolyTrans\Scheduler\
  └── Templating/          # PolyTrans\Templating\
  ```

- **Migrated Modules** (60+ classes):
  - ✅ Assistants (3 classes)
  - ✅ Core (8 classes)
  - ✅ Debug (2 classes)
  - ✅ Menu (5 classes)
  - ✅ PostProcessing (11 classes + 4 interfaces)
  - ✅ Providers (6 classes + 2 interfaces)
  - ✅ Receiver (11 classes)
  - ✅ Scheduler (2 classes)
  - ✅ Templating (1 class)

- **Interface Migration**: All 4 interfaces migrated to PSR-4
  - `TranslationProviderInterface`
  - `SettingsProviderInterface`
  - `WorkflowStepInterface`
  - `VariableProviderInterface`

### Removed - Cleanup

- **Deleted Unused Files**:
  - `includes/core/process-task.php` (not used, BackgroundProcessor creates dynamic scripts)
  - All old lowercase directories (`debug/`, `templating/`, `core/`)
  - All `class-*.php` files (replaced with PSR-4 versions)

### Fixed - PSR-4 Migration

- **Namespace Resolution**: Added leading backslash to all global class references in namespaced files (9 occurrences)
- **Test Compatibility**: Updated all test files to use PSR-4 autoloading
- **Bootstrap Loading**: Fixed interface loading order (interfaces before aliases)
- **Strict Types**: Fixed `declare(strict_types=1)` position in TwigEngine

### Improved - Code Quality

- **Reduced Cognitive Load**: Organized code into logical modules
- **Better Maintainability**: Clear namespace structure makes navigation easier
- **Modern PHP Standards**: Follows PSR-4, PSR-12 coding standards
- **Improved Testing**: All unit tests passing with new structure

### Documentation

- **Updated ARCHITECTURE.md**: Complete rewrite reflecting PSR-4 structure
- **Updated README.md**: All file paths updated to new structure
- **Added PSR-4 Guide**: Documentation of namespace structure and autoloading

### Upgrade Notes

**⚠️ PHP Version Requirement Change**: This release requires **PHP 8.1 or higher** (previously 7.4+).

**Backward Compatibility**:
- **No breaking changes**: All old class names work via aliases
- **No database changes**: Schema remains identical
- **No settings migration**: All options preserved
- **Zero downtime**: Plugin works immediately after update (if PHP 8.1+ is available)

**Requirements**:
- **PHP 8.1+** (breaking change from 7.4+)
- Composer dependencies must be installed (`vendor/autoload.php`)
- For production releases, dependencies are included
- For development installs, run `composer install` after update

**Why PHP 8.1+?**
- PHP 7.4 reached End of Life in November 2022
- Twig 3.14+ requires PHP 8.1+
- Better performance and security
- Modern PHP features (enums, readonly properties, etc.)

### Added
- **Managed Assistants in Translation System**: Integrated Managed Assistants with system translations
  - Grouped dropdown UI showing both Managed and OpenAI API Assistants
  - Assistant type auto-detection (managed_xxx vs asst_xxx)
  - Managed Assistants can now be used for language pair translations
  - Full schema-based parsing with auto-mapping for system translations
  - Scalable structure for future Claude/Gemini integration
  - Example: Select "Translation EN→PL (Managed)" for PL→EN translation pair

- **Complete Translation Schema Examples**: Added comprehensive examples for all SEO fields
  - `translation-schema-full.json`: Complete schema with 14 SEO fields (RankMath + Yoast)
  - `translation-user-message-full.twig`: Dynamic template with meta field loop
  - `TRANSLATION_SCHEMA_GUIDE.md`: Complete setup and customization guide
  - Minified schema for direct paste into UI
  - Zero Output Actions needed with auto-mapping

### Fixed
- **Workflow Test UI**: Added collapsible sections to display interpolated prompts (System Prompt & User Message) sent to AI after Twig variable interpolation
- **Managed Assistant Output**: Fixed output structure to wrap AI response in array format (`{ 'ai_response': '...' }`) so output processor can correctly extract values for saving to meta/content
- **Context Variable Updates**: Fixed `translated.meta` not being updated after `update_post_meta` action, enabling subsequent steps to access meta values via `{{ translated.meta.KEY }}`
- **Workflow Sanitization**: Added missing sanitization case for `managed_assistant` step type, fixing issue where `assistant_id` was stripped during workflow save
- **Assistant Migration**: Fixed critical bugs in workflow migration from `ai_assistant` to `managed_assistant`:
  - Correctly separate system prompt and user message based on delimiter
  - Properly handle WP_Error returns from Assistant_Manager
  - Update workflow array reference (not just local variable) when converting steps
  - Cast API parameters to correct types (int for max_tokens, float for temperature/top_p)

### Improved
- **OpenAI Error Logging**: Detailed error codes and messages for translation failures
  - Now shows specific error codes (rate_limit_exceeded, insufficient_quota, server_error, etc.)
  - Includes human-readable error messages from OpenAI API
  - Structured logging with error_code, error_details, thread_id, run_id
  - **Before**: "OpenAI: step 0 failed (de -> en): Run ended with status: failed"
  - **After**: "OpenAI: step 0 failed (de -> en) [code: rate_limit_exceeded]: Run ended with status: failed - Rate limit reached for requests"
  - Makes debugging translation failures much faster (instantly see if it's rate limiting, insufficient funds, timeout, etc.)
  - See: `docs/ERROR_LOGGING_IMPROVEMENTS.md` for full details and error code reference

### Added (Phase 1 - Complete)
- **AI Assistants Management System**: Complete implementation with Admin UI and Workflow integration
  
  **Backend Infrastructure:**
  - `wp_polytrans_assistants` table for centralized assistant configurations
  - `PolyTrans_Assistant_Manager`: Full CRUD operations (26 unit tests ✅)
  - `PolyTrans_Assistant_Executor`: Execute assistants with Twig variable interpolation (27 unit tests ✅)
  - Support for OpenAI Chat Completions API (Claude and Gemini placeholders)
  - Text and JSON response formats with validation
  - Comprehensive error handling (rate limiting, timeouts, API errors)
  
  **Admin UI (PolyTrans > AI Assistants):**
  - List view with assistant details (name, provider, model, response format, created date)
  - Create/Edit assistant form with:
    - Name, provider (OpenAI/Claude/Gemini), model dropdown with "Use Global Setting" option
    - **Separate editors** for System Instructions and User Message Template
    - Prompt template editor with Twig syntax and variable pills
    - Response format (text/json)
    - Configuration (temperature, max_tokens, top_p)
  - Delete assistant with confirmation
  - Test assistant functionality with sample variables
  - Beautiful, responsive interface matching WordPress admin design
  - Shared CSS/JS components with workflow editor for consistency
  
  **Workflow Integration:**
  - New step type: "Managed AI Assistant" (managed_assistant)
  - Uses assistants configured in Admin UI
  - Automatic Twig variable interpolation from workflow context
  - Dropdown selector showing all available assistants with model info
  - Backward compatible with existing workflow steps
  - AJAX endpoint for loading managed assistants in workflow editor
  - **Automatic Migration**: Existing `ai_assistant` steps are automatically converted to `managed_assistant` on plugin activation/update
  - Migration creates managed assistants from old step configs and updates workflows
  - Manual migration trigger available in AI Assistants admin page
  
  **Benefits:**
  - ✅ Centralized management - configure once, use everywhere
  - ✅ Multi-provider support - OpenAI, Claude, Gemini (extensible)
  - ✅ Twig templates - powerful variable interpolation
  - ✅ Reusable - same assistant in multiple workflows
  - ✅ Testable - test assistants before using in production
  - ✅ Maintainable - update assistant prompts without editing workflows

## [1.3.5] - 2025-12-10

### Added
- **Prompt Editor Module**: Extracted prompt editor into reusable module (`prompt-editor.js`)
  - Can be used in multiple places (workflows, assistants, etc.)
  - Centralized variable definitions
  - Consistent UI across plugin
  - Public API: `PolyTransPromptEditor.create()`, `.init()`, `.variables`

### Changed
- **Refactored workflow editor**: Now uses `PolyTransPromptEditor` module
  - Cleaner code, less duplication
  - Easier to maintain and extend
  - Backward compatible with existing workflows

## [1.3.4] - 2025-12-10

### Fixed
- **CRITICAL BUG**: Twig variable interpolation not working
  - `convert_legacy_syntax()` was converting `{{ variable }}` to `{{{ variable }}}`
  - Added check: if template already has Twig syntax, skip conversion
  - Variables now interpolate correctly in workflow prompts
- **File permissions**: Fixed templating files permissions (600 → 644) for Docker compatibility
- **Twig cache directory**: Improved cache directory handling
  - Cache enabled in production (WP_DEBUG = false), disabled in development
  - Automatic fallback to no-cache if directory creation fails
  - Set 777 permissions for Docker compatibility (www-data user)
  - Prevents "Unable to create cache directory" errors

### Added
- **Markdown rendering in AI responses**: AI responses with markdown are now rendered beautifully
  - Auto-detects markdown patterns (headers, bold, italic, code, lists, links)
  - Renders markdown as formatted HTML in test results
  - Fallback to plain text for non-markdown content
  - Improved readability of AI-generated content reviews

### Changed
- **Removed debug logging**: Cleaned up temporary debug logs from Variable Manager and Twig Engine
  - Debug logging was used to diagnose interpolation bug
  - Now removed for cleaner production logs
- **Increased AI response max height**: 200px → 400px for better visibility of longer responses

## [1.3.3] - 2025-12-10

### Changed
- **CONTEXT REFRESH LOGIC** (Phase 0.2): Context now stays fresh between workflow steps
  - `refresh_context_from_database()` now uses Post Data Provider for complete rebuild
  - Updates all variable structures: legacy (`post_title`), top-level (`title`), and nested (`original.*`, `translated.*`)
  - `apply_change_to_context()` (test mode) now updates all variable structures consistently
  - Ensures subsequent workflow steps see updated data after AI changes

### Fixed
- **Context staleness bug**: After AI changes title/content, next steps now see fresh data
  - Before: Step 2 would see old title from Step 0
  - After: Step 2 sees updated title from Step 1
- **Test mode consistency**: Test mode context updates now match production mode behavior

### Technical Details
- Workflow Output Processor: `refresh_context_from_database()` uses Post Data Provider
- Workflow Output Processor: `apply_change_to_context()` updates all variable structures
- Both production and test modes now maintain context consistency
- Meta field updates propagate to nested structures (`translated.meta.*`)

## [1.3.2] - 2025-12-10

### Added
- **SHORT VARIABLE ALIASES** (Phase 0.1 Day 2): Cleaner, more intuitive variable names
  - New short aliases: `{{ original.title }}`, `{{ translated.content }}`
  - Replaces verbose `{{ original_post.title }}`, `{{ translated_post.content }}`
  - Backward compatible: old names still work (`original_post.*`, `translated_post.*`)
  - Updated UI: Variable sidebar shows new recommended syntax
  - Meta field access: `{{ original.meta.seo_title }}`, `{{ translated.meta.KEY }}`

### Changed
- **POST DATA PROVIDER**: Added short aliases for better DX
  - `original` → alias for `original_post` (shorter, cleaner)
  - `translated` → alias for `translated_post` (shorter, cleaner)
  - Top-level aliases unchanged: `title`, `content`, `excerpt`
- **ADMIN UI**: Variable lists updated with new recommended syntax
  - Sidebar pills show `original.title` instead of `original_post.title`
  - Advanced examples updated with loops and meta field access
  - Legacy variables still shown for backward compatibility

### Technical Details
- Post Data Provider: Added `original` and `translated` aliases (lines 73-77)
- Updated `get_available_variables()` with new short aliases
- Updated `get_variable_documentation()` with Phase 0.1 examples
- JavaScript: Updated both `renderVariableSidebar()` and `renderVariableReferencePanel()`

## [1.3.1] - 2025-12-09

### Added
- **TWIG TEMPLATE ENGINE INTEGRATION**: Modern templating system with powerful features
  - Twig 3.22.1 for template rendering with caching and filters
  - New syntax: `{{ variable }}` for modern templating (legacy `{variable}` still works)
  - Nested variable access: `{{ original.title }}`, `{{ translated.content }}`
  - WordPress filters: `wp_excerpt`, `wp_date`, `wp_kses`, `esc_html`, `esc_url`
  - WordPress functions: `get_permalink`, `get_post_meta`
  - Conditional templates: `{% if translated.title %}...{% endif %}`
  - Template filters: `{{ content|wp_excerpt(50) }}`, `{{ date|wp_date('F j, Y') }}`
  - Automatic legacy syntax conversion (`{var}` → `{{ var }}`)
  - Deprecated variable mappings (`post_title` → `title` for backward compatibility)
  - Graceful fallback to regex interpolation on Twig errors
  - Cache directory: `/cache/twig/` (auto-created, gitignored)

### Changed
- **VARIABLE MANAGER**: Now uses Twig Engine for template interpolation
  - `interpolate_template()` delegates to Twig Engine with fallback
  - Added `interpolate_template_legacy()` private method for regex fallback
  - Automatic error logging when Twig rendering fails
- **ADMIN UI**: Completely redesigned variable panel for better UX
  - **Compact pills design**: Variables shown as `title` instead of `{{ title }}`
  - **Tooltips on hover**: Show variable description
  - **Click to insert**: Variables insert into last focused textarea
  - **Undo support**: `Ctrl+Z` works (uses `execCommand`)
  - **Scrollable**: Max 200px height with custom scrollbar
  - **Collapsible advanced section**: Filters, conditionals, meta examples
  - **New variables**: Added `recent_articles` for SEO context
  - **Removed legacy section**: Old "Available Variables" panel at bottom
  - **Fixed examples**: Show actual line breaks instead of `\\n`

### Fixed
- **LAZY LOADING**: Twig Engine now lazy loads to avoid fatal error
  - Removed `require_once` from `class-polytrans.php` (caused "Failed opening" error)
  - Added `load_twig_engine()` method in Variable Manager
  - Twig loads only when needed (during template interpolation)
  - Ensures Composer autoloader is available before loading Twig namespace

### Technical Details
- Composer dependencies: `twig/twig: ^3.0` (v3.22.1)
- Twig cache: `cache/twig/` (disabled in WP_DEBUG mode)
- Autoloader: `vendor/autoload.php` loaded in `polytrans.php`
- Class: `PolyTrans_Twig_Engine` (450 lines, includes/templating/)
- Integration: Variable Manager lazy loads Twig Engine with try-catch fallback
- Testing: Architecture tests passing (6/6), Pest PHP 2.36.0 installed

## [1.3.0] - 2025-12-08

### Added
- **WORKFLOW DATABASE MIGRATION**: Migrated workflows from wp_options to dedicated database table
  - Created `wp_polytrans_workflows` table with proper indexes (workflow_id, language, enabled, name)
  - Automatic migration from legacy wp_options storage with backup
  - JSON storage for triggers, steps, and output_actions
  - Backward compatibility mapping (`workflow_id` → `id`, `language` → `target_language`)
  - `get_workflows_using_assistant()` method for Phase 1 assistants system
  - Improved performance with indexed queries instead of array filtering
  - Migration flag to prevent duplicate migrations
  - Activation hook to create table on plugin activation
  - Admin_init hook to ensure table exists even if activation hook didn't run

### Changed
- **WORKFLOW STORAGE**: All workflow CRUD operations now use database instead of wp_options
  - `get_all_workflows()` uses SQL SELECT with hydration
  - `get_workflows_for_language()` uses indexed WHERE clause
  - `get_workflow()` uses prepared statement
  - `save_workflow()` uses INSERT/UPDATE with automatic timestamp tracking
  - `delete_workflow()` uses DELETE statement
  - `cleanup_orphaned_data()` removes invalid workflows from database

### Technical Details
- Database schema supports metadata fields (created_at, updated_at, created_by, attribution_user_id)
- Migration preserves all existing workflow data with automatic type conversion
- Fallback handling for legacy field names (target_language, attribution_user)
- Safe migration with backup to `polytrans_workflows_backup` option
- Error logging for migration failures

## [1.2.1] - 2025-12-08

### Fixed
- **USER AUTOCOMPLETE**: Fixed missing AJAX endpoint configuration in settings menu
  - Added `ajaxUrl` and `nonce` to `PolyTransUserAutocomplete` JavaScript object in class-settings-menu.php
  - User search now uses secure custom AJAX endpoint (`polytrans_search_users`) instead of WP REST API fallback
  - Fixes user autocomplete functionality on trans.eu and other installations where WP REST API is blocked by other plugins
  - Ensures consistent behavior across all PolyTrans admin pages (settings and postprocessing menus)

## [1.2.0] - 2025-07-17

### Added
- **POST-PROCESSING WORKFLOWS**: AI-driven automation for intelligent post management
  - Workflow system for running AI assistants on translated content
  - `update_post_status` action: AI can set post status based on content analysis (publish, draft, pending, etc.)
  - `update_post_date` action: AI can schedule posts for optimal publishing times
  - Workflow executor with test mode and production execution
  - Workflow output processor with robust helper methods (`create_change_object`, `apply_change_to_context`, `execute_change`, `refresh_context_from_database`)
  - Variable providers for context data (post data, meta data, articles data)
  - Workflow metabox for manual execution
  - Workflow debug tools and logging
- **MENU STRUCTURE**: Organized admin interface with 4 dedicated menu classes
  - Settings menu (`class-settings-menu.php`)
  - Post-processing menu (`class-postprocessing-menu.php`)
  - Logs menu (`class-logs-menu.php`)
  - Tag translation menu (`class-tag-translation.php`)
- **BACKGROUND PROCESSOR**: Asynchronous task handling for translation jobs
- **LOGS MANAGER**: Comprehensive debugging and monitoring system
- **INTERNATIONALIZATION**: Extensive i18n implementation
  - 233+ localized strings using `__()` function
  - 890+ instances of 'polytrans' text domain usage
  - 7 `wp_localize_script` implementations for JavaScript components
  - Translation strings for all user-facing interfaces

### Changed
- **ARCHITECTURE**: Restructured plugin with dedicated directories (menu/, postprocessing/, receiver/, scheduler/)
- **FILE ORGANIZATION**: Moved translation extension and settings to core directory
- **DOCUMENTATION**: Reorganized docs into structured directories (user-guide/, admin/, developer/, examples/)
- **README**: Enhanced with professional presentation and navigation

### Fixed
- **WORKFLOW TESTING**: Fixed JavaScript frontend processing of workflow test results
  - Enhanced change objects with display-friendly fields (`action_type`, `target_description`, `current_value`, `new_value`)
  - Resolved "No changes" display issue in test results
- **CONTEXT INITIALIZATION**: Fixed empty "BEFORE" values in workflow test mode
  - Added `ensure_context_has_post_data()` method to populate context with actual post data
  - Ensures accurate "BEFORE" values from database
- **SECURITY**: Resolved 43+ critical security issues
  - Proper nonce verification with `check_admin_referer()` for form processing
  - `$_SERVER` validation with isset() checks
  - `wp_unslash()` usage for all $_POST data handling (21+ instances)
  - Escaped output using `__()` and `esc_html()`
  - Array sanitization with `array_map()` for allowed sources/targets
- **JAVASCRIPT**: Fixed undefined variable `$failed` in translation scheduler

### Removed
- **CLEANUP**: Removed redundant internal development files and duplicate documentation
- **DIRECTORY STRUCTURE**: Removed standalone API directory (functionality integrated into core)
- **CODE CLEANUP**: Dashboard widget functionality

## [1.0.0] - 2025-07-09

### Added
- **Initial Release**: Core translation management functionality
- **PROVIDER SYSTEM**: Translation provider architecture
  - Google Translate integration (`class-google-provider.php`)
  - OpenAI integration with custom assistants (`class-openai-provider.php`, `class-openai-client.php`)
  - Provider registry and interface system
- **TRANSLATION SCHEDULER**: Translation scheduling and management
  - Meta box for post editing screen
  - Automatic and manual translation triggering
  - Translation status tracking (pending, processing, completed, failed)
  - Background task queue integration
- **RECEIVER SYSTEM**: REST API for receiving translations from external services
  - Translation coordinator for handling incoming translations
  - Language, media, metadata, taxonomy managers
  - Post creator with validation and security
  - Status and notification management
- **REVIEW WORKFLOW**: Translation review system
  - Reviewer assignment with user autocomplete
  - Email notifications for reviewers
  - Translation attribution tracking
- **TAG TRANSLATION**: Dedicated tag/term translation management
  - Bulk import/export functionality
  - CRUD operations for tag translations
- **CORE FEATURES**:
  - User autocomplete (`class-user-autocomplete.php`)
  - Translation meta box for post editor
  - Translation notifications system
  - Translation settings with multi-server support
  - Polylang integration
- **ADMIN INTERFACE**: Settings page with provider configuration
- **ASSETS**: Admin CSS and JavaScript files for all components

### Technical
- **PHP Version**: 7.4+
- **WordPress Version**: 5.0+
- **Text Domain**: polytrans
- **Plugin Architecture**: Object-oriented with PSR-4-like autoloading
