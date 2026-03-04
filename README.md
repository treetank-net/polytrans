# PolyTrans

Contributors: jmarianski
Tags: translation, multilingual, ai, openai, polylang
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.8.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress translation plugin with bring-your-own AI. Pay only for tokens, not subscriptions.

## Description

Connect your own AI provider (Google Translate, OpenAI, Claude, Gemini) — pay only for tokens consumed, not $200–800+/year in plugin subscriptions. Translation orchestration with post-processing workflows, multi-server architecture, and review management.

**The problem:** WordPress translation plugins like WPML ($299/yr), Weglot ($200–790/yr), and TranslatePress ($99–349/yr) charge flat subscription fees that scale with content volume, not actual usage. With Weglot, you lose all translations if you stop paying.

**PolyTrans takes a different approach:** bring your own AI account. The plugin connects to any AI provider you already use — you pay only for the tokens you actually consume. No vendor lock-in, no recurring license fees, and you always own your translations.

### Why PolyTrans?

| | WPML | Weglot | TranslatePress | PolyTrans |
|---|---|---|---|---|
| **Annual cost** | $299+ | $200–790+ | $99–349+ | Free (pay only AI tokens) |
| **Own your translations** | Yes | No (SaaS-hosted) | Yes | Yes |
| **AI provider choice** | None | Built-in only | Built-in only | Google, OpenAI, Claude, Gemini |
| **Post-processing workflows** | No | No | No | Yes (multi-step AI chains) |
| **Multi-server support** | No | No | No | Yes (sender/translator/receiver) |
| **Open source** | No | No | Freemium | Yes |

## 📚 Documentation

