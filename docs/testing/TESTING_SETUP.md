# PolyTrans Testing Setup

**Status**: ✅ Operational
**Framework**: Pest PHP v2.36.0 + PHPUnit 10.5.36
**Environment**: Docker (PHP 8.2-cli)
**Tests Passing**: 6/6 (100%)

---

## Quick Start

```bash
# Run all tests
./run-tests.sh

# Run specific test suite
docker compose -f docker-compose.test.yml run --rm polytrans-test ./vendor/bin/pest --testsuite=Architecture
docker compose -f docker-compose.test.yml run --rm polytrans-test ./vendor/bin/pest --testsuite=Unit

# Run with coverage (requires xdebug)
./run-tests.sh --coverage

# Run parallel tests
./run-tests.sh --parallel
```

---

## Test Structure

```
tests/
├── Architecture/              # Architecture tests (Pest Arch plugin)
│   ├── NamingConventionsTest.php  # Code quality rules
│   ├── ProviderArchitectureTest.php  # Provider swappability
│   └── WorkflowArchitectureTest.php  # Workflow system structure
├── Unit/                      # Unit tests
│   ├── WorkflowStorageManagerTest.php  # DB storage
│   └── WorkflowExecutionTest.php  # Workflow logic
├── Helpers/                   # Test helpers (future)
├── Fixtures/                  # Test fixtures (future)
├── Pest.php                   # Pest configuration
└── TestCase.php               # Base test case
```

---

## What We Test

### ✅ Architecture Tests (3 tests, 7 assertions)

**Provider Architecture** (`ProviderArchitectureTest.php`):
- ✓ Translation providers don't cross-depend (OpenAI ≠→ Google, Google ≠→ OpenAI)
- ✓ Providers use only allowed namespaces (PolyTrans\\Providers, WP_Error, Psr, GuzzleHttp)

**Workflow Architecture** (`WorkflowArchitectureTest.php`):
- ✓ No circular dependencies (Steps/Triggers don't use Workflow_Manager)

**Naming Conventions** (`NamingConventionsTest.php`):
- ✓ No dangerous functions (eval, create_function) are used

### ✅ Unit Tests (3 tests, 10 assertions)

**Workflow Storage** (`WorkflowStorageManagerTest.php`):
- ✓ Database table names follow WordPress conventions (wp_prefix)

**Workflow Execution** (`WorkflowExecutionTest.php`):
- ✓ Trigger types are properly defined (arrays, not empty)

---

## Docker Setup

### Dockerfile
- **Base**: `php:8.2-cli`
- **Extensions**: xml, dom, simplexml, pdo, pdo_mysql, zip
- **Composer**: Latest version
- **System**: git, unzip, zip, libxml2-dev, libzip-dev

### docker-compose.test.yml
- **Service**: `polytrans-test` (network_mode: host for internet access)
- **Volumes**:
  - `.:/app` (source code)
  - `polytrans-vendor:/app/vendor` (persistent dependencies)
  - `polytrans-cache:/app/cache` (Pest cache)
- **MySQL**: `mysql-test` service (port 3307) for integration tests (future)

---

## Dependencies

From `composer.json`:

```json
{
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "pestphp/pest": "^2.35",
    "pestphp/pest-plugin-arch": "^2.7",
    "wp-coding-standards/wpcs": "^3.1",
    "phpmd/phpmd": "^2.10",
    "squizlabs/php_codesniffer": "^3.6"
  },
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

---

## CI/CD Integration (Future)

Add to `.gitlab-ci.yml`:

```yaml
test:
  stage: test
  image: docker:latest
  services:
    - docker:dind
  script:
    - cd plugins/polytrans
    - docker compose -f docker-compose.test.yml run --rm polytrans-test ./vendor/bin/pest
  only:
    - branches
    - merge_requests
```

---

## Test Philosophy

### ✅ What We Test
- **Architecture**: Provider swappability, workflow modularity, no circular deps
- **Contracts**: Interfaces, abstract classes, naming conventions
- **Business Logic**: Workflow execution, data storage, trigger system
- **Integration Points**: Database schema, WordPress conventions

### ❌ What We Don't Test (Yet)
- **Full WordPress Runtime**: No WP core loaded (unit tests, not integration)
- **CRUD Operations**: Mocked for now (requires WP functions)
- **API Calls**: External services (OpenAI, Google Translate) not mocked yet
- **E2E Workflows**: No Playwright setup (planned for Phase 2)

---

## Adding New Tests

### Architecture Test
```php
// tests/Architecture/MyArchTest.php
arch('my rule description')
    ->expect('PolyTrans\\MyNamespace')
    ->not()->toUse('BadDependency');
```

### Unit Test
```php
// tests/Unit/MyUnitTest.php
test('my feature works', function () {
    $result = myFunction();
    expect($result)->toBeTrue();
});
```

---

## Troubleshooting

### Tests fail with "class not found"
**Cause**: Autoload not configured
**Fix**: Run `composer dump-autoload`

### Permission denied on test files
**Cause**: Docker root ownership
**Fix**: `docker compose -f docker-compose.test.yml run --rm --user root polytrans-test chown -R 1000:1000 tests/`

### MySQL connection failed
**Cause**: MySQL not started
**Fix**: `docker compose -f docker-compose.test.yml up -d mysql-test` first

### Composer network timeout
**Cause**: Docker network isolation
**Fix**: Use `network_mode: "host"` in docker-compose.test.yml (already configured)

---

## Performance

- **Execution Time**: ~0.25s (6 tests)
- **Memory**: <10MB peak
- **Parallel**: Supported via `--parallel` flag
- **Coverage**: Requires xdebug extension (not installed by default)

---

## Resources

- **Pest Docs**: https://pestphp.com/docs
- **Pest Arch Plugin**: https://pestphp.com/docs/arch-testing
- **PHPUnit Docs**: https://phpunit.de/documentation.html
- **WordPress Testing**: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/

---

## Next Steps

### Phase 1: Complete Unit Tests (2-3 days)
- [ ] WordPress function mocks (wpdb, get_option, update_option)
- [ ] WorkflowStorageManager CRUD tests (create, read, update, delete)
- [ ] Migration tests (wp_options → dedicated table)
- [ ] Data validation tests (sanitization, JSON encoding)

### Phase 2: Integration Tests (1 week)
- [ ] Full WordPress test environment (wp-env or wp-pest)
- [ ] Database integration (real MySQL queries)
- [ ] Workflow execution with real steps
- [ ] Provider integration (mocked API calls)

### Phase 3: E2E Tests (1 week)
- [ ] Playwright setup
- [ ] Admin UI workflow tests
- [ ] Translation flow tests
- [ ] Multi-tab scenarios

---

**Last Updated**: 2025-12-09
**Maintainer**: PolyTrans Team
**Status**: ✅ Production Ready (architecture tests)
