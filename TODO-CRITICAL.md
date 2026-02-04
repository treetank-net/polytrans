# PolyTrans - Critical Issues TODO

Issues identified during code review that should be addressed when time permits.

## High Priority

### 1. Race condition in workflow locks
**File:** `includes/PostProcessing/WorkflowManager.php` lines 702-734
**Issue:** Lock check and set are not atomic. Two simultaneous "Execute" clicks can both pass the lock check and run the same workflow in parallel.
**Fix:** Use `add_transient()` pattern or database-level locking.

### 2. No rollback for output actions
**File:** `includes/PostProcessing/WorkflowOutputProcessor.php` lines 93-117
**Issue:** If action 3/5 fails, actions 1-2 are already committed. Post may be in inconsistent state.
**Fix:** Consider transaction-like pattern or dry-run validation before execution.

### 3. Infinite loop risk with wp_update_post hooks
**File:** `includes/PostProcessing/WorkflowOutputProcessor.php` lines 316-331
**Issue:** `wp_update_post()` triggers hooks. If a hook triggers workflow execution, infinite loop possible.
**Fix:** Add recursion guard or temporarily unhook during workflow execution.

### 4. Ephemeral posts cleanup on dispatch failure
**File:** `includes/Core/TranslationExtension.php` lines 472-588
**Issue:** If external dispatch fails after ephemeral post creation, orphaned post remains.
**Fix:** Add cleanup in catch block or scheduled cleanup job.

## Medium Priority

### 5. No timeout for AI API calls
**File:** `includes/Assistants/AssistantExecutor.php` line 199
**Issue:** API call without timeout can hang indefinitely.
**Fix:** Add configurable timeout (default 60s?).

### 6. Status update race condition
**File:** `includes/Core/BackgroundProcessor.php` lines 432-438
**Issue:** Status check/update not atomic - may overwrite 'completed' with 'processing'.
**Fix:** Use compare-and-swap pattern or status timestamps.

### 7. Memory usage for large context
**File:** `includes/Scheduler/TranslationHandler.php` lines 346-403
**Issue:** 20 context articles × full content loaded into memory.
**Fix:** Consider pagination or streaming for very large contexts.

## Low Priority / Monitor

### 8. Retry logic for external API calls
**Files:** Multiple
**Issue:** No retry mechanism for transient API failures.
**Note:** May not be needed if failures are rare.

### 9. Background process spawn verification
**File:** `includes/Core/BackgroundProcessor.php` lines 126-176
**Issue:** `@exec()`, `@shell_exec()`, `@system()` suppress errors.
**Note:** Fallback to HTTP works, but worth monitoring.

---

*Created: 2026-02-04*
*Last reviewed: 2026-02-04*
