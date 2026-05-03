# Git Commit Workflow for WordPress Plugins

**Purpose:** Standard workflow for committing code changes with quality checks  
**Last Updated:** 10 January 2026

---

## Pre-Commit Checklist

Before committing any code changes, always:

1. ✅ Run code standards checks
2. ✅ Auto-fix what can be fixed
3. ✅ Manually fix remaining issues
4. ✅ Test the changes
5. ✅ Write a clear commit message

---

## Step-by-Step Workflow

### 1. Check for Code Standards Violations

```bash
# Check all PHP files
phpcs

# Check specific files/directories
phpcs includes/class-plugin.php
phpcs admin-templates/
```

**What it does:** Scans code against WordPress Coding Standards, reports violations.

### 2. Auto-Fix Issues

```bash
# Auto-fix all fixable issues
phpcbf

# Auto-fix specific files/directories
phpcbf includes/class-plugin.php
phpcbf admin-templates/
```

**What it does:** Automatically fixes spacing, indentation, formatting issues.

### 3. Re-Check After Auto-Fix

```bash
# Verify fixes were applied
phpcs
```

**If issues remain:** These require manual fixing (logic issues, security problems, naming violations).

### 4. Review Changes

```bash
# See what's changed
git status

# See detailed changes
git diff

# See changes for specific file
git diff includes/class-plugin.php
```

### 5. Stage Changes

```bash
# Stage all changes
git add .

# Stage specific files
git add includes/class-plugin.php
git add admin-templates/settings-page.php

# Stage interactively (recommended for large changes)
git add -p
```

### 6. Commit with Proper Message

```bash
git commit
```

**Commit message format:**

```
type: brief description (50 chars max)

- Detailed explanation point 1
- Detailed explanation point 2
- Why this change was necessary
- What problem it solves
```

**Types:**
- `feat:` - New feature
- `fix:` - Bug fix
- `refactor:` - Code restructuring (no behavior change)
- `chore:` - Maintenance (dependencies, configs, cleanup)
- `docs:` - Documentation only
- `style:` - Formatting, whitespace (no logic change)
- `test:` - Adding or updating tests

**Examples:**

```
feat: add ShipStation order import

- Implemented ShipStation_Api_Client class
- Added pull_orders() method with pagination
- Created order mapping to WooCommerce format
- Handles multiple marketplace sources via metadata

Closes #42
```

```
fix: prevent whitespace in textarea fields

- Changed textarea rendering to use printf/echo
- Prevents HTML whitespace bleeding into content
- Applied to all admin template textareas

Fixes #38
```

```
chore: update PHPCS configuration

- Added exclude patterns for vendor and node_modules
- Enabled parallel processing for faster checks
- Updated WordPress ruleset to latest version
```

### 7. Push to Remote

```bash
# Push to current branch
git push

# Push to specific branch
git push origin feature-branch

# First time pushing new branch
git push -u origin feature-branch
```

---

## Quick Reference Commands

```bash
# Full workflow in one go (if no manual fixes needed)
phpcs && phpcbf && phpcs && git add . && git commit

# After making changes
git status                    # What's changed?
git diff                      # See changes
phpcs                         # Check standards
phpcbf                        # Auto-fix
git add .                     # Stage all
git commit                    # Commit with message
git push                      # Push to remote
```

---

## Optional: Git Pre-Commit Hook

Automatically run phpcs before allowing commits.

**Setup:**

```bash
# Create hook file
cat > .git/hooks/pre-commit << 'EOF'
#!/bin/bash

echo "Running PHPCS before commit..."

# Run phpcs
phpcs_output=$(phpcs 2>&1)
phpcs_exit_code=$?

if [ $phpcs_exit_code -ne 0 ]; then
    echo "❌ PHPCS found errors. Please fix before committing."
    echo "$phpcs_output"
    echo ""
    echo "💡 Run 'phpcbf' to auto-fix, then 'phpcs' to verify."
    exit 1
fi

echo "✅ PHPCS passed. Proceeding with commit..."
exit 0
EOF

# Make executable
chmod +x .git/hooks/pre-commit
```

**What it does:** Prevents commits if phpcs finds violations.

**To bypass temporarily:**
```bash
git commit --no-verify
```

**To disable:**
```bash
rm .git/hooks/pre-commit
```

---

## Handling Large Changes

When making many changes across multiple files:

### 1. Commit Related Changes Together

```bash
# Stage only related files
git add includes/class-plugin.php includes/class-settings.php
git commit -m "refactor: extract settings logic to Settings class"

# Then commit other changes separately
git add admin-templates/settings-page.php
git commit -m "feat: add tabbed navigation to settings page"
```

### 2. Use Interactive Staging for Partial Commits

```bash
# Stage changes interactively (choose hunks)
git add -p includes/class-plugin.php
```

**Interactive options:**
- `y` - Stage this hunk
- `n` - Don't stage this hunk
- `s` - Split into smaller hunks
- `q` - Quit (don't stage remaining)
- `?` - Show help

---

## Troubleshooting

### PHPCS Reports False Positives

**Option 1:** Suppress with inline comment (use sparingly):
```php
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above
$data = $_POST['data'];
// phpcs:enable
```

**Option 2:** Update `phpcs.xml` to exclude specific rules project-wide.

### PHPCBF Changed Too Much

```bash
# Undo auto-fixes
git checkout -- .

# Or undo specific file
git checkout -- includes/class-plugin.php

# Then manually fix issues and commit
```

### Forgot to Run PHPCS Before Committing

```bash
# Amend last commit (if not pushed yet)
phpcs && phpcbf
git add .
git commit --amend

# If already pushed - create new commit
git commit -m "style: fix code standards violations"
```

---

## Best Practices

✅ **DO:**
- Run phpcs/phpcbf before every commit
- Write descriptive commit messages
- Commit related changes together
- Test changes before committing
- Review git diff before committing

❌ **DON'T:**
- Commit code with phpcs violations
- Make huge commits with unrelated changes
- Use vague commit messages ("fixed stuff", "updates")
- Commit commented-out code or debug statements
- Force push to shared branches

---

## Additional Resources

- **WordPress Coding Standards:** https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- **Git Best Practices:** https://git-scm.com/book/en/v2/Distributed-Git-Contributing-to-a-Project
- **Conventional Commits:** https://www.conventionalcommits.org/
