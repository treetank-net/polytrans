# Release Process

This document describes how to create a new release of the PolyTrans plugin.

## Prerequisites

- Push access to the repository
- All changes merged to `main` branch
- All tests passing

## ⚠️ CRITICAL: Pre-Release Checklist

**BEFORE creating a tag, you MUST complete these steps IN ORDER:**

### 1. Update CHANGELOG.md

Add a new version section with all changes since the last release:

```markdown
## [1.7.1] - 2026-01-20

### Added
- New feature description

### Changed
- Changed feature description

### Fixed
- Bug fix description
```

**Tip:** Use `git log v1.6.15..HEAD --oneline` to see all commits since the last tag.

### 2. Update Version Numbers

Update version in **polytrans.php** (2 places):

```php
* Version: 1.7.1
```

```php
define('POLYTRANS_VERSION', '1.7.1');
```

And in **readme.txt** (1 place) — this is what the WordPress.org directory serves:

```
Stable tag: 1.7.1
```

The `build` job fails the pipeline if the tag, both `polytrans.php` values, the
`Stable tag` and the `CHANGELOG.md` section do not all agree — so a forgotten
`readme.txt` is caught, but only after the tag is already pushed. Check it here first.

### 3. Commit All Changes

```bash
git add -A
git commit -m "chore: Release version 1.7.1"
git push origin main
```

### 4. Create and Push Tag

```bash
# Create annotated tag
git tag -a v1.7.1 -m "Release version 1.7.1"

# Push tag to GitLab
git push origin v1.7.1
```

## Automated Release Process (GitLab CI)

When you push a version tag (e.g., `v1.7.1`), GitLab CI automatically:

1. ✅ Checkout code
2. ✅ Install PHP 8.1
3. ✅ Run `composer install --no-dev --optimize-autoloader`
4. ✅ Create clean release directory (excludes dev files)
5. ✅ Generate ZIP archives:
   - `polytrans.zip` - For WordPress installation
   - `polytrans-1.7.1.zip` - Versioned archive
6. ✅ Generate SHA256 checksums
7. ✅ Create GitLab Release with files attached
8. ✅ Extract changelog section for release notes

### Verify Release

1. Go to: https://gitlab.com/treetank/polytrans/-/releases
2. Check that ZIP files are attached
3. Verify the release notes contain the correct changelog
4. Download and verify:
   ```bash
   sha256sum -c polytrans.zip.sha256
   ```

## What's Included in Release

**`.distignore` is the single source of truth.** Read that file rather than a list
duplicated here — a second list is how the ZIP came to ship `.codex`, a dangling
`AGENTS.md` symlink and `.playwright-mcp/` logs. Everything not matched by
`.distignore` ships; that includes `readme.txt`, which the directory requires.

The `build` job then verifies the packed result: no hidden files, no symlinks, no
development files, and `readme.txt` present. It fails the pipeline if any of those
is wrong, so `.distignore` cannot quietly rot again.

## Manual Release (Fallback)

If GitLab CI fails, you can build manually:

```bash
# Install production dependencies
composer install --no-dev --optimize-autoloader

# Create release directory — same exclusion list the pipeline uses
mkdir -p release
rsync -a --exclude-from=.distignore ./ release/polytrans/

# Create ZIP
cd release
zip -r polytrans-1.7.1.zip polytrans/
zip -r polytrans.zip polytrans/

# Generate checksums
sha256sum polytrans-1.7.1.zip > polytrans-1.7.1.zip.sha256
sha256sum polytrans.zip > polytrans.zip.sha256
```

Then manually upload to GitLab Releases.

## Versioning

We follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (1.x.x): Breaking changes
- **MINOR** (x.1.x): New features, backward compatible
- **PATCH** (x.x.1): Bug fixes, backward compatible

### Examples

- `1.7.0` → `1.7.1`: Bug fix or small feature (patch)
- `1.7.0` → `1.8.0`: New feature (minor)
- `1.7.0` → `2.0.0`: Breaking change (major)

## Complete Release Checklist

Use this checklist for every release:

```
PRE-RELEASE (do these BEFORE creating tag):
- [ ] All tests passing (`composer test` — Unit and Architecture run separately)
- [ ] PHPCS clean (`composer phpcs` — see docs/development/plugin-check.md)
- [ ] Plugin Check clean of ERRORs on the distribution package (warnings reviewed)
- [ ] CHANGELOG.md updated with new version section
- [ ] Version updated in `polytrans.php` (header comment)
- [ ] Version updated in `polytrans.php` (POLYTRANS_VERSION constant)
- [ ] `Stable tag` updated in `readme.txt`
- [ ] Changes committed: `git commit -m "chore: Release version X.Y.Z"`
- [ ] Changes pushed to main: `git push origin main`

CREATE RELEASE:
- [ ] Tag created: `git tag -a vX.Y.Z -m "Release version X.Y.Z"`
- [ ] Tag pushed: `git push origin vX.Y.Z`

POST-RELEASE VERIFICATION:
- [ ] GitLab CI pipeline successful, including the four gates on the tagged commit
- [ ] Release visible in GitLab Releases
- [ ] ZIP files downloadable
- [ ] Version number in ZIP matches tag
- [ ] Plugin tested in WordPress after installation
```

## Fixing a Bad Release

If you created a tag without updating version/changelog:

```bash
# Delete remote tag
git push origin :refs/tags/v1.7.1

# Delete local tag
git tag -d v1.7.1

# Fix version numbers and changelog
# ... make your changes ...

# Commit fixes
git add -A
git commit -m "chore: Release version 1.7.1"
git push origin main

# Recreate and push tag
git tag -a v1.7.1 -m "Release version 1.7.1"
git push origin v1.7.1
```

## Troubleshooting

### GitLab CI Failed

1. Check pipeline logs: https://gitlab.com/treetank/polytrans/-/pipelines
2. Common issues:
   - Composer dependencies failed → Check `composer.json`
   - ZIP creation failed → Check file permissions
   - Release creation failed → Check CI job token permissions

### ZIP is Too Large

The release ZIP should be < 10 MB. If larger:

1. Check that `node_modules/` is excluded
2. Check that `tests/` is excluded
3. Run `composer install --no-dev` (not with `--dev`)

### Missing Files in ZIP

1. Check `rsync` exclude patterns in `.gitlab-ci.yml`
2. Verify files exist in repository
3. Check if files are in `.gitignore`

### Release Created Without Changelog

The CI extracts changelog from CHANGELOG.md for the release notes. If the section is missing:

1. Delete the release in GitLab UI
2. Update CHANGELOG.md
3. Delete and recreate the tag (see "Fixing a Bad Release")

---

**Last Updated**: 2026-01-20
**CI Platform**: GitLab CI/CD
