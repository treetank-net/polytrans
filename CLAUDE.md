# PolyTrans - Claude AI Instructions

This file contains instructions for Claude AI when working on the PolyTrans plugin.

## Project Overview

PolyTrans is a WordPress plugin for AI-powered multilingual translation management. It supports multiple AI providers (OpenAI, Claude, Gemini) and features translation scheduling, workflow automation, and review processes. Google Translate support is available behind the `POLYTRANS_ENABLE_GOOGLE` feature flag (default: false).

## Key Directories

```
polytrans/
├── assets/                 # JS/CSS assets
│   ├── js/scheduler/       # Translation scheduler UI
│   ├── js/settings/        # Settings page JS
│   └── css/                # Stylesheets
├── includes/               # PHP classes
│   ├── Core/               # Core functionality
│   ├── Scheduler/          # Translation scheduling
│   ├── Providers/          # AI provider integrations
│   ├── PostProcessing/     # Workflow steps
│   └── Menu/               # Admin menus
├── templates/              # Twig templates
├── docs/                   # Documentation
│   └── RELEASE.md          # Release process (IMPORTANT!)
└── tests/                  # PHPUnit/Pest tests
```

## Release Process - CRITICAL

**When deploying/releasing a new version, ALWAYS follow these steps IN ORDER:**

### Before Creating a Tag

1. **Update CHANGELOG.md** - Add new version section with all changes:
   ```markdown
   ## [X.Y.Z] - YYYY-MM-DD

   ### Added
   - New features

   ### Changed
   - Changes

   ### Fixed
   - Bug fixes
   ```

   Use `git log vPREVIOUS..HEAD --oneline` to see commits since last tag.

2. **Update version in polytrans.php** (TWO places):
   ```php
   * Version: X.Y.Z
   ```
   ```php
   define('POLYTRANS_VERSION', 'X.Y.Z');
   ```

3. **Commit and push**:
   ```bash
   git add -A
   git commit -m "chore: Release version X.Y.Z"
   git push origin main
   ```

4. **Create and push tag**:
   ```bash
   git tag -a vX.Y.Z -m "Release version X.Y.Z"
   git push origin vX.Y.Z
   ```

### After Pushing Tag

GitLab CI will automatically:
- Build the plugin ZIP
- Create a GitLab Release with changelog
- Attach download links

### If You Forgot Something

If you created a tag without updating version/changelog:

```bash
# Delete remote and local tag
git push origin :refs/tags/vX.Y.Z
git tag -d vX.Y.Z

# Fix the issues, commit, push
git add -A && git commit -m "chore: Release version X.Y.Z" && git push origin main

# Recreate tag
git tag -a vX.Y.Z -m "Release version X.Y.Z"
git push origin vX.Y.Z
```

## Git Remotes

