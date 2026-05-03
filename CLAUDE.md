# Tidy Resize Images — Claude Context

WordPress plugin for keeping the Media Library lean. Resizes oversized
uploads, converts unsuitable formats (PNG → WebP/AVIF, HEIC → WebP),
and recompresses bloated files. Originals are backed up to a Trash
directory and can be restored. Operators mark assets "do not touch" to
exempt logos and brand artwork. Full WP-CLI surface mirrors the admin UI.

Built to fill the gaps in Imsanity (file-size-only problems and an
originals restore path).

## Read these first

1. **`dev-notes/00-project-tracker.md`** — current milestone, active TODO,
   completed work, and the "Notes for Development" section that captures
   cross-cutting decisions (AVIF policy, format-decision tree, failed-
   conversion memoisation, DB search-replace scope, HEIC handling).
   Always start here.
2. **`.github/copilot-instructions.md`** — portable WordPress coding
   standards (file structure, security, WP integration, JS patterns).
   Authoritative for everything not specific to this plugin.
3. **`dev-notes/patterns/`** — reusable patterns for tabs, settings,
   database migrations, caching, templates, AJAX.
4. **`dev-notes/workflows/`** — phpcs setup and the commit workflow.

## Hard constraints

These override anything else and must never be violated:

- **No `shell_exec` / `exec` / `proc_open` / `system`.** PHP GD or
  Imagick extensions only — never the ImageMagick CLI.
- **No Composer.** Plugin must work from a clean checkout.
- **No `declare(strict_types=1)`.** Per house style.
- **All templates code-first** (`printf`/`echo`) — never inline HTML
  with PHP snippets.
- **`phpcs` must be clean** before any commit.
- **Never auto-deactivate another plugin** in any code path. Surface a
  notice and let the operator decide.

## This plugin's conventions

- **Namespace:** `Tidy_Resize_Images` (all classes inside `includes/`).
- **Prefixes:** `tri_` for global symbols, `TRI_` for global constants,
  `Tidy_Resize_Images\` for namespaced symbols.
- **Entry-point file** (`tidy-resize-images.php`) stays in the **root**
  namespace so plugin path/version constants are reachable everywhere
  without qualification. Only classes inside `includes/` are namespaced.
- **Stateless helpers** live in `functions-private.php` (namespaced),
  not in single-purpose collaborator classes. Prefer a function over a
  one-method class.
- **When refactoring, delete the old code.** Do not leave commented-out
  implementations as breadcrumbs — phpcs flags them and they rot.
- **Single-Entry Single-Exit (SESE) is a strong preference, not a hard
  rule.** Early returns are fine for guard clauses (auth checks, nonce
  failures, missing/invalid inputs at the top of ajax handlers). Avoid
  multiple returns inside loops or branching logic — that's where SESE
  pays off, because tracing which path produced a value becomes
  painful with several scattered `return` statements. (NB:
  `.github/copilot-instructions.md` states SESE as a strict rule; this
  CLAUDE.md is the more accurate source for this project.)
- **Public API for other plugins (if ever needed):** create
  `functions.php` in the plugin root, in the **root namespace**, with
  `tri_`-prefixed functions. Reserved — we expect hooks and filters
  (`tri_format_decision` and friends) to cover most extensibility
  needs, so this file may never be created.

## Architectural philosophy

- **Testable in isolation.** Service classes (Image_Processor,
  Trash_Manager, Search_Replace) take inputs and return reports — no
  implicit DB or filesystem state, no mid-call I/O surprise. The
  hook-registration layer (`Plugin::run`) wires services to WordPress.
- **Filter-driven decisions.** Anywhere we decide *what to do*, the
  result is filterable so operators or third-party code can override.
  Example: `tri_format_decision`.
- **Plan / Result as associative arrays**, never value objects — keeps
  the filter chain simple and serialisable.
- **Image processor uses the temp-file model.** `execute()` produces a
  transformed file at a temp path and returns the path; the on-disk
  swap, DB rewrites, and backup happen in later steps (Trash_Manager
  and Search_Replace). This makes dry-run trivial: just don't swap.
- **Image library backend is a thin wrapper** that defaults to
  `WP_Image_Editor` but drops to raw GD/Imagick when we need encoder
  knobs WP doesn't expose (WebP `method`, AVIF `speed`, HEIC reading).
- **Originals are sacred.** Every destructive path backs up the source
  before touching it, unless the operator explicitly disables backups.
- **Dry-run is first-class** for every destructive operation.

## Project-specific environment

- **File ownership:** plugin files are owned by `www-devx:www-data`
  (the dev-site apache user). Shell user is `pfaulkner` (in `www-data`
  group). Git requires `safe.directory` configured for pfaulkner to
  operate on the repo.
- **Imsanity coexistence:** Imsanity (and 7 other image optimizers) are
  detected and surface a notice but **never auto-deactivated**. For dev
  work, deactivate Imsanity first via `wp plugin deactivate imsanity`.
- **Dev site:** https://devx.headwall.tech/ — docroot
  `/var/www/devx.headwall.tech/web/`. Run `wp` from the docroot.
- **GitHub:** `git@github.com:headwalluk/tidy-resize-images.git`,
  default branch `main`.

## Common commands

```bash
# Code standards (run from plugin directory).
phpcs
phpcbf
phpcs --report=summary

# WP-CLI (run from /var/www/devx.headwall.tech/web/).
wp plugin activate tidy-resize-images
wp plugin deactivate tidy-resize-images
wp plugin list --status=active
wp eval 'echo TRI_PLUGIN_VERSION;'
```

## Workflow

- **Track progress in `dev-notes/00-project-tracker.md`.** Tick boxes
  when work completes; move tasks into "Active TODO" when starting them.
- **Write CHANGELOG.md entries under `[Unreleased]`** for any user-
  visible change.
- **Commit cadence:** small, logical commits (e.g. `feat: capability
  detection`, `feat: image_processor plan/execute skeleton`) — not one
  giant per-milestone commit. Easier to bisect and review.
- **Pre-commit checklist:** `phpcs` clean → stage **specific files**
  (never `git add .`) → commit with a HEREDOC message.

## Authoritative file map

| Purpose                              | File / directory                              |
| ------------------------------------ | --------------------------------------------- |
| Plugin entry point                   | `tidy-resize-images.php`                      |
| Path/version constants (root ns)     | `tidy-resize-images.php` (`define()`)         |
| Plugin constants (namespaced)        | `constants.php`                               |
| Stateless helpers (namespaced)       | `functions-private.php`                       |
| Orchestrator / hook registration     | `includes/class-plugin.php`                   |
| Admin menu, notices, asset enqueueing| `includes/class-admin-hooks.php`              |
| Admin page templates (code-first)    | `admin-templates/`                            |
| Project tracker (read first!)        | `dev-notes/00-project-tracker.md`             |
| Coding standards                     | `.github/copilot-instructions.md`             |
| WP.org plugin readme                 | `readme.txt`                                  |
| GitHub readme                        | `README.md`                                   |
| Changelog                            | `CHANGELOG.md`                                |
| Operator/developer docs              | `docs/` (populated late in project)           |
