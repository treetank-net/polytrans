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
`GITHUB_MIRROR_TOKEN` or `GITHUB_TOKEN`, authenticated as `GITHUB_MIRROR_USERNAME`
(`jmarianski` by default). If the token variable already contains `user:token`,
CI uses it as-is.

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

- **Twig Templates**: All admin UI uses Twig templates in `templates/`
- **Provider System**: Pluggable AI providers via `PolyTrans_Provider_Registry`
- **Workflows**: Post-processing workflows in `includes/PostProcessing/`
- **Settings**: Stored in `polytrans_settings` WordPress option

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

## Important Files

- `polytrans.php` - Main plugin file, version definition
- `CHANGELOG.md` - Version history (update before each release!)
- `docs/RELEASE.md` - Full release process documentation
- `.gitlab-ci.yml` - CI/CD pipeline configuration
