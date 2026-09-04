const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

function unique(items) {
  return [...new Set(items.filter(Boolean))];
}

function candidateDrupalRoots(baseUrl, explicitRoot = '') {
  const hostLabel = baseUrl.hostname.split('.')[0] || '';
  const candidates = [];
  if (explicitRoot) candidates.push(explicitRoot);
  if (hostLabel) {
    candidates.push(
      `/var/www/html/build/${hostLabel}/web`,
      `/var/www/html/build/${hostLabel}`,
      `/var/www/html/${hostLabel}/web`,
      `/var/www/html/${hostLabel}`,
    );
  }
  const buildRoot = '/var/www/html/build';
  if (fs.existsSync(buildRoot)) {
    for (const entry of fs.readdirSync(buildRoot, { withFileTypes: true })) {
      if (!entry.isDirectory()) continue;
      candidates.push(path.join(buildRoot, entry.name, 'web'), path.join(buildRoot, entry.name));
    }
  }
  return unique(candidates).filter(candidate => fs.existsSync(candidate));
}

function candidateDrushExecutables(root, explicitDrush = '') {
  const projectRoot = path.basename(root) === 'web' ? path.dirname(root) : root;
  const candidates = [];
  if (explicitDrush) candidates.push(explicitDrush);
  candidates.push(
    path.join(projectRoot, 'vendor/bin/drush'),
    path.join(root, 'vendor/bin/drush'),
    'drush',
  );
  return unique(candidates);
}

function extractLoginUrl(output, baseUrl) {
  const matches = String(output || '').match(/https?:\/\/[^\s'"<>]+|\/user\/reset\/[^\s'"<>]+/g) || [];
  if (!matches.length) return '';
  const value = matches[matches.length - 1].replace(/[),.;]+$/, '');
  const resolved = new URL(value, baseUrl);
  return resolved.origin === baseUrl.origin ? resolved.href : '';
}

function runDrushLogin({ drush, root, baseUrl, username }) {
  const args = [
    `--root=${root}`,
    `--uri=${baseUrl.href}`,
    'user:login',
    `--name=${username}`,
    '--no-browser',
  ];
  return spawnSync(drush, args, {
    encoding: 'utf8',
    env: process.env,
    timeout: 15_000,
  });
}

/**
 * Resolve a local Drupal one-time login URL without changing the user's password.
 * Returns an empty string when Drush/bootstrap discovery is unavailable so callers
 * can fall back to normal Drupal form authentication.
 */
function resolveDrupalLoginUrl(options) {
  const baseUrl = options.baseUrl instanceof URL ? options.baseUrl : new URL(options.baseUrl);
  const username = options.username || 'admin';
  const explicitRoot = options.drupalRoot || process.env.CIVICFG_DRUPAL_ROOT || '';
  const explicitDrush = options.drush || process.env.CIVICFG_DRUSH || '';
  const roots = candidateDrupalRoots(baseUrl, explicitRoot);

  for (const root of roots) {
    for (const drush of candidateDrushExecutables(root, explicitDrush)) {
      if (drush.includes('/') && !fs.existsSync(drush)) continue;
      const result = runDrushLogin({ drush, root, baseUrl, username });
      if (result.error || result.status !== 0) continue;
      const loginUrl = extractLoginUrl(`${result.stdout || ''}\n${result.stderr || ''}`, baseUrl);
      if (loginUrl) return loginUrl;
    }
  }
  return '';
}

module.exports = {
  candidateDrupalRoots,
  extractLoginUrl,
  resolveDrupalLoginUrl,
};
