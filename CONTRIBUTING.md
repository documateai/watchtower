# Contributing to Watchtower

Thank you for your interest in contributing to Watchtower! This document provides guidelines and instructions for contributing.

## Code of Conduct

Please read our [Code of Conduct](CODE_OF_CONDUCT.md) before contributing.

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/YOUR-USERNAME/watchtower.git`
3. Create a feature branch: `git checkout -b feature/your-feature-name`
4. Install dependencies: `composer install`

## Development Setup

### Requirements

- PHP 8.2+
- Composer
- Redis server (optional -- only needed when testing the `redis` command bus driver)
- A Laravel application for integration testing

### Running Tests

```bash
composer test
```

### Code Style

This project follows PSR-12 coding standards. Run the fixer before committing:

```bash
composer format
```

## Making Changes

### Branch Naming

- `feature/` - New features
- `fix/` - Bug fixes
- `docs/` - Documentation updates
- `refactor/` - Code refactoring

### Commit Messages

Follow conventional commit format:

```
type(scope): description

feat(dashboard): add queue depth chart
fix(worker): handle Redis connection timeout
docs(readme): update installation instructions
```

### Pull Requests

1. Update documentation if needed
2. Add tests for new features
3. Ensure all tests pass
4. Update CHANGELOG.md with your changes
5. Create a PR with a clear description

## What We're Looking For

### Good First Issues

- Documentation improvements
- Additional test coverage
- Bug fixes with clear reproduction steps

### Feature Ideas

- Additional metrics and charts
- Queue priority visualization
- Worker auto-scaling algorithms
- Additional command bus drivers (e.g., file-based, APCu)

## Questions?

Open an issue with the "question" label or start a discussion.

---

Thank you for contributing! 🎉
