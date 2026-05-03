# Code Standards Setup for WordPress Plugins

**Purpose:** Setting up PHP_CodeSniffer with WordPress Coding Standards  
**Last Updated:** 10 January 2026

---

## Installation

### 1. Install PHP_CodeSniffer

```bash
# Via Composer (recommended)
composer require --dev squizlabs/php_codesniffer
composer require --dev wp-coding-standards/wpcs
composer require --dev phpcsstandards/phpcsextra
composer require --dev phpcsstandards/phpcsutils

# Configure installed paths
./vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs,vendor/phpcsstandards/phpcsextra
```

**Or install globally:**

```bash
composer global require squizlabs/php_codesniffer
composer global require wp-coding-standards/wpcs
composer global require phpcsstandards/phpcsextra
composer global require phpcsstandards/phpcsutils

# Configure installed paths
phpcs --config-set installed_paths ~/.composer/vendor/wp-coding-standards/wpcs,~/.composer/vendor/phpcsstandards/phpcsextra
```

### 2. Verify Installation

```bash
# Check available standards
phpcs -i

# Should show: WordPress, WordPress-Core, WordPress-Docs, WordPress-Extra, etc.
```

---

## Configuration File

Create `phpcs.xml` in your plugin root:

```xml
<?xml version="1.0"?>
<ruleset name="Plugin Name Standards">
	<description>WordPress Coding Standards for Your Plugin</description>
	
	<file>.</file>
	
	<!-- Exclude patterns -->
	<exclude-pattern>*/vendor/*</exclude-pattern>
	<exclude-pattern>*/node_modules/*</exclude-pattern>
	<exclude-pattern>*/assets/*</exclude-pattern>
	<exclude-pattern>*/.git/*</exclude-pattern>
	<exclude-pattern>*/dev-notes/*</exclude-pattern>
	
	<!-- Arguments -->
	<arg value="sp"/>
	<arg name="basepath" value="."/>
	<arg name="colors"/>
	<arg name="extensions" value="php"/>
	<arg name="parallel" value="8"/>
	
	<!-- Rules -->
	<rule ref="WordPress">
		<exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
	</rule>
	
	<!-- Prefixes -->
	<rule ref="WordPress.NamingConventions.PrefixAllGlobals">
		<properties>
			<property name="prefixes" type="array">
				<element value="your_plugin"/>
				<element value="yp"/>
				<element value="Your_Plugin"/>
			</property>
		</properties>
	</rule>
</ruleset>
```

---

## Customization Guide

### Update Plugin Prefixes

Replace the prefixes in `WordPress.NamingConventions.PrefixAllGlobals` with your plugin's:

```xml
<property name="prefixes" type="array">
	<element value="example_plugin"/>      <!-- Snake case -->
	<element value="ep"/>                  <!-- Short prefix -->
	<element value="Example_Plugin"/>      <!-- Namespace -->
</property>
```

### Exclude Additional Directories

Add more patterns if needed:

```xml
<exclude-pattern>*/build/*</exclude-pattern>
<exclude-pattern>*/dist/*</exclude-pattern>
<exclude-pattern>*/tests/*</exclude-pattern>
```

### Allow Short Array Syntax

The example excludes the short array syntax rule (allows `[]` instead of `array()`):

```xml
<rule ref="WordPress">
	<exclude name="Generic.Arrays.DisallowShortArraySyntax"/>
</rule>
```

Remove this exclusion if you want to enforce `array()` syntax.

### Performance Settings

```xml
<arg value="sp"/>              <!-- Show progress and sniff codes -->
<arg name="basepath" value="."/>  <!-- Strip base path from file paths -->
<arg name="colors"/>           <!-- Colorize output -->
<arg name="extensions" value="php"/>  <!-- Only check PHP files -->
<arg name="parallel" value="8"/>  <!-- Use 8 parallel processes -->
```

---

## Usage Commands

```bash
# Check all files
phpcs

# Check specific files/directories
phpcs includes/
phpcs includes/class-plugin.php

# Auto-fix issues
phpcbf

# Auto-fix specific files/directories
phpcbf includes/
phpcbf includes/class-plugin.php

# Show detailed report
phpcs --report=full

# Show summary only
phpcs --report=summary

# Save report to file
phpcs --report=json > phpcs-report.json
```

---

## Common Exclusions

### Inline Suppressions

```php
// Disable a specific rule for next line
// phpcs:ignore WordPress.Security.NonceVerification.Missing
$data = $_POST['data'];

// Disable multiple rules
// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$data = $_POST['data'];

// Disable for a block
// phpcs:disable WordPress.Security.NonceVerification.Missing
$data1 = $_POST['data1'];
$data2 = $_POST['data2'];
// phpcs:enable WordPress.Security.NonceVerification.Missing

// Disable with explanation (preferred)
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above
$data = $_POST['data'];
// phpcs:enable
```

---

## Integration with VS Code

Install the **PHP Sniffer & Beautifier** extension:

1. Install extension: `wongjn.php-sniffer`
2. Add to `.vscode/settings.json`:

```json
{
	"phpSniffer.standard": "phpcs.xml",
	"phpSniffer.autoDetect": true,
	"phpSniffer.run": "onSave",
	"[php]": {
		"editor.defaultFormatter": "wongjn.php-sniffer",
		"editor.formatOnSave": false
	}
}
```

---

## Git Pre-Commit Hook

See [`commit-to-git.md`](commit-to-git.md#optional-git-pre-commit-hook) for automated PHPCS checking before commits.

---

## Troubleshooting

### "WordPress" standard not found

```bash
# Check installed standards
phpcs -i

# If WordPress is missing, reinstall WPCS
composer require --dev wp-coding-standards/wpcs

# Configure path
phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
```

### PHPCS is slow

```bash
# Increase parallel processes
<arg name="parallel" value="16"/>

# Or reduce checked files
<exclude-pattern>*/legacy/*</exclude-pattern>
```

### False positives

Use inline suppressions sparingly and always include explanatory comments:

```php
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above
```

---

## Best Practices

✅ **DO:**
- Run phpcs before every commit
- Use `phpcbf` to auto-fix when possible
- Add explanations to inline suppressions
- Keep phpcs.xml in version control
- Exclude generated/vendor code

❌ **DON'T:**
- Disable rules globally without good reason
- Use inline suppressions without explanation
- Commit code with violations
- Exclude too much (defeats the purpose)

---

## Additional Resources

- **PHP_CodeSniffer:** https://github.com/squizlabs/PHP_CodeSniffer
- **WordPress Coding Standards:** https://github.com/WordPress/WordPress-Coding-Standards
- **WordPress Developer Handbook:** https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
