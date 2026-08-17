  {if $op eq 'settings'}
    <h3>{ts}Settings{/ts}</h3>
    <form class="civicfg-settings-form" method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=settings'}">
      <input type="hidden" name="_action" value="save_settings" />
      <table class="form-layout-compressed">
        <tr>
          <td class="label"><label for="sync_dir">{ts}Sync Directory{/ts}</label></td>
          <td>
            {if $syncDirLocked}
              <input type="text" class="crm-form-text huge" size="90" id="sync_dir" name="sync_dir_display" value="{$syncDir|escape}" disabled="disabled" />
              <input type="hidden" name="sync_dir" value="{$syncDir|escape}" />
              <div class="messages status no-popup civicfg-inline-message">{$syncDirLockMessage|escape}</div>
            {else}
              <input type="text" class="crm-form-text huge" size="90" id="sync_dir" name="sync_dir" value="{$syncDir|escape}" />
            {/if}
            <p class="description">{ts}Use one directory per CiviCRM build. Relative paths are resolved from the CMS project root. Absolute paths are accepted. The path must be a server-local directory writable by the web/PHP user; URLs are not accepted. Export creates the directory when the parent is writable. Example: civicrm-config or /var/www/html/civicrm-buildkit/build/drupal-civi/civicrm-config{/ts}</p>
          </td>
        </tr>

        <tr>
          <td class="label">{ts}Site Identifier{/ts}</td>
          <td>
            <code class="civicfg-site-id">{$siteId|escape}</code>
            <p class="description">{ts}Generated automatically and stored in CiviCRM settings. A cloned dev/stage/prod database keeps the same identifier, so normal same-site environment sync works without manual setup. A separate site receives a different identifier and is blocked from import unless experimental cross-site import is enabled below.{/ts}</p>
          </td>
        </tr>
        <tr>
          <td class="label">{ts}Cross-site Import{/ts}</td>
          <td>
            <label class="civicfg-experimental"><input type="checkbox" name="allow_cross_site_import" value="1" {if $allowCrossSiteImport}checked="checked"{/if} /> {ts}Experimental: allow reviewed cross-site import when the manifest site identifier does not match this site{/ts}</label>
            <p class="description">{ts}Keep this disabled for normal dev/stage/prod sync. Enable only when intentionally migrating reviewed YAML between different sites. Validation still runs before import, and import remains manual.{/ts}</p>
          </td>
        </tr>
        <tr>
          <td class="label">{ts}Configuration Scope{/ts}</td>
          <td>
            <p class="description">{ts}Choose what Configuration Manager manages, watches, or ignores. Managed configuration participates in YAML export/import. Watch-only configuration is monitored locally and is never imported, restored, or deleted.{/ts}</p>
            {if $scopeOverridden}
              <div class="messages status no-popup civicfg-inline-message">
                <strong>{ts}Scope is controlled by civicrm.settings.php.{/ts}</strong>
                {ts}The effective values are shown below, but they cannot be changed from this page.{/ts}
              </div>
            {/if}
            <div class="civicfg-scope-table-wrap">
              <table class="display civicfg-scope-table">
                <thead>
                  <tr>
                    <th>{ts}Configuration{/ts}</th>
                    <th>{ts}Mode{/ts}</th>
                    <th>{ts}Selected items{/ts}</th>
                    <th>{ts}Watch the rest{/ts}</th>
                  </tr>
                </thead>
                <tbody>
                  {foreach from=$scopeRows item=row}
                    <tr>
                      <td>
                        <strong>{$row.label|escape}</strong>
                        <div class="civicfg-muted"><code>{$row.type|escape}</code></div>
                      </td>
                      <td>
                        <select name="scope_mode[{$row.type|escape}]" class="crm-form-select" {if $scopeOverridden}disabled="disabled"{/if}>
                          <option value="all" {if $row.mode_all}selected="selected"{/if}>{ts}Manage all{/ts}</option>
                          <option value="selected" {if $row.mode_selected}selected="selected"{/if}>{ts}Manage selected{/ts}</option>
                          <option value="watch" {if $row.mode_watch}selected="selected"{/if}>{ts}Watch only{/ts}</option>
                          <option value="ignore" {if $row.mode_ignore}selected="selected"{/if}>{ts}Ignore{/ts}</option>
                        </select>
                      </td>
                      <td>
                        <textarea name="scope_selectors[{$row.type|escape}]" class="crm-form-textarea civicfg-scope-selectors" rows="3" {if $scopeOverridden}disabled="disabled"{/if}>{$row.selectors_text|escape}</textarea>
                        <div class="civicfg-muted">{ts}Used only with Manage selected.{/ts}</div>
                      </td>
                      <td>
                        <label>
                          <input type="checkbox" name="scope_watch_unmanaged[{$row.type|escape}]" value="1" {if $row.watch_unmanaged}checked="checked"{/if} {if $scopeOverridden}disabled="disabled"{/if} />
                          {ts}Watch unselected{/ts}
                        </label>
                      </td>
                    </tr>
                  {/foreach}
                </tbody>
              </table>
            </div>
            <p class="description">{$scopeSelectorHelp|escape} {ts}Numeric IDs are source-site selectors only; exported YAML uses portable semantic identities instead of relying on database IDs.{/ts}</p>
            <details class="civicfg-settings-example">
              <summary>{ts}Override scope in civicrm.settings.php{/ts}</summary>
              <p class="description">{ts}The normal CiviCRM settings override wins over values saved in this UI. This is useful when scope should be deployment-controlled on DEV, STAGE, or PROD.{/ts}</p>
              <pre><code>{$scopeSettingsExample|escape}</code></pre>
            </details>
          </td>
        </tr>
        <tr>
          <td class="label"><label for="settings_allowlist">{ts}Settings Allowlist{/ts}</label></td>
          <td>
            <textarea id="settings_allowlist" name="settings_allowlist" class="crm-form-textarea">{$settingsAllowlist|escape}</textarea>
            <p class="description">{ts}Safety allowlist for CiviCRM settings that Configuration Manager may inspect. Each safe setting is exported as its own YAML item, so Configuration Scope can manage selected settings or watch the rest. Add one setting name per line. Do not add secrets.{/ts}</p>
          </td>
        </tr>

        <tr>
          <td class="label"><label for="ignore_values">{ts}Config Ignore Values{/ts}</label></td>
          <td>
            <textarea id="ignore_values" name="ignore_values" class="crm-form-textarea">{$ignoreValues|escape}</textarea>
            <p class="description">{ts}Optional field-level ignore rules. Use one rule per line in the format path/to/file.yml:dot.path. Wildcards are allowed in the YAML path and * is allowed as a path segment. Example: settings/theme_backend.yml:item.value or extensions/*.yml:settings.environment_color. Ignored values are removed before diff, export, import, and single-file preview so each environment can keep local values while the rest of the file remains managed.{/ts}</p>
          </td>
        </tr>
        <tr>
          <td class="label"><label for="ignore_paths">{ts}Config Ignore{/ts}</label></td>
          <td>
            <textarea id="ignore_paths" name="ignore_paths" class="crm-form-textarea">{$ignorePaths|escape}</textarea>
            <p class="description">{ts}One relative YAML path or wildcard per line. Ignored files are skipped during diff, validate, export, import, single-file preview, and ZIP download. The Configuration Manager extension YAML is ignored by default to avoid self-management loops; remove that line only if you intentionally want to manage this extension state from YAML. Do not ignore files that are required dependencies of non-ignored YAML files; validation will warn/block when it can detect that risk.{/ts}</p>
          </td>
        </tr>
      </table>
      <div class="crm-submit-buttons"><button type="submit" class="button"><span>{ts}Save{/ts}</span></button></div>
    </form>
  {/if}