- `origin` - GitLab (https://gitlab.com/treetank/polytrans) - PRIMARY for releases
- `github` - GitHub mirror (https://github.com/treetank-net/polytrans)

Always push releases to `origin` (GitLab).

GitLab CI mirrors successful `main` pipelines to GitHub using
`GITHUB_TREETANK_TOKEN`, scoped to the `treetank-net/polytrans` GitHub repo.

## Common Tasks

### Syncing to WordPress

After making changes, sync to WordPress instances:
```bash
cd /home/jm/projects/trans-info
./sync-polytrans-watch.sh  # Watch mode
# or
./sync-polytrans.sh        # One-time sync
```

### Running Tests

```bash
cd /home/jm/projects/trans-info/plugins/polytrans
./vendor/bin/pest
```

Prefer Docker for Pest runs because local PHP may miss PHPUnit extensions such as `dom`/`php-xml`:

```bash
docker compose -f docker-compose.test.yml run --rm polytrans-test ./vendor/bin/pest tests/Unit/AssistantExecutorTest.php
docker compose -f docker-compose.test.yml run --rm polytrans-test ./vendor/bin/pest
```

Note: `AGENTS.md` is a symlink to `CLAUDE.md`; update either path and the shared instruction file changes.

### Checking Version

```bash
grep -E "Version:|POLYTRANS_VERSION" polytrans.php
```

## Architecture Notes

- **PSR-4, always**: `composer.json` maps `PolyTrans\` → `includes/`, loaded via
  `includes/Bootstrap.php`. New code goes in a namespaced class under `includes/`.
  Do **not** add procedural functions to `polytrans.php` — it holds only the plugin
  header, constants and the WordPress lifecycle hooks.
- **Twig Templates**: All admin UI uses Twig templates in `templates/`
- **Provider System**: Pluggable AI providers via `PolyTrans_Provider_Registry`
- **Workflows**: Post-processing workflows in `includes/PostProcessing/`
- **Settings**: Stored in `polytrans_settings` WordPress option

### Database tables

Each table is owned by its class, which exposes `initialize()` / `create_table()`
using `dbDelta`, and is registered in `polytrans_activate()` in `polytrans.php`:

| Table | Owner |
|---|---|
| `polytrans_logs` | `Core\LogsManager` |
| `polytrans_workflows` | `PostProcessing\Managers\WorkflowStorageManager` |
| `polytrans_assistants` | `Assistants\AssistantManager` |
| `polytrans_usage` | `Core\UsageRecorder` |

Prefer a lazy `initialize()` before the first write as well, so updating the plugin
without reactivating it does not silently drop data.

**Trap**: `polytrans_create_tables()` in `polytrans.php` is dead code — nothing
calls it. Adding table creation there has no effect.

### Long-running admin actions

Anything that waits on a model must go through `Core\AsyncJobRunner`
(`dispatch()` → transient + loopback worker, then `poll()`), not one held-open AJAX
request. High reasoning effort runs for minutes, which outlives proxy and PHP
limits, and a dropped connection carries no error message. The worker also has a
shutdown handler, so a fatal becomes a reported failure.

Each menu registers its own `dispatch`/`poll` actions with its own nonce and adds a
`polytrans_async_job_execute` filter handling only its own job types, passing
everything else through. Existing users: workflow refinement, assistant tests.

### Model capabilities and cost

- `Core\ModelCapabilities` — which reasoning effort levels and temperature a
  `(model, endpoint)` pair accepts, plus `resolve_surface()` routing between
  OpenAI's `/chat/completions` and `/responses`. See
  `docs/developer/MODEL_CAPABILITIES.md`.
- `Core\ModelPricing` — per-token prices. **No provider publishes prices via its
  API** (verified); the catalogue comes from OpenRouter, cached in a transient.
  Reasoning tokens are inside the output count for OpenAI/Anthropic but separate
  for Gemini — billing them twice is the easy mistake.
- `Core\UsageRecorder` — writes a row to `polytrans_usage`, post meta on the
  translated post, and a per-language breakdown on the original. Costs are frozen
  at write time, since prices change.

`record()` is called from the places that know both the usage payload and the post
it belongs to:

| Call site | `activity` |
|---|---|
| `Core\TranslationPathExecutor` (per path step) | `translation` |
| `PostProcessing\WorkflowExecutor` (per step) | `workflow_step` |
| `Menu\AssistantsMenu` (test runs) | `assistant_test` |

To make a new AI call show up, return the provider's raw `usage` block — not a
single token total, which cannot be priced — alongside `provider` and `model`, and
let the caller that knows the post record it. `skip_post_meta` writes the row but
leaves post totals alone; use it for test runs, which cost money without producing
a post. Usage is recorded even when the step later fails, because a reply that
could not be parsed was still billed.

`OpenAI\OpenAIProvider::translate()` duplicates the path logic and is effectively
unreachable — any configured assistant makes `$has_paths` true, routing through
`TranslationPathExecutor` instead — so it is deliberately not instrumented.

Reporting: `Core\UsageReport` (SQL aggregations), `Menu\UsageMenu` (dashboard) and
`Core\UsageMetaBox` (per-post panel, reads the meta so no query runs on an edit
screen). A group column cannot be a prepared value, so `UsageReport::by()` takes
only names from its allow-list — the dashboard passes request input into it.

### Gotchas

- **OpenAI settings are saved through an explicit key list**, not a merge
  (`Core\TranslationSettings`). A new `openai_*` setting is silently discarded
  until it is added to that list. Claude and Gemini use `array_merge`.
- `ChatClientFactory` only knows the provider, not the model, so
  `OpenAIChatClientAdapter` is the single entry point for OpenAI and delegates to
  the `/responses` adapter when the model or effort requires it.
- Reasoning models reject `max_tokens` (they want `max_completion_tokens`), and on
  `/responses` the token budget covers reasoning tokens, so a limit carried over
  from Chat Completions can starve the answer entirely.

## Translation Flow

The translation execution flow has several key stages:

1. **Translation Request** → REST endpoint `/polytrans/v1/translation/translate`
2. **Translation Execution** → `TranslationPathExecutor` dispatches to provider or managed assistant
   - Managed assistants (`managed_XXX`) → `AssistantExecutor` → AI returns JSON
   - Provider assistants (`asst_XXX`, `project_XXX`) → provider-specific client
   - Standard providers → `provider->translate()`
3. **Post Creation** → `TranslationCoordinator` → `PostCreator::create_post()`
   - Uses `$translated['title']`, `$translated['content']`, `$translated['excerpt']`
   - Optionally uses `$translated['slug']` for transliterated slugs
   - WordPress generates slug from title if none provided
4. **Post Setup** → metadata, taxonomies (Polylang-based), language assignment, featured image
5. **Post-Processing Workflows** → triggered via `polytrans_translation_completed` action
   - `WorkflowManager` → `WorkflowExecutor` → step-by-step execution
   - Each step can have `output_actions` (configured in UI) processed by `WorkflowOutputProcessor`
   - `ManagedAssistantStep` generates `auto_actions` from schema mappings (informational)

### Key Files in Translation Flow

- `includes/Core/TranslationPathExecutor.php` - Routes to provider/assistant
- `includes/Receiver/TranslationCoordinator.php` - Orchestrates post creation
- `includes/Receiver/Managers/PostCreator.php` - Creates WP post
- `includes/Receiver/Managers/TaxonomyManager.php` - Assigns categories/tags via Polylang term translations
- `includes/PostProcessing/WorkflowExecutor.php` - Runs workflow steps
- `includes/PostProcessing/WorkflowOutputProcessor.php` - Applies step outputs to posts
- `includes/PostProcessing/Steps/ManagedAssistantStep.php` - AI assistant workflow step

### Slug Handling

- `PostCreator` accepts optional `slug` field from translated data for explicit slug control
- If no slug provided, WordPress auto-generates from title (problematic for Cyrillic/non-Latin scripts)
- `WorkflowOutputProcessor` supports `update_post_slug` action for workflow-based slug updates
- Schema mapping target `"post.slug"` is supported

### Taxonomy Assignment

- Categories/tags are assigned via **Polylang term translation relationships**, NOT name matching
- Uses `pll_get_term_translations($term_id)` to find translated terms
- Falls back to original term ID if Polylang unavailable
- Missing translations are logged but not assigned

### Post meta ownership

Two different filters decide what meta reaches a translation, and they work in
opposite directions — confusing them is what hid a bug for a long time:

| Path | Class | Direction |
|---|---|---|
| Post meta → AI | `Receiver\TranslationHandler::filter_meta_for_translation` | **allow**-list (SEO keys + ACF/Flynt patterns) |
| Original's meta → translated post | `Receiver\Managers\MetadataManager::should_skip_meta_key` | **deny**-list |

Because the model only ever sees an allow-list, translated *content* stays correct
even when the copy step is broken — so a bug there shows up somewhere else entirely.

**Everything under the `_polytrans_` prefix is per-post state, and all of it lives on
the original**, with a language suffix: `_polytrans_translation_status_<lang>`,
`_polytrans_translation_log_<lang>`, `_polytrans_translation_post_id_<lang>`,
`_polytrans_author_notified`, `_polytrans_usage_summary`. It is written by the class
that owns it (`TranslationExtension`, `StatusManager`, `BackgroundProcessor`,
`LanguageManager`, `UsageRecorder`) on the post it belongs to. Never copy it, and
never add a `_polytrans_` key expecting the copy step to enumerate it — the deny-list
matches the prefix, deliberately.

**Why it matters**: `handle_check_stuck_translations` (`class-polytrans.php`) and
`fix_stuck_translations` (`TranslationHandler`) query
`meta_key LIKE '_polytrans_translation_status_%'` across the **whole** table. A stray
copy reading `translating` is indistinguishable from a real stuck translation, and
gets marked `failed` after 24 h.

`Core\MetaCleanup` cleans up what earlier versions copied. Its criterion is narrow on
purpose: only keys naming the post's **own** language. A relay (`pl → en → de`) gives
the intermediate post a legitimate hub for other languages, and nothing in the row
distinguishes that from a copy. The post's language comes from the pointer on its
original (`_polytrans_translation_post_id_<lang>` == this ID), with Polylang as
fallback — **`polytrans_translation_lang` holds the SOURCE language**, so acting on it
deletes the wrong keys.

## Important Files

- `polytrans.php` - Main plugin file, version definition
- `CHANGELOG.md` - Version history (update before each release!)
- `docs/RELEASE.md` - Full release process documentation
- `.gitlab-ci.yml` - CI/CD pipeline configuration
