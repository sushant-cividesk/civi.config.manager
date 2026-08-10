# Configuration Manager CLI

Configuration Manager provides CLI commands for teams that prefer scripted export, import, diff, and validation.

## Commands

The main command is `civicfg`. Short aliases call the same backend:

| Command | Alias | Purpose |
| --- | --- | --- |
| `civicfg config-export` | `ce` | Export active CiviCRM config to YAML |
| `civicfg config-import` | `ci` | Import YAML config into CiviCRM |
| `civicfg config-diff` | `cdf` | Show pending differences |
| `civicfg config-validate` | `cval` | Validate YAML and dependency metadata |
| `civicfg status` | | Show sync directory and health |

## Project wrappers

On install/enable, the extension attempts to create managed wrappers in:

- `<cms-docroot>/bin`
- `<project-root>/bin` when the CMS docroot is named `web`
- `/var/www/html/bin` on DDEV/buildkit when writable

Existing non-managed files are never overwritten. The wrappers check that `civi.config.manager` is enabled before running. If the extension is disabled, they stop with a warning.

## Terminal PATH setup

On install/enable, Configuration Manager now creates project wrappers and sourceable PATH helpers. It does **not** edit shell profile files automatically, because doing that from a CiviCRM extension would be risky and user-specific.

For a one-time terminal session, source the generated helper from the project/shared bin directory:

```bash
. /var/www/html/bin/civicfg-env
```

Or from a project root:

```bash
. bin/civicfg-env
```

Then verify direct command access:

```bash
command -v civicfg
civicfg status
```

To make direct terminal access permanent, add one of these lines to the shell profile or project `.envrc` used by the developer/site:

```bash
export PATH="/var/www/html/bin:$PATH"
```

```bash
export PATH="$PWD/bin:$PATH"
```

For a terminal-level install, set an explicit writable bin directory before enabling or re-enabling the extension:

```bash
CIVICFG_GLOBAL_BIN_DIR="$HOME/.local/bin" cv ext:enable civi.config.manager
```

The installer also writes wrappers into known safe writable directories that are already in `PATH`, such as `/usr/local/bin`, `/opt/homebrew/bin`, `$HOME/bin`, `$HOME/.local/bin`, or `/var/www/html/bin`. Existing non-managed commands are never overwritten.

## DDEV/buildkit examples

```bash
cd /var/www/html/build/dcivi-dev
bin/ce --write
bin/cdf
bin/ci --dry-run
bin/ci --yes
bin/cval
```

If `/var/www/html/bin` is in your PATH, you can run:

```bash
civicfg status
ce --write
ci --dry-run
```

Otherwise call the shared wrapper directly:

```bash
/var/www/html/bin/civicfg status
/var/www/html/bin/ce --write
```

## Options

```bash
--type TYPE       Limit to a managed type. Repeat for multiple types.
--dry-run         Preview without changing anything.
--write           Write YAML files during export.
--yes, -y         Apply import.
--json            Ask cv for JSON output when supported.
-h, --help        Show help.
```

## Safety model

CLI commands call the same API4 backend as the UI, so these features are shared:

- Config Ignore paths and field-level ignore rules
- dependency expansion on export/import
- manifest site-identifier validation
- stale YAML deletion safeguards
- handler validation before import
- disabled-extension warning from project wrappers
