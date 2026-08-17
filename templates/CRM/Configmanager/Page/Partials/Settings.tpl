  {if $op eq 'settings'}
    <h3>{ts}Settings{/ts}</h3>
    <form class="civicfg-settings-form" method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=settings'}" data-civicfg-scope-options-url="{$scopeOptionsUrl|escape}">
      <input type="hidden" name="_action" value="save_settings" />

      <div class="civicfg-settings-section">
        <h4>{ts}Configuration files{/ts}</h4>
        <div class="civicfg-settings-field">
          <label for="sync_dir"><strong>{ts}Sync Directory{/ts}</strong></label>
          <div>
            {if $syncDirLocked}
              <input type="text" class="crm-form-text huge" size="90" id="sync_dir" name="sync_dir_display" value="{$syncDir|escape}" disabled="disabled" />
              <input type="hidden" name="sync_dir" value="{$syncDir|escape}" />
              <div class="messages status no-popup civicfg-inline-message">{$syncDirLockMessage|escape}</div>
            {else}
              <input type="text" class="crm-form-text huge" size="90" id="sync_dir" name="sync_dir" value="{$syncDir|escape}" />
            {/if}
            <p class="description">{ts}Use one directory per CiviCRM build. Relative paths are resolved from the CMS project root. The directory must be server-local and writable by the web/PHP user. Export creates it when the parent is writable.{/ts}</p>
          </div>
        </div>
      </div>

      <div class="civicfg-settings-section">
        <h4>{ts}What should Configuration Manager manage?{/ts}</h4>
        <p class="description">{ts}Every registered configuration type is listed below. Choose full management, selected-item management, monitoring only, or ignore. Third-party providers that do not expose a safe portable identity remain backup/monitor-only instead of being written automatically.{/ts}</p>
        <div class="civicfg-mode-help civicfg-mode-help-all" data-civicfg-mode-help="all">
          {ts}All supported items are part of managed YAML and the normal export, diff, validation, and safe restore/import workflow.{/ts}
        </div>
        {if $scopeOverridden}
          <div class="messages status no-popup civicfg-inline-message">
            <strong>{ts}Configuration scope is controlled by civicrm.settings.php.{/ts}</strong>
            {ts}The effective values are shown below. Change the deployment configuration file to update them.{/ts}
          </div>
        {/if}

        <div class="civicfg-scope-grid">
          {foreach from=$scopeRows item=row}
            <section class="civicfg-scope-card" data-civicfg-scope-row="{$row.type|escape}" data-scope-label="{$row.label|escape}">
              <div class="civicfg-scope-card-header">
                <div>
                  <strong class="civicfg-scope-title">{$row.label|escape}</strong>
                  <div class="civicfg-muted"><code>{$row.type|escape}</code></div>
                </div>
                <span class="civicfg-capability civicfg-capability-{$row.capability|escape}" title="{$row.capability_help|escape}">{$row.capability_label|escape}</span>
              </div>

              <label class="civicfg-scope-mode-label" for="scope-mode-{$row.type|escape}">{ts}How should this configuration be handled?{/ts}</label>
              <select id="scope-mode-{$row.type|escape}" name="scope_mode[{$row.type|escape}]" class="crm-form-select civicfg-scope-mode" data-civicfg-scope-mode {if $scopeOverridden}disabled="disabled"{/if}>
                <option value="all" {if $row.mode_all}selected="selected"{/if}>{ts}Manage everything{/ts}</option>
                <option value="selected" {if $row.mode_selected}selected="selected"{/if}>{ts}Manage selected items{/ts}</option>
                <option value="watch" {if $row.mode_watch}selected="selected"{/if}>{ts}Monitor only{/ts}</option>
                <option value="ignore" {if $row.mode_ignore}selected="selected"{/if}>{ts}Ignore{/ts}</option>
              </select>

              <div class="civicfg-mode-help civicfg-mode-help-selected" data-civicfg-mode-help="selected">
                {ts}Only the items you choose are managed. Other items can optionally be monitored without entering YAML.{/ts}
              </div>
              <div class="civicfg-mode-help civicfg-mode-help-watch" data-civicfg-mode-help="watch">
                {ts}Changes are monitored locally. Nothing from this type is exported, restored, imported, or deleted by Configuration Manager.{/ts}
              </div>
              <div class="civicfg-mode-help civicfg-mode-help-ignore" data-civicfg-mode-help="ignore">
                {ts}Configuration Manager does not manage or monitor this type.{/ts}
              </div>

              <div class="civicfg-selected-controls" data-civicfg-selected-controls {if !$row.mode_selected}hidden="hidden"{/if}>
                <div class="civicfg-selected-summary">
                  <strong>{ts}Managed items{/ts}</strong>
                  <span class="civicfg-muted" data-civicfg-scope-count>{if $row.selector_count gt 0}{$row.selector_count|escape} {ts}selected{/ts}{else}{ts}None selected yet{/ts}{/if}</span>
                </div>
                <button type="button" class="button civicfg-scope-picker-button" data-civicfg-scope-picker="{$row.type|escape}" {if $scopeOverridden}disabled="disabled"{/if}><span>{ts}Choose items{/ts}</span></button>
                <div class="civicfg-selected-chips" data-civicfg-selected-chips></div>

                <label class="civicfg-watch-rest">
                  <input type="checkbox" name="scope_watch_unmanaged[{$row.type|escape}]" value="1" {if $row.watch_unmanaged}checked="checked"{/if} {if $scopeOverridden}disabled="disabled"{/if} />
                  <span>{ts}Monitor everything else in this type{/ts}</span>
                </label>

                <details class="civicfg-advanced-selection">
                  <summary>{ts}Advanced selection{/ts}</summary>
                  <p class="description">{ts}Normally use Choose items above. Advanced selectors are useful for deployment automation or an item that is temporarily missing from CiviCRM.{/ts}</p>
                  <textarea name="scope_selectors[{$row.type|escape}]" class="crm-form-textarea civicfg-scope-selectors" rows="3" data-civicfg-scope-selectors {if $scopeOverridden}disabled="disabled"{/if}>{$row.selectors_text|escape}</textarea>
                  <div class="civicfg-muted">{$scopeSelectorHelp|escape}</div>
                </details>
              </div>
            </section>
          {/foreach}
        </div>

        <details class="civicfg-settings-example" open="open">
          <summary>{ts}Use the same scope from civicrm.settings.php{/ts}</summary>
          <p class="description">{ts}Use this when DEV, STAGE, or PROD should receive scope from deployment configuration instead of the database. The code-owned value wins and the scope controls become read-only in this page.{/ts}</p>
          <div class="civicfg-code-example-actions">
            <button type="button" class="button" data-civicfg-copy-scope-example><span>{ts}Copy configuration{/ts}</span></button>
            <span class="civicfg-muted" data-civicfg-copy-status></span>
          </div>
          <pre><code id="civicfg-scope-settings-example">{$scopeSettingsExample|escape}</code></pre>
        </details>
      </div>

      <details class="civicfg-settings-section civicfg-advanced-settings">
        <summary>{ts}Advanced settings{/ts}</summary>
        <div class="civicfg-advanced-settings-body">
          <div class="civicfg-settings-field">
            <div><strong>{ts}Site Identifier{/ts}</strong></div>
            <div>
              <code class="civicfg-site-id">{$siteId|escape}</code>
              <p class="description">{ts}A cloned dev/stage/prod database keeps this identifier. A separate site receives a different identifier and is blocked from import unless reviewed cross-site import is enabled.{/ts}</p>
            </div>
          </div>

          <div class="civicfg-settings-field">
            <div><strong>{ts}Cross-site Import{/ts}</strong></div>
            <div>
              <label class="civicfg-experimental"><input type="checkbox" name="allow_cross_site_import" value="1" {if $allowCrossSiteImport}checked="checked"{/if} /> {ts}Experimental: allow reviewed import when the manifest belongs to a different site{/ts}</label>
              <p class="description">{ts}Keep this disabled for normal dev/stage/prod synchronization. Enable only for an intentional one-off migration between different sites.{/ts}</p>
            </div>
          </div>

          <div class="civicfg-settings-field">
            <label for="settings_allowlist"><strong>{ts}CiviCRM Settings Allowlist{/ts}</strong></label>
            <div>
              <textarea id="settings_allowlist" name="settings_allowlist" class="crm-form-textarea">{$settingsAllowlist|escape}</textarea>
              <p class="description">{ts}Safety boundary for CiviCRM settings that Configuration Manager may inspect. Add one safe setting name per line. Sensitive settings are rejected even if added here.{/ts}</p>
            </div>
          </div>

          <div class="civicfg-settings-field">
            <label for="ignore_values"><strong>{ts}Ignore specific YAML values{/ts}</strong></label>
            <div>
              <textarea id="ignore_values" name="ignore_values" class="crm-form-textarea">{$ignoreValues|escape}</textarea>
              <p class="description">{ts}One field-level rule per line in path/to/file.yml:dot.path format. Use this only for deliberately environment-local values; do not ignore identities or required dependencies.{/ts}</p>
            </div>
          </div>

          <div class="civicfg-settings-field">
            <label for="ignore_paths"><strong>{ts}Ignore YAML files{/ts}</strong></label>
            <div>
              <textarea id="ignore_paths" name="ignore_paths" class="crm-form-textarea">{$ignorePaths|escape}</textarea>
              <p class="description">{ts}One relative YAML path or wildcard per line. Ignored files are excluded from diff, validation, export, import, single-file preview, and ZIP download.{/ts}</p>
            </div>
          </div>
        </div>
      </details>

      <div class="crm-submit-buttons"><button type="submit" class="button"><span>{ts}Save settings{/ts}</span></button></div>
    </form>
  {/if}
