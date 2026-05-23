# Contributing to Parvion Laravel Log Pruner

First off — thank you for considering a contribution! 🎉  
Every bug report, suggestion, and pull request makes this package better.

---

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Ways to Contribute](#ways-to-contribute)
3. [Reporting a Bug](#reporting-a-bug)
4. [Requesting a Feature](#requesting-a-feature)
5. [Development Setup](#development-setup)
6. [Submitting a Pull Request](#submitting-a-pull-request)
7. [Coding Standards](#coding-standards)
8. [Versioning & Changelog](#versioning--changelog)
9. [License](#license)

---

## Code of Conduct

Be respectful and constructive. Harassment or discriminatory language in any
form will not be tolerated. We are here to build something great together.

---

## Ways to Contribute

| Type | How |
|---|---|
| 🐛 Bug report | Open a GitHub Issue with full reproduction steps |
| 💡 Feature request | Open a GitHub Issue describing the use case |
| 📝 Documentation | Edit `README.md`, `CHANGELOG.md`, or inline docblocks |
| 🔧 Code fix / feature | Fork → branch → PR (see workflow below) |
| ⭐ Star the repo | Helps other developers discover the package |

---

## Reporting a Bug

Before opening a new issue, please **search existing issues** to avoid
duplicates.

When you open a bug report, include:

```
**Package version:**  e.g. 1.0.0
**PHP version:**      e.g. 8.1
**Laravel version:**  e.g. 11.x
**OS / environment:** e.g. Ubuntu 22.04 / Forge

**Description:**
A clear description of what goes wrong.

**Steps to reproduce:**
1. Run `php artisan logs:rotate-and-prune --days=7`
2. See error ...

**Expected behaviour:**
What should have happened.

**Actual behaviour:**
What actually happened (paste the full error / stack trace).

**Config (sanitised):**
Paste your relevant config/log-pruner.php or .env values here.
```

---

## Requesting a Feature

Open a GitHub Issue with the label `enhancement`. Include:

- **Use case** — What problem does it solve?
- **Proposed API** — Show how you imagine using it (e.g. a new config key or CLI option)
- **Alternatives considered** — Any other approaches you thought of

---

## Development Setup

### 1 — Clone the package

```bash
git clone https://github.com/AnandKumar2002/laravel-log-pruner.git
cd laravel-log-pruner
```

### 2 — Install dependencies

```bash
composer install
```

### 3 — Link into a local Laravel app for manual testing

In your test Laravel app's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-log-pruner"
        }
    ],
    "require": {
        "parvion/laravel-log-pruner": "*"
    }
}
```

```bash
composer require parvion/laravel-log-pruner
php artisan vendor:publish --tag=log-pruner-config
php artisan logs:rotate-and-prune
```

---

## Submitting a Pull Request

### Workflow

```
1.  Fork the repository on GitHub
2.  Clone your fork locally
         git clone https://github.com/YOUR-USERNAME/laravel-log-pruner.git
3.  Create a branch off `main` with a descriptive name
         git checkout -b fix/backup-pruning-glob-pattern
         git checkout -b feat/custom-log-filename
4.  Make your changes (see Coding Standards below)
5.  Update CHANGELOG.md under [Unreleased]
6.  Commit with a clear message (see Commit Format below)
7.  Push to your fork
         git push origin your-branch-name
8.  Open a Pull Request against the `main` branch
```

### PR Checklist

Before opening a PR, confirm each item:

- [ ] My code follows the [Coding Standards](#coding-standards) below
- [ ] I have added or updated PHPDoc comments for any changed methods
- [ ] I have updated `CHANGELOG.md` under the `[Unreleased]` section
- [ ] I have tested the change manually in a local Laravel application
- [ ] I have not broken any existing behaviour without documenting the change
- [ ] My PR title clearly describes what changed and why

### Commit Message Format

Use this format for commit messages:

```
type: short description (max 72 chars)

Optional longer body explaining why the change was made.
```

**Types:**

| Type | When to use |
|---|---|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation only |
| `refactor` | Code change that neither fixes a bug nor adds a feature |
| `chore` | Tooling, CI, dependency updates |
| `test` | Adding or fixing tests |

**Examples:**

```
feat: add --dry-run option to preview pruning without deleting
fix: handle empty laravel.log edge case in Phase 1
docs: add ZeptoMail configuration example to README
refactor: extract resolveRetentionDays() into a dedicated method
```

---

## Coding Standards

This package targets **PHP 8.0+** and follows the conventions already established
in the codebase. Please match the existing style:

### PHP

- `declare(strict_types=1)` at the top of every PHP file
- Full PHPDoc blocks on every `public` and `private` method
- Inline comments explaining *why*, not just *what*
- Type declarations on all parameters and return types
- One class per file, namespace matching directory structure
- No external dependencies beyond what Laravel already provides

### Style

- 4-space indentation (no tabs)
- Spaces around `=>` in arrays
- Aligned `=>` within a single array block where it aids readability
- Section dividers (`// ===...` and `// ---...`) inside large methods

### Config Keys

- Snake_case for all config keys
- Every new config key must have a `.env` variable with a sensible default
- Document every new key in `config/log-pruner.php` with a plain-English comment block

### Changelog

Add an entry under `[Unreleased]` in `CHANGELOG.md` for every change:

```markdown
## [Unreleased]

### Added
- Brief description of what was added

### Changed
- Brief description of what was modified

### Fixed
- Brief description of what was fixed

### Removed
- Brief description of what was removed
```

---

## Versioning & Changelog

This package uses [Semantic Versioning](https://semver.org):

| Change type | Version bump | Example |
|---|---|---|
| Breaking change (renamed config key, removed option) | **MAJOR** | `1.0.0` → `2.0.0` |
| New feature (new phase, new config option) | **MINOR** | `1.0.0` → `1.1.0` |
| Bug fix, docs, internal refactor | **PATCH** | `1.0.0` → `1.0.1` |

When a release is made:
1. All `[Unreleased]` entries are moved under a new dated version heading
2. The `[Unreleased]` compare link and new version link are updated at the bottom of `CHANGELOG.md`
3. A Git tag is pushed: `git tag v1.1.0 && git push origin v1.1.0`

---

## License

By submitting a pull request, you agree that your contribution will be
licensed under the [MIT License](LICENSE) that covers this project.

---

*Made with ❤️ by [Anand Kumar (Parvion)](mailto:anandkumar101002@gmail.com)*
