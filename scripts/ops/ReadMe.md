## Configuration Manager install automation

Use this script after a site rebuild to install the latest Configuration Manager release and create the first YAML baseline export.

### Script

```bash
scripts/ops/install-civicfg-latest.sh
```

### What it does

- Downloads the latest `civi.config.manager` release ZIP from GitHub.
- Installs it into the CiviCRM extensions directory.
- Enables `civi.config.manager`.
- Clears CiviCRM/CMS caches.
- Checks that the Configuration Manager API/menu is available.
- Checks that the `civicfg` CLI wrapper is available.
- Runs initial validation.
- Runs the first YAML baseline export.

### What it does not do

- It does not import configuration.
- It does not commit or push YAML files.
- It does not resolve conflicts.
- It does not send alerts.
- It does not take database/files backups.

### Required environment variables

```bash
export CIVICRM_ROOT=/var/www/html/web/wp-content/plugins/civicrm/civicrm/
export CIVICRM_SETTINGS=/var/www/html/web/wp-content/uploads/civicrm/civicrm.settings.php
export WEBROOT=/var/www/html/web
export ENV=prod
export APP_URL=cividesk.com,cividesk.ca
export APP=cividesk
```

### Run

```bash
bash -n scripts/ops/install-civicfg-latest.sh
scripts/ops/install-civicfg-latest.sh
```

### Optional: pin a release

```bash
export CIVICFG_RELEASE_TAG=0.1.0-beta2
scripts/ops/install-civicfg-latest.sh
```

### Reports

Reports are saved under:

```text
/tmp/civicfg-install/<app>-<env>-<timestamp>
```

Example:

```text
/tmp/civicfg-install/cividesk-prod-20260813-180500
```
