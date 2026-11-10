# PolyTrans Documentation Index

## 📚 Quick Links

### Getting Started

**New Users:**
1. [Quick Start Guide](../QUICK_START.md) - Get up and running in 5 minutes
2. [Installation Guide](user-guide/INSTALLATION.md) - Detailed setup instructions
3. [User Interface Guide](user-guide/INTERFACE.md) - Learn the admin interface
4. [FAQ](user-guide/FAQ.md) - Common questions and troubleshooting

**Administrators:**
1. [Configuration Guide](admin/CONFIGURATION.md) - Complete settings reference
2. [Workflow Management](admin/WORKFLOW-TRIGGERING.md) - Creating and managing workflows
3. [Workflow Output Actions](admin/WORKFLOW_OUTPUT_ACTIONS.md) - Complete actions reference
4. [Workflow Variables](admin/WORKFLOW_VARIABLES.md) - Available variables guide
5. [Workflow Logging](admin/WORKFLOW-LOGGING.md) - Monitoring execution

**Developers:**
1. [Development Setup](developer/DEVELOPMENT_SETUP.md) - Local dev environment
2. [Architecture](developer/ARCHITECTURE.md) - System design
3. [Provider Extensibility Guide](developer/PROVIDER_EXTENSIBILITY_GUIDE.md) - Adding custom providers
4. [Hooks and Filters](developer/HOOKS_AND_FILTERS.md) - Complete hook reference
5. [API Documentation](developer/API-DOCUMENTATION.md) - REST API reference
6. [Contributing](developer/CONTRIBUTING.md) - Contribution guidelines

**Contributors:**
1. [Contributing Guidelines](developer/CONTRIBUTING.md) - Code standards and workflow
2. [Testing Setup](testing/TESTING_SETUP.md) - How to run tests
3. [Architecture](developer/ARCHITECTURE.md) - Understanding the codebase

## 🏗️ Development Documentation

### Phase 0: Foundation (✅ Complete - v1.3.5)

Core infrastructure improvements completed in v1.3.0-1.3.5:
- **Twig Integration** - Modern templating engine
- **Variable Structure Refactor** - Clean, consistent variable access
- **Context Refresh Logic** - Multi-step workflow data consistency
- **UI Redesign** - Variable sidebar/pills responsive layout

### Current Development Plans
**Location**: `docs/development/`

- [Refactoring Plan](development/REFACTORING_PLAN.md) - DRY approach with design patterns
- [Quality Assessment](development/QUALITY_ASSESSMENT.md) - Code quality analysis and improvements roadmap
- [Docs Cleanup Plan](development/DOCS_CLEANUP_PLAN.md) - Documentation organization plan

### Roadmap
**Location**: `docs/roadmap/`

- [Version 1.7.0 Plan](roadmap/VERSION_1.7.0_PLAN.md) - Comprehensive refactoring & extensibility
- [Version 1.7.0 Proposal](roadmap/VERSION_1.7.0_PROPOSAL.md) - Initial extensibility proposal
- [Backlog](roadmap/BACKLOG.md) - Potential extensions without a target version


## 🧪 Testing Documentation

**Location**: `docs/testing/`

- [Testing Setup](testing/TESTING_SETUP.md) - How to run tests
- [Testing Best Practices](testing/TESTING_BEST_PRACTICES.md) - Writing good tests
- [Framework Research](testing/TESTING_FRAMEWORK_RESEARCH.md) - Why Pest PHP
- [Action Plan](testing/TESTING_ACTION_PLAN.md) - Testing roadmap

Quick start:
```bash
./run-tests.sh
```

## 📂 Documentation Structure

```
docs/
├── INDEX.md                    # This file
├── README.md                   # Docs overview
├── admin/                      # Admin documentation
├── user-guide/                 # End-user guides (includes ASSISTANTS.md)
├── developer/                  # Developer guides
├── development/                # Implementation details & plans
│   ├── REFACTORING_PLAN.md    # Refactoring plan (DRY approach)
│   ├── QUALITY_ASSESSMENT.md  # Quality assessment & improvements
│   └── DOCS_CLEANUP_PLAN.md   # Documentation cleanup plan
├── roadmap/                     # Version roadmaps
│   ├── VERSION_1.7.0_PLAN.md   # Comprehensive 1.7.0 plan
│   └── VERSION_1.7.0_PROPOSAL.md
└── testing/                    # Testing documentation
```

## 🎯 Current Status

**Version**: 1.6.14 → **1.7.0** (in development)  
**Phase**: Twig Migration Complete ✅  
**Next**: Version 1.7.0 - Comprehensive Refactoring & Extensibility

### What's Working
- ✅ Twig template engine with WordPress integration
- ✅ Clean variable structure (`original.*`, `translated.*`)
- ✅ Context refresh between workflow steps
- ✅ Responsive variable editor UI
- ✅ Markdown rendering in AI responses
- ✅ Reusable Prompt Editor module
- ✅ Testing infrastructure (Pest PHP)
- ✅ All admin pages migrated to Twig templates

### What's Next (Version 1.7.0)
- 🔄 Complete PSR-4 migration (377 references to fix)
- 🔄 Utility classes refactoring (HttpClient, BaseAjaxHandler, etc.)
- 🔄 Extensibility hooks system (27 new hooks)
- 🔄 Path resolution interceptors (modify pathing behavior)
- 🔄 Manifest extensions (custom endpoint handlers)
- 🔄 Documentation cleanup and reorganization

## 🔗 External Resources

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Twig Documentation](https://twig.symfony.com/doc/3.x/)
- [Pest PHP Documentation](https://pestphp.com/)

---

**Last Updated**: 2025-12-16  
**Version**: 1.7.0 (planning)  
**Maintained By**: PolyTrans Team