- **[Full Documentation Index](docs/INDEX.md)** - Complete documentation structure
- **[Quick Start Guide](QUICK_START.md)** - Get started in 5 minutes
- **[Changelog](CHANGELOG.md)** - Version history and changes

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Recent Improvements](#recent-improvements)
- [REST API Endpoints](#rest-api-endpoints)
- [Hooks and Filters](#hooks-and-filters)
- [Security Considerations](#security-considerations)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Requirements](#requirements)
- [License](#license)

## Features

### Core Translation Management
- **Translation Meta Box**: Mark posts as human or machine translated
- **Translation Scheduler**: Schedule automatic translations to multiple languages
- **Translation Status Tracking**: Monitor translation progress and logs
- **Review Workflow**: Assign reviewers for translation quality control

### Translation Provider System
The plugin uses a hot-pluggable provider architecture that supports:

- **Google Translate**: Simple, fast machine translation using public API (no API key required)
- **OpenAI Integration**: AI-powered translation with custom assistants
  - API key configuration required
  - Custom assistant mapping per language pair
  - Multi-step translation support for complex language workflows

### Post-Processing Workflows
Advanced workflow automation system for translated content:

- **AI-Powered Processing**: Custom AI assistants for content enhancement
- **Multi-Step Workflows**: Chain processing steps for complex transformations
- **Output Actions**: Automatically update post content, titles, meta, status, and scheduling
- **Test Mode**: Preview workflow changes before applying them
- **Comprehensive Logging**: Full audit trail of all workflow executions

### User Attribution System
- **Workflow Attribution**: Assign specific users to be credited for workflow changes
- **Post Creation Attribution**: Preserve original author attribution for translated posts
- **Clean Revision Logs**: Maintain proper authorship in WordPress revision history
- **Audit Trail**: Complete logging of all user context switches and changes

### Advanced Translation Architecture
- **Modular Receiver System**: Specialized manager classes for different aspects of translation processing
- **Translation Coordinator**: Orchestrates the entire translation process
- **Request Validation**: Comprehensive validation of incoming translation requests
- **Post Creation**: Robust post creation with proper sanitization and author attribution
- **Metadata Management**: Intelligent copying and translation of post metadata
- **Taxonomy Management**: Automatic category and tag translation mapping
- **Language Management**: Polylang integration with translation relationships
- **Security**: IP restrictions and authentication validation

### Communication Features
- **REST API Endpoints**: Receive translated content from external services
- **Email Notifications**: Notify reviewers and authors throughout the workflow
- **User Assignment**: Autocomplete user search for reviewer assignment

### Additional Features
- **Tag Translation Management**: Manage multilingual taxonomy translations
- **Polylang Integration**: Full compatibility with Polylang plugin
- **Flexible Language Configuration**: Configure allowed source and target languages
- **Status Management**: Set post status after translation (publish, draft, pending, same as source)

## Architecture

### Multi-Server Support
The plugin supports both single-server and multi-server translation workflows:

1. **Single Server (Local)**: All translation processing happens on the same WordPress installation
2. **Multi-Server**: Translation work can be distributed across multiple WordPress installations
   - **Scheduler Server**: Manages translation requests
   - **Translator Server**: Performs actual translation work
   - **Receiver Server**: Processes completed translations

### Provider System
The plugin implements a hot-pluggable provider architecture:
- **Translation Provider Interface**: Defines core translation functionality
- **Settings Provider Interface**: Handles provider-specific configuration UI
- **Provider Registry**: Automatic discovery and registration of translation providers
- **Dynamic Settings UI**: Providers automatically appear in settings with their own configuration tabs

### Workflow System
Advanced post-processing workflow engine:
- **Step Registry**: Pluggable step types for different processing tasks
- **Context Management**: Proper data flow between workflow steps
- **Output Processing**: Automated post updates based on workflow results
- **Attribution Management**: User context switching for proper change attribution

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 8.1 or higher
- **Composer**: Required for dependency management (Twig, PSR-4 autoloading)

## Installation

### For Production (from release)

1. Download the latest release (includes `vendor/` directory)
2. Upload the plugin files to `/wp-content/plugins/polytrans/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure settings under **PolyTrans > Settings**

### For Development (from source)

1. Clone the repository to `/wp-content/plugins/polytrans/`
2. **Install Composer dependencies** (required):
   ```bash
   cd /wp-content/plugins/polytrans/
   composer install
   ```
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure settings under **PolyTrans > Settings**

**⚠️ Important**: The plugin requires Composer dependencies (`vendor/autoload.php`) to function. If you see errors on activation, make sure you've run `composer install`.

## Configuration

### Basic Setup
1. Go to **Settings > Translation Settings**
2. Choose your translation provider (Google Translate or OpenAI)
3. Configure allowed source and target languages
4. Set up post status and reviewer settings for each language

### Google Translate Configuration
- **No setup required** - Google Translate works out of the box using the public API
- Simply select "Google Translate" as your provider

### OpenAI Configuration
1. Select "OpenAI" as translation provider
2. Enter your OpenAI API key
3. Choose your primary OpenAI language
4. Map assistants to language pairs (e.g., "en_to_pl" → "asst_123")
5. Test your configuration using the built-in testing interface

### Post-Processing Workflows
1. Navigate to **PolyTrans > Workflows** in WordPress admin
2. Create new workflows or edit existing ones
3. Configure workflow steps:
   - **AI Assistant Steps**: Use OpenAI for content processing
   - **Predefined Assistant Steps**: Use pre-configured prompts
   - **Output Actions**: Define what should be updated (content, status, scheduling, etc.) (title, content, meta, status, date, etc.)
4. Set up triggers for when workflows should run
5. Configure user attribution if needed

### Multi-Server Setup
1. Configure translation endpoint URLs for external translation services
2. Set up receiver endpoint for incoming translated content
3. Configure authentication secrets and methods
4. Customize email templates for notifications

## Usage

### Scheduling Translations
1. Edit any post or page
2. Use the "Translation" meta box to mark translation type
3. Use the "Translation Scheduler" meta box to:
   - Select translation scope (Local, Regional, Global)
   - Choose target languages for Regional scope
   - Enable review if needed
   - Click "Translate"

### Managing Post-Processing Workflows
1. Go to **PolyTrans > Workflows**
2. Create or edit workflows:
   - **Name and Description**: Basic workflow information
   - **Steps**: Configure AI processing steps
   - **Output Actions**: Define what should be updated
   - **Triggers**: Set when workflow should run
   - **Attribution User**: Optionally assign changes to specific user
3. Test workflows before enabling them
4. Monitor workflow execution through logs

### Managing Tag Translations
1. Go to **Posts > Tag Translations**
2. Add tags to translate in the tag list
3. Map translations for each language
4. Export/import CSV for bulk management

### Reviewing Translations
1. Reviewers receive email notifications when translations are ready
2. Edit the translated post to review content
3. Publish or update status as needed
4. Original author receives notification when published

## Recent Improvements

### 1. User Attribution Features

#### Workflow Attribution User
- **Feature**: New field in workflow settings to specify attribution user
- **Implementation**: User autocomplete field with backend validation
- **Benefits**: Clean revision logs, proper change attribution
- **Files Modified**: 
  - `includes/Menu/PostprocessingMenu.php` (PSR-4: `PolyTrans\Menu\PostprocessingMenu`)
  - `includes/PostProcessing/WorkflowOutputProcessor.php` (PSR-4: `PolyTrans\PostProcessing\WorkflowOutputProcessor`)
  - `assets/js/postprocessing-admin.js`
  - `assets/js/core/user-autocomplete.js`

#### Post Creation Attribution Fix
- **Issue**: Translated posts were attributed to system user instead of original author
- **Root Cause**: `post_author` field was not included in post creation array
- **Solution**: Modified `PolyTrans_Translation_Post_Creator::create_post()` to include original author
- **Benefits**: Proper author attribution from post creation, cleaner revision history
- **Files Modified**: 
  - `includes/Receiver/Managers/PostCreator.php` (PSR-4: `PolyTrans\Receiver\Managers\PostCreator`)
  - `includes/Receiver/Managers/MetadataManager.php` (PSR-4: `PolyTrans\Receiver\Managers\MetadataManager`)

### 2. Workflow Engine Enhancements

#### Context Updating Fix
- **Issue**: Subsequent workflow steps received stale data instead of updated values
- **Root Cause**: Execution context only updated in test mode, not production mode
- **Solution**: Updated context in both test and production modes, added database refresh
- **Benefits**: Proper data flow between workflow steps, correct step execution
- **Files Modified**: 
  - `includes/PostProcessing/WorkflowExecutor.php` (PSR-4: `PolyTrans\PostProcessing\WorkflowExecutor`)
  - `includes/PostProcessing/WorkflowOutputProcessor.php` (PSR-4: `PolyTrans\PostProcessing\WorkflowOutputProcessor`)

#### Action Counting Fix
- **Issue**: "Actions processed: 0" consistently shown in logs
- **Root Cause**: Data type mismatch between workflow executor and output processor
- **Solution**: Fixed executor to treat `processed_actions` as integer, not array
- **Benefits**: Accurate action counting, better debugging capability
- **Files Modified**: 
  - `includes/PostProcessing/WorkflowExecutor.php` (PSR-4: `PolyTrans\PostProcessing\WorkflowExecutor`)

### 3. Performance & Reliability
- **Consolidated Logging**: Unified logging system across all components
- **Better Validation**: Enhanced input validation and error handling
- **Test Mode**: Comprehensive test mode for workflow validation
- **Debug Support**: Extensive debugging tools and logging

### 4. AI-Driven Post Status and Scheduling

#### Post Status Automation
- **Feature**: AI workflows can now automatically set post status based on content analysis
- **Supported Actions**: `update_post_status` output action with intelligent status parsing
- **AI Response Formats**: Handles various status formats (published→publish, draft, pending review→pending, etc.)
- **Validation**: Validates against WordPress post status system with helpful error messages
- **Benefits**: Automated content quality gates, intelligent publishing workflows

#### Scheduled Publishing
- **Feature**: AI workflows can schedule posts for optimal publishing times
- **Supported Actions**: `update_post_date` output action with flexible date parsing  
- **Date Formats**: Supports MySQL, ISO, US/European formats, and natural language (tomorrow, next week)
- **WordPress Integration**: Automatically sets both `post_date` and `post_date_gmt` fields
- **Benefits**: AI-driven optimal timing, automated content scheduling

#### Example Use Cases
- **Quality Gates**: AI determines if translated content meets quality standards before publishing
- **Optimal Timing**: AI analyzes content and suggests best publish times based on audience and content type
- **Editorial Workflow**: AI routes content through different review stages (draft→pending→publish)
- **Seasonal Content**: AI schedules content based on relevance and timing analysis

#### Implementation Details
- **Files Modified**: `includes/PostProcessing/WorkflowOutputProcessor.php` (PSR-4: `PolyTrans\PostProcessing\WorkflowOutputProcessor`)
- **New Methods**: `update_post_status()`, `update_post_date()`, `parse_post_status()`, `parse_post_date()`
- **Error Handling**: Comprehensive validation with helpful error messages and format examples
- **Logging**: Full audit trail of all status and date changes with original AI responses

## REST API Endpoints

### Translation Endpoints
- **POST** `/wp-json/polytrans/v1/translation/translate` - Receive and process translation requests
- **POST** `/wp-json/polytrans/v1/translation/receive-post` - Receive completed translations

### Workflow Endpoints
- **POST** `/wp-json/polytrans/v1/workflows/test` - Test workflow execution
- **POST** `/wp-json/polytrans/v1/workflows/execute` - Execute workflow

### Authentication
All endpoints support configurable authentication:
- Bearer token in Authorization header
- Custom header (x-polytrans-secret)
- POST parameter

## Hooks and Filters

### Actions
- `polytrans_translation_before_create` - Before creating translated post
- `polytrans_translation_after_create` - After creating translated post
- `polytrans_translation_status_updated` - When translation status changes
- `polytrans_workflow_before_execute` - Before workflow execution
- `polytrans_workflow_after_execute` - After workflow execution

### Filters
- `polytrans_register_providers` - Register custom translation providers
- `polytrans_translation_allowed_meta_keys` - Modify allowed meta keys for translation
- `polytrans_translation_post_data` - Modify post data before creating translation
- `polytrans_translation_email_content` - Customize notification email content
- `polytrans_workflow_context` - Modify workflow execution context
- `polytrans_workflow_steps` - Register custom workflow step types

## 📖 Documentation

📋 **[Complete Documentation Index](docs/README.md)** - Find all documentation organized by audience and topic

### 👥 **For Users**
- 📦 [Installation Guide](docs/user-guide/INSTALLATION.md) - Get PolyTrans installed
- ⚡ [Quick Start Tutorial](QUICK_START.md) - First translation in 5 minutes
- 🖥️ [User Interface Guide](docs/user-guide/INTERFACE.md) - Learn the admin interface
- ❓ [FAQ & Troubleshooting](docs/user-guide/FAQ.md) - Common questions and solutions

### ⚙️ **For Administrators**
- 🔧 [Configuration Guide](docs/admin/CONFIGURATION.md) - Complete setup reference
- 🔄 [Workflow Management](docs/admin/WORKFLOW-TRIGGERING.md) - Automate post-processing
- 📝 [Workflow Logging](docs/admin/WORKFLOW-LOGGING.md) - Monitor system activity
- 📖 [Plain Text Workflow Guide](docs/admin/PLAIN_TEXT_WORKFLOW_GUIDE.md) - Advanced workflows
- ⚡ [Performance Tuning](docs/admin/PERFORMANCE.md) - Optimize for your environment
- 🔒 [Security Settings](docs/admin/SECURITY.md) - Secure your translation setup

### 🔧 **For Developers**
- 📡 [API Documentation](docs/developer/API-DOCUMENTATION.md) - REST API reference
- 🏗️ [Architecture Overview](docs/developer/ARCHITECTURE.md) - System design and structure
- 💻 [Development Setup](docs/developer/DEVELOPMENT_SETUP.md) - Local dev environment
- 🤝 [Contributing Guidelines](docs/developer/CONTRIBUTING.md) - How to contribute
- 🔌 [Plugin Hooks & Filters](docs/developer/HOOKS.md) - WordPress integration
- 📊 [Code Quality Status](docs/developer/CODE_QUALITY_STATUS.md) - Development status

### 📋 **Reference**
- 📝 [Changelog](CHANGELOG.md) - Version history and changes
- ⚙️ [Task Queue System](docs/reference/TASK_QUEUE.md) - Background processing
- 📊 [Workflow Logging](docs/admin/WORKFLOW-LOGGING.md) - System monitoring
- 📈 [Implementation Status](docs/reference/POLYTRANS_STATUS.md) - Current system status

### 🎯 **Examples & Use Cases**
- 📝 [Blog Post Translation](docs/examples/BLOG_POSTS.md) - Complete blog translation workflow
- 🛒 [E-commerce Translation](docs/examples/ECOMMERCE.md) - Product catalog translation
- 🎯 [Landing Page Translation](docs/examples/LANDING_PAGES.md) - Marketing content translation
- ⚙️ [SEO Workflow Example](examples/seo-internal-linking-workflow.php) - Live workflow code

## Security Considerations

### User Context Management
- User context switching only occurs during actual execution (not test mode)
- Original user context is always restored after workflow completion
- Comprehensive logging of all user context changes
- Validation of attribution user existence and permissions

### AJAX Security
- All AJAX endpoints require proper nonce verification
- User permission validation on all administrative functions
- Secure user search with capability restrictions

### API Security
- Configurable authentication methods for REST endpoints
- IP-based access restrictions
- Request validation and sanitization

## Testing

### Test Suite Location
All test files are located in `/tests/` directory:
- `test-attribution-user.php` - User attribution features
- `test-post-attribution.php` - Post creation attribution
- `test-context-updating.php` - Workflow context updating
- `test-actions-count.php` - Action counting validation
- `test-workflow-output.php` - Workflow execution testing
- And more...

### Running Tests
Tests can be run individually by accessing them through WordPress admin or web interface:
```
http://yoursite.com/wp-content/plugins/polytrans/tests/test-attribution-user.php
```

### Test Coverage
- Workflow execution and output processing
- User attribution and context switching
- Post creation and metadata management
- Context updating between workflow steps
- Action counting and logging

## Troubleshooting

### Common Issues

#### "Actions processed: 0" in logs
- **Status**: ✅ **Fixed** in latest version
- **Cause**: Data type mismatch between components
- **Solution**: Upgrade to latest version for automatic fix

#### Workflow steps receiving old data
- **Status**: ✅ **Fixed** in latest version
- **Cause**: Context only updated in test mode
- **Solution**: Upgrade to latest version for automatic fix

#### Attribution not working
- **Check**: Ensure attribution user exists and has valid permissions
- **Check**: Verify workflow configuration includes attribution user
- **Check**: Review logs for user context switching messages

#### Translation not preserving original author
- **Status**: ✅ **Fixed** in latest version
- **Cause**: Missing `post_author` field in post creation
- **Solution**: Upgrade to latest version for automatic fix

### Debug Mode
Enable debug logging by adding to wp-config.php:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `/wp-content/debug.log` for detailed execution information.

### Log Locations
- **WordPress Debug Log**: `/wp-content/debug.log`
- **PolyTrans Logs**: Database table `polytrans_logs`
- **Workflow Logs**: Accessible through admin interface

## Development

### Development Setup
1. Clone the repository
2. Install dependencies (if applicable)
3. Enable debug mode for development
4. Run tests to validate functionality

### Code Structure
```
polytrans/
├── assets/                 # CSS, JS, and other assets
├── includes/              # PHP classes and core functionality
│   ├── menu/             # Admin menu management
│   ├── postprocessing/   # Workflow system
│   ├── receiver/         # Translation processing
│   └── ...
├── tests/                # Test files
└── README.md            # This file
```

### Contributing
1. Follow WordPress coding standards
2. Add comprehensive tests for new features
3. Update documentation for any new functionality
4. Ensure all tests pass before submitting changes

## Requirements

### Core Requirements
- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Database**: MySQL 5.7+ or MariaDB 10.1+

### PHP Extensions
- **JSON Extension**: Required for API communication and data processing
- **cURL Extension**: Required for external API calls (OpenAI, Google Translate, external translation services)
- **mbstring Extension**: Required for proper Unicode/UTF-8 string handling in multilingual content
- **OpenSSL Extension**: Required for secure HTTPS communication with external APIs

### WordPress Features
- **REST API**: Plugin uses WordPress REST API extensively
- **AJAX**: Required for admin interface interactions
- **Cron/WP-Cron**: Required for background processing (can be disabled in favor of external cron)
- **Post Revisions**: Required for proper attribution tracking
- **User Capabilities**: Requires `edit_posts`, `manage_options`, and other standard WordPress capabilities

### Plugin Dependencies

#### Required
- **None** - The plugin works standalone

#### Recommended
- **Polylang**: For full multilingual support and automatic language detection
  - Version: 3.0 or higher recommended
  - Provides: Language detection, post language assignments, taxonomy translations
  - **Without Polylang**: Plugin falls back to default languages (Polish, English, Italian)
  - **Functionalities that break without Polylang**:
    - ❌ **Automatic language detection**: Cannot detect post language automatically
    - ❌ **Post language assignment**: New translated posts won't be assigned proper language
    - ❌ **Translation relationships**: Posts won't be linked as translations of each other
    - ❌ **Context article filtering**: Recent articles won't be filtered by target language
    - ❌ **Tag translation management**: Cannot properly manage multilingual taxonomy translations
    - ❌ **Language-specific content queries**: Falls back to hardcoded language list
    - ⚠️ **Limited language support**: Only supports Polish, English, Italian (hardcoded)

#### Optional but Useful
- **Yoast SEO**: For SEO metadata translation support
  - **With Yoast SEO**: 
    - ✅ **SEO metadata translation**: Yoast SEO fields are included in translation payload and translated
    - ✅ **Supported Yoast SEO fields** (included in translation):
      - `_yoast_wpseo_title`
      - `_yoast_wpseo_metadesc`
      - `_yoast_wpseo_focuskw`
      - `_yoast_wpseo_opengraph-title`
      - `_yoast_wpseo_opengraph-description`
      - `_yoast_wpseo_twitter-title`
      - `_yoast_wpseo_twitter-description`
    - ✅ **SEO context in workflows**: Yoast SEO data available in post processing workflows
    - ✅ **Search functionality**: Post search includes Yoast SEO fields
  - **Without Yoast SEO**: 
    - ❌ **No SEO metadata**: Yoast-specific fields won't be present or translated
    - ❌ **SEO context missing**: No Yoast data in workflows or search
    - ℹ️ **Core functionality intact**: Basic translation still works
    
- **RankMath**: For SEO metadata translation support
  - **With RankMath**: 
    - ✅ **SEO metadata translation**: RankMath fields are included in translation payload and translated
    - ✅ **Supported RankMath fields** (included in translation):
      - `rank_math_title`
      - `rank_math_description` 
      - `rank_math_facebook_title`
      - `rank_math_facebook_description`
      - `rank_math_twitter_title`
      - `rank_math_twitter_description`
      - `rank_math_focus_keyword`
    - ✅ **SEO context in workflows**: RankMath data available in post processing workflows
    - ✅ **Search functionality**: Post search includes RankMath SEO fields
  - **Without RankMath**: 
    - ❌ **No SEO metadata**: RankMath-specific fields won't be present or translated
    - ❌ **SEO context missing**: No RankMath data in workflows or search
    - ℹ️ **Core functionality intact**: Basic translation still works

### External API Requirements

#### For OpenAI Translation Provider
- **OpenAI API Key**: Required for AI-powered translation
- **OpenAI API Access**: Requires active OpenAI account with API credits
- **Internet Connection**: Required for API communication
- **HTTPS**: Required for secure API communication

#### For Google Translate Provider
- **No API Key Required**: Uses public Google Translate API
- **Internet Connection**: Required for translation requests
- **HTTPS**: Required for secure communication

### Server Requirements

#### Web Server
- **Apache** 2.4+ or **Nginx** 1.18+
- **HTTPS Support**: Recommended for secure API communication
- **URL Rewriting**: Required for WordPress permalinks

#### Memory and Performance
- **PHP Memory Limit**: 256MB minimum, 512MB recommended
- **PHP Execution Time**: 300 seconds recommended for translation processing
- **PHP Max Input Vars**: 3000+ recommended for complex workflows

#### File Permissions
- **WordPress uploads directory**: Must be writable for temporary files
- **Plugin directory**: Must be readable by web server

### Network Requirements
- **Outbound HTTPS**: Required for external API calls
- **Inbound HTTPS**: Required for receiving webhook translations
- **Firewall**: May need configuration for webhook endpoints

### Development/Testing Requirements
- **PHPUnit**: 9.0+ for running tests
- **Composer**: For dependency management
- **PHP_CodeSniffer**: For code quality checks
- **PHPMD**: For code analysis

### Browser Compatibility (Admin Interface)
- **Chrome**: 90+
- **Firefox**: 88+
- **Safari**: 14+
- **Edge**: 90+
- **JavaScript**: Must be enabled for admin interface

### Performance Considerations
- **Background Processing**: Plugin can use WordPress cron or external cron for better performance
- **Database**: Regular optimization recommended for large translation volumes
- **Caching**: Compatible with most WordPress caching plugins

### Security Considerations
- **SSL/TLS**: Required for production environments
- **API Key Security**: Store API keys securely (not in version control)
- **User Permissions**: Proper WordPress user role management
- **Input Validation**: All inputs are sanitized and validated

### Compatibility Notes
- **WordPress Multisite**: Not tested/supported
- **PHP 8.0+**: Fully compatible
- **WordPress 6.0+**: Fully compatible
- **Classic Editor**: Compatible
- **Gutenberg/Block Editor**: Compatible

## Plugin Check Notes

This plugin passes WordPress Plugin Check with **0 errors**. The remaining warnings are intentional architectural decisions:

### Direct Database Queries (DirectDatabaseQuery)

PolyTrans uses custom database tables (`polytrans_logs`, `polytrans_assistants`, `polytrans_workflows`, `polytrans_workflow_steps`) that require direct `$wpdb` queries. `WP_Query` does not apply to custom tables. Object caching is not used because log entries are write-heavy and workflow data changes frequently during AJAX operations.

### Interpolated Table Names (PreparedSQL.InterpolatedNotPrepared)

Table names are constructed via `$wpdb->prefix . 'polytrans_*'` and cannot be passed as `%s` parameters to `$wpdb->prepare()` — SQL does not allow parameterized identifiers. The prefix comes from `wp-config.php` and is trusted. Table names are backtick-quoted in queries.

### Template Output (EscapeOutput)

`TemplateRenderer::render()` returns HTML from Twig templates, which auto-escape all variables by default (`{{ var }}` applies `htmlspecialchars`). These outputs use `phpcs:disable` blocks with explanatory comments.

### error_log Usage

`error_log()` calls in API provider classes are intentional for debugging provider communication in production, guarded by `WP_DEBUG` checks.

## License

GPLv2 or later — see [LICENSE](LICENSE) for details.

## Links

- **Source code:** [gitlab.com/treetank/polytrans](https://gitlab.com/treetank/polytrans)
- **Case study:** [treetank.net/projects/polytrans](https://treetank.net/projects/polytrans)
- **Author:** [Jacek Mariański](https://treetank.net)

---

*Last updated: March 2026*
