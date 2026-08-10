# Development Setup

## Quick Start

```bash
cd /wp-content/plugins/
git clone [repository-url] polytrans
cd polytrans
make setup    # Docker environment
```

## Requirements

- Docker & Docker Compose
- PHP 8.1+
- Composer
- Git

## Docker Setup (Recommended)

### Start Environment
```bash
make setup      # First-time setup
make dev        # Start services
make shell      # Access container
```

### Services
- WordPress: http://localhost:8080
- MySQL: localhost:3306
- PHPMyAdmin: http://localhost:8081

### Default Credentials
- Admin: admin / password
- DB: wordpress / wordpress

## Manual Setup

### Without Docker

1. **Clone repository**
   ```bash
   cd /wp-content/plugins/
   git clone [repo] polytrans
   cd polytrans
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure WordPress**
   ```php
   // wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('POLYTRANS_DEBUG', true);
   ```

4. **Activate plugin**
   - Admin → Plugins → Activate PolyTrans
   - Install & activate Polylang

## Development Commands

### Code Quality
```bash
make phpcs          # Check WordPress standards
make phpcbf         # Auto-fix style issues
make phpcs-syntax   # Quick syntax check
make phpcs-relaxed  # Security-focused check
```

### Testing
```bash
make test           # Run PHPUnit tests
make test-coverage  # With coverage report
```

### Docker
```bash
make dev            # Start services
make stop           # Stop services
make clean          # Remove all containers/volumes
make shell          # Access PHP container
make logs           # View logs
```

## File Structure

```
polytrans/
├── Makefile              # Dev commands
├── docker-compose.yml    # Docker config
├── composer.json         # PHP dependencies
├── phpunit.xml.dist      # Test config
├── phpcs.xml             # Coding standards config
├── includes/             # Source code
├── tests/                # PHPUnit tests
├── assets/               # CSS/JS
└── docs/                 # Documentation
```

## Coding Standards

**Follow WordPress Coding Standards:**

```bash
make phpcs          # Check all files
make phpcbf         # Auto-fix
```

**PHPDoc required:**
```php
/**
 * Brief description.
 *
 * @param int $post_id Post ID.
 * @return bool Success.
 */
public function method($post_id) {}
```

## Testing

### Write Tests
```php
// tests/test-feature.php
class Test_Feature extends WP_UnitTestCase {
    public function test_something() {
        $post_id = $this->factory->post->create();
        $result = my_function($post_id);
        $this->assertTrue($result);
    }
}
```

### Run Tests
```bash
make test                    # All tests
make test -- --filter TestClass  # Specific test
```

## Debugging

**Enable debug logging:**
```php
// wp-config.php
define('POLYTRANS_DEBUG', true);
```

**View logs:**
- `wp-content/debug.log` (WordPress)
- Admin → PolyTrans → Translation Logs (plugin)

**Xdebug (Docker):**
```bash
# Already configured in Docker
# Set breakpoints in IDE
# Access http://localhost:8080
```

## Git Workflow

```bash
# Create feature branch
git checkout -b feature/my-feature

# Make changes
git add .
git commit -m "feat: add feature"

# Push
git push origin feature/my-feature
```

**Commit format:** `type(scope): description`
- Types: `feat`, `fix`, `docs`, `refactor`, `test`

## Configuration

### Local wp-config.php
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('POLYTRANS_DEBUG', true);
define('SCRIPT_DEBUG', true);
```

### Docker Environment Variables
```bash
# .env file (create if needed)
WORDPRESS_DB_HOST=mysql
WORDPRESS_DB_NAME=wordpress
WORDPRESS_DB_USER=wordpress
WORDPRESS_DB_PASSWORD=wordpress
```

## Troubleshooting

**Docker won't start:**
```bash
make clean
make setup
```

**Permission errors:**
```bash
sudo chown -R $USER:$USER polytrans/
```

**Tests fail:**
```bash
# Reinstall test database
make test-install
make test
```

**Can't access localhost:8080:**
- Check Docker is running
- Check port not in use: `lsof -i :8080`
- Try different port in docker-compose.yml

## Resources

- [CONTRIBUTING.md](CONTRIBUTING.md) - Contribution guidelines
- [ARCHITECTURE.md](ARCHITECTURE.md) - Code structure
- [API-DOCUMENTATION.md](API-DOCUMENTATION.md) - API reference

## Next Steps

1. Run `make setup`
2. Access http://localhost:8080
3. Activate PolyTrans
4. Install Polylang
5. Configure translation provider
6. Start developing!
