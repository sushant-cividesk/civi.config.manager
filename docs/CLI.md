# Configuration Manager CLI

Configuration Manager has one CLI implementation:

```text
<extension>/bin/civicfg
```

The supported commands are:

```bash
civicfg status
civicfg diff
civicfg validate
civicfg watch
civicfg scope
civicfg scope-items --type message-templates
civicfg scope-set --type message-templates --mode selected --selector 'key:<portable-config-key>' --watch-unmanaged
civicfg cross-site-import
civicfg cross-site-import --allow
civicfg cross-site-import --deny
civicfg export --write
civicfg import --dry-run
civicfg import --yes
```

Use `civicfg --help` for the complete option list.

## Launcher architecture

The extension does not install copies of the CLI into project `bin` directories. The extension-owned `bin/civicfg` is always authoritative.

On install/enable, Configuration Manager may create two managed launchers:

1. `<composer-vendor>/bin/civicfg` when the current project has a writable Composer vendor directory.
2. One shared global `civicfg` in a safe writable directory already present in `PATH`, or in `CIVICFG_GLOBAL_BIN_DIR` when explicitly configured.

No launcher is installed in `<project>/bin`, a parent project `bin`, `/var/www/html/bin`, or multiple PATH directories.

The global launcher contains no project-specific extension path. At execution time it uses `cv` from `PATH`, or a sibling Composer `vendor/bin/cv` when the launcher is itself in `vendor/bin`, checks that `civi.config.manager` is enabled, asks CiviCRM's extension mapper for the current extension directory, and delegates to that installation's `bin/civicfg`.

This makes the same command usable from Composer and non-Composer projects, including legacy Drupal 7 sites. The global dispatcher requires `cv` in `PATH`; the Composer launcher can also use its sibling `vendor/bin/cv`.

## Composer projects

When the project has a Composer vendor directory, including a legacy Drupal site-local `sites/default/vendor`, either form can be used:

```bash
vendor/bin/civicfg status
civicfg status
```

Both launch the same extension-owned CLI implementation.

## Non-Composer projects

A Composer `vendor/bin` directory is not required. Run the shared command from a directory where `cv` can bootstrap the site:

```bash
cd /path/to/civicrm-site
civicfg status
```

The extension-local script is always available as a fallback:

```bash
/path/to/civicrm-extension-dir/civi.config.manager/bin/civicfg status
```

## Global installation directory

Configuration Manager never edits shell profiles or changes the parent shell's PATH.

Selection order is:

1. `CIVICFG_GLOBAL_BIN_DIR`, when explicitly set to a safe writable directory.
2. One suitable writable `bin` directory already in `PATH`.
3. A safe `bin` directory already advertised in `PATH` but not created yet (for example `$HOME/.local/bin`) when its existing parent is writable; Configuration Manager creates the directory and launcher.
4. No global installation. The extension-local CLI and Composer launcher, when available, continue to work.

Example:

```bash
export CIVICFG_GLOBAL_BIN_DIR="$HOME/.local/bin"
```

The selected directory still needs to be in PATH if the command should be callable by name from a new terminal.

## Ownership and uninstall

Generated launchers contain a Configuration Manager managed marker. Existing non-managed `civicfg` files are never overwritten or deleted.

A small local registry records which local project installations use the shared global launcher. The registry defaults to the normal user configuration location and can be overridden for testing or managed environments with:

```bash
export CIVICFG_REGISTRY_DIR=/path/to/private/config-directory
```

Each registration combines the CiviCRM site-family identifier with the local project root. This means DEV and STAGE can share a cloned CiviCRM site identifier and still register independently on the same machine.

On uninstall:

- the current project's managed `vendor/bin/civicfg` is removed;
- only the current project registration is removed;
- the shared global launcher remains while another registered local project still uses it;
- after the final registration is removed, Configuration Manager removes only its own managed global launcher and empty registry;
- unrelated administrator-created files are never removed.

The installer also removes obsolete Configuration Manager-managed alias wrappers created by older development builds. It never removes a same-named file without the managed marker.

## Configuration Scope and cross-site policy

The CLI uses the same API4 services as Settings, so deployment automation does not need to edit Configuration Manager's database settings directly.

```bash
# Read effective scope without scanning active records.
civicfg scope --json

# Lazily enumerate one type's current items and portable selectors.
civicfg scope-items --type message-templates --json

# Change one policy while preserving all other type policies.
civicfg scope-set --type scheduled-jobs --mode selected \
  --selector 'key:scheduled-jobs|Job|name=process_mailing' \
  --watch-unmanaged

# Inspect or explicitly change the reviewed cross-site import gate.
civicfg cross-site-import
civicfg cross-site-import --allow
civicfg cross-site-import --deny
```

`scope-set` accepts `all`, `selected`, `watch`, or `ignore`. `--selector` and `--watch-unmanaged` are valid only with `--mode selected`. A `civicrm.settings.php` scope override remains authoritative and prevents CLI/UI writes to the database-owned scope policy.

Cross-site import is disabled by default. Enabling it removes only the site-family mismatch gate; YAML validation, dependency checks, preview, permissions, and explicit import confirmation still apply.

## Status

`civicfg status` / `cv api4 ConfigManager.status` reports the configuration directory and CLI availability, including:

- extension CLI availability;
- Composer launcher path/availability;
- shared global launcher path/availability;
- local registry path and whether the current local project is registered.

Checking status is read-only. It does not reinstall or rewrite launchers.

## Examples

```bash
civicfg export --write
civicfg export --type searchkit-saved-searches --write
civicfg diff
civicfg validate
civicfg import --dry-run
civicfg import --yes
```

The equivalent API4 actions remain available through `cv api4 ConfigManager.*`.
