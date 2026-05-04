---
name: release-workflow
description: Use when preparing and publishing a PolyTrans plugin release (version/changelog updates, tag push, GitLab/GitHub sync, and transinfo-wp-docker deploy tag).
---

# PolyTrans Release Workflow

Use this workflow for a full release of the WordPress plugin and deploy-tag sync.

## Preconditions

- Run from repo root: `/home/jm/projects/personal/polytrans`.
- Branch should be `main`.
- Ensure you know the target version, for example `1.13.12`.

## Release Steps (PolyTrans)

1. Check status and latest tags.
   - `git status --short --branch`
   - `git tag --list "v1.*" --sort=-v:refname | head -10`

2. Update release metadata.
   - `CHANGELOG.md`: add `## [X.Y.Z] - YYYY-MM-DD` with `Added/Changed/Fixed`.
   - `polytrans.php`: update both:
     - header `Version: X.Y.Z`
     - `define('POLYTRANS_VERSION', 'X.Y.Z');`

3. Validate key paths before release.
   - Syntax: `php -l includes/...` for touched PHP files.
   - Tests (prefer Docker): `docker compose -f docker-compose.test.yml run --rm polytrans-test ./vendor/bin/pest <target-tests>`

4. Commit and push.
   - `git add -A`
   - `git commit -m "chore: Release version X.Y.Z"`
   - `git push origin main`

5. Create and push release tag.
   - `git tag -a vX.Y.Z -m "Release version X.Y.Z"`
   - `git push origin vX.Y.Z`

6. Sync GitHub if CI token lacks `workflow` scope.
   - `git push gh main`
   - `git push gh vX.Y.Z`

## Deploy Tag Steps (transinfo-wp-docker)

1. Go to repo: `/home/jm/projects/trans-info/transinfo-wp-docker`.
2. Keep only plugin sync changes (avoid test temp files).
   - Restore generated temp if needed:
     - `git restore -- public/wp-content/plugins/polytrans/vendor/pestphp/pest/.temp/test-results`
3. Commit synced plugin update.
   - `git add public/wp-content/plugins/polytrans/...`
   - `git commit -m "polytrans X.Y.Z"`
   - `git push origin main`
4. Create next deploy tag (for example `v0.0.480`).
   - `git tag -a v0.0.N -m "Release v0.0.N"`
   - `git push origin v0.0.N`

## Final Verification

- PolyTrans repo clean and aligned:
  - `git status --short --branch`
  - `git log --oneline --decorate -3`
- transinfo-wp-docker repo clean and aligned:
  - `git status --short --branch`
  - `git log --oneline --decorate -3`
