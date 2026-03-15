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
- `github` - GitHub mirror (https://github.com/jmarianski/polytrans)

Always push releases to `origin` (GitLab).

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

### Checking Version

```bash
grep -E "Version:|POLYTRANS_VERSION" polytrans.php
```

## Architecture Notes

- **Twig Templates**: All admin UI uses Twig templates in `templates/`
- **Provider System**: Pluggable AI providers via `PolyTrans_Provider_Registry`
- **Workflows**: Post-processing workflows in `includes/PostProcessing/`
- **Settings**: Stored in `polytrans_settings` WordPress option

## Important Files

- `polytrans.php` - Main plugin file, version definition
- `CHANGELOG.md` - Version history (update before each release!)
- `docs/RELEASE.md` - Full release process documentation
- `.gitlab-ci.yml` - CI/CD pipeline configuration
