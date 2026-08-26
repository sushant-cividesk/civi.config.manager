  {if $op eq 'settings'}
    <h3>{ts}Settings{/ts}</h3>
    <form class="civicfg-settings-form" data-civicfg-settings-form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=settings'}" data-civicfg-scope-options-url="{$scopeOptionsUrl|escape}">
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

      <details class="civicfg-settings-section civicfg-scope-settings" open="open">
        <summary><strong>{ts}What should Configuration Manager manage?{/ts}</strong></summary>
        <div class="civicfg-settings-section-body">
        {if $scopeNeedsSetup}
          <div class="messages status no-popup civicfg-onboarding" data-civicfg-onboarding>
            <strong>{ts}Welcome to Configuration Manager{/ts}</strong>
            <p>{ts}Nothing is managed automatically on a new installation. Choose the configuration types you want to manage or monitor, save the settings, then create the first YAML export/baseline from Synchronize.{/ts}</p>
            <ol>
              <li>{ts}Select configuration types below.{/ts}</li>
              <li>{ts}Use the bulk action or individual dropdowns to choose a mode.{/ts}</li>
              <li>{ts}Save settings.{/ts}</li>
              <li>{ts}Open Synchronize and run the first export.{/ts}</li>
            </ol>
          </div>
        {/if}
        <p class="description">{ts}Every registered configuration type is listed below. Third-party providers that do not expose a safe portable identity remain backup/monitor-only instead of being written automatically.{/ts}</p>
        <p class="civicfg-scope-question"><strong>{ts}How should each configuration type be handled?{/ts}</strong> {ts}Choose one mode on each card. Additional controls appear only when they are needed.{/ts}</p>
        <div class="civicfg-mode-help civicfg-mode-help-all" data-civicfg-mode-help="all">
          {ts}All supported items are part of managed YAML and the normal export, diff, validation, and safe restore/import workflow.{/ts}
        </div>
        <div class="civicfg-scope-bulk" data-civicfg-scope-bulk>
          <label class="civicfg-scope-select-all"><input type="checkbox" data-civicfg-scope-select-all {if $scopeOverridden}disabled="disabled"{/if} /> <span>{ts}Select all{/ts}</span></label>
          <span class="civicfg-muted" data-civicfg-scope-selected-count>0 {ts}selected{/ts}</span>
          <label for="civicfg-scope-bulk-mode"><strong>{ts}Bulk action{/ts}</strong></label>
          <select id="civicfg-scope-bulk-mode" class="crm-form-select" data-civicfg-scope-bulk-mode {if $scopeOverridden}disabled="disabled"{/if}>
            <option value="">{ts}- Choose mode -{/ts}</option>
            <option value="all">{ts}Manage everything{/ts}</option>
            <option value="selected">{ts}Manage selected items{/ts}</option>
            <option value="watch">{ts}Monitor only{/ts}</option>
            <option value="ignore">{ts}Ignore{/ts}</option>
          </select>
          <button type="button" class="button" data-civicfg-scope-bulk-apply disabled="disabled" {if $scopeOverridden}disabled="disabled"{/if}><span>{ts}Apply{/ts}</span></button>
          <button type="submit" class="button civicfg-scope-save" data-civicfg-scope-save {if $scopeOverridden}disabled="disabled"{/if}><span>{ts}Save scope changes{/ts}</span></button>
          <span class="civicfg-muted">{ts}Bulk Apply changes the form only. Save scope changes before exporting, importing, or leaving Settings.{/ts}</span>
        </div>
        <div class="messages warning no-popup civicfg-scope-unsaved" data-civicfg-scope-unsaved hidden="hidden">
          <strong>{ts}Unsaved scope changes{/ts}</strong> - {ts}Export, Import, Validate, and Synchronize still use the last saved scope until you save this form.{/ts}
        </div>

        <div class="civicfg-scope-dependency-guide">
          <strong>{ts}Scope dependency guidance{/ts}</strong>
          <p class="description">{ts}Some configuration types reference other types. Configuration Manager will not silently widen your saved scope, but it will warn when a managed type depends on configuration that is ignored, monitor-only, unavailable, or only partly selected. Import validation still checks the actual YAML dependency before making changes.{/ts}</p>
          <div class="messages warning no-popup civicfg-scope-dependency-summary" data-civicfg-scope-dependency-summary {if $scopeDependencyWarnings|@count eq 0}hidden="hidden"{/if}>
            <strong data-civicfg-scope-dependency-heading>{if $scopeDependencyWarnings|@count gt 0}{ts 1=$scopeDependencyWarnings|@count}%1 scope dependency item(s) need review.{/ts}{/if}</strong>
            <ul data-civicfg-scope-dependency-list>
              {foreach from=$scopeDependencyWarnings item=dependencyWarning}
                <li>{$dependencyWarning.message|escape}</li>
              {/foreach}
            </ul>
          </div>
        </div>

        {if $scopeOverridden}
          <div class="messages status no-popup civicfg-inline-message">
            <strong>{ts}Configuration scope is controlled by civicrm.settings.php.{/ts}</strong>
            {ts}The effective values are shown below. Change the deployment configuration file to update them.{/ts}
          </div>
        {/if}

        <div class="civicfg-scope-grid">
          {foreach from=$scopeRows item=row}
            <section class="civicfg-scope-card" data-civicfg-scope-row="{$row.type|escape}" data-scope-label="{$row.label|escape}" data-scope-capability="{$row.capability|escape}" data-civicfg-scope-dependencies="{$row.scope_dependency_types|escape}">
              <label class="civicfg-scope-card-select"><input type="checkbox" data-civicfg-scope-select aria-label="{ts 1=$row.label}Select %1 for bulk action{/ts}" {if $scopeOverridden || $row.capability eq 'unavailable'}disabled="disabled"{/if} /></label>
              <div class="civicfg-scope-card-header">
                <div>
                  <strong class="civicfg-scope-title">{$row.label|escape}</strong>
                  <div class="civicfg-muted"><code>{$row.type|escape}</code></div>
                </div>
                <span class="civicfg-capability civicfg-capability-{$row.capability|escape}" title="{$row.capability_help|escape}">{$row.capability_label|escape}</span>
              </div>

              <select id="scope-mode-{$row.type|escape}" name="scope_mode[{$row.type|escape}]" class="crm-form-select civicfg-scope-mode" data-civicfg-scope-mode aria-label="{ts 1=$row.label}How should %1 be handled?{/ts}" {if $scopeOverridden}disabled="disabled"{/if}>
                <option value="all" {if $row.mode_all}selected="selected"{/if} {if $row.capability eq 'unavailable'}disabled="disabled"{/if}>{ts}Manage everything{/ts}</option>
                <option value="selected" {if $row.mode_selected}selected="selected"{/if} {if $row.capability eq 'unavailable'}disabled="disabled"{/if}>{ts}Manage selected items{/ts}</option>
                <option value="watch" {if $row.mode_watch}selected="selected"{/if} {if $row.capability eq 'unavailable'}disabled="disabled"{/if}>{ts}Monitor only{/ts}</option>
                <option value="ignore" {if $row.mode_ignore}selected="selected"{/if}>{ts}Ignore{/ts}</option>
              </select>

              {if $row.capability eq 'unavailable'}
                <div class="messages error no-popup civicfg-provider-unavailable">
                  <strong>{ts}Provider unavailable{/ts}</strong> - {$row.capability_help|escape}
                  {if !$row.mode_ignore}<div><strong>{ts}Current saved scope is retained but cannot run safely. Choose Ignore and save, or restore a supported provider version.{/ts}</strong></div>{/if}
                </div>
              {/if}

              <div class="civicfg-mode-help civicfg-mode-help-selected" data-civicfg-mode-help="selected">
                {ts}Only the items you choose are managed. Other items can optionally be monitored without entering YAML.{/ts}
              </div>
              <div class="civicfg-mode-help civicfg-mode-help-watch" data-civicfg-mode-help="watch">
                {ts}Changes are monitored locally. Nothing from this type is exported, restored, imported, or deleted by Configuration Manager.{/ts}
              </div>
              <div class="civicfg-mode-help civicfg-mode-help-ignore" data-civicfg-mode-help="ignore">
                {ts}Configuration Manager does not manage or monitor this type.{/ts}
              </div>

              {if $row.scope_dependencies|@count gt 0}
                <div class="civicfg-scope-relations">
                  <div class="civicfg-scope-relations-label"><strong>{ts}Related deployment scope{/ts}</strong></div>
                  <div class="civicfg-scope-relation-links">
                    {foreach from=$row.scope_dependencies item=dependency name=scopeDependencyLoop}
                      <code title="{$dependency.reason|escape}">{$dependency.label|escape}</code>{if !$smarty.foreach.scopeDependencyLoop.last}<span aria-hidden="true">, </span>{/if}
                    {/foreach}
                  </div>
                  {if $row.scope_dependents|@count gt 0}
                    <div class="civicfg-scope-used-by"><span class="civicfg-muted">{ts}Used by:{/ts}</span>
                      {foreach from=$row.scope_dependents item=dependent name=scopeDependentLoop}
                        <span>{$dependent.label|escape}</span>{if !$smarty.foreach.scopeDependentLoop.last}<span aria-hidden="true">, </span>{/if}
                      {/foreach}
                    </div>
                  {/if}
                  <div class="civicfg-scope-card-dependency-warning" data-civicfg-scope-card-dependency-warning hidden="hidden"></div>
                  <button type="button" class="button civicfg-manage-dependencies" data-civicfg-manage-dependencies hidden="hidden" {if $scopeOverridden}disabled="disabled"{/if}><span>{ts}Manage recommended dependencies{/ts}</span></button>
                </div>
              {/if}

              <div class="civicfg-selected-controls" data-civicfg-selected-controls {if !$row.mode_selected}hidden="hidden"{/if}>
                <div class="civicfg-selected-summary">
                  <strong>{ts}Managed items{/ts}</strong>
                  <span class="civicfg-muted" data-civicfg-scope-count>{if $row.selector_count gt 0}{$row.selector_count|escape} {ts}selected{/ts}{else}{ts}None selected yet{/ts}{/if}</span>
                </div>
                <button type="button" class="button civicfg-scope-picker-button" data-civicfg-scope-picker="{$row.type|escape}" {if $scopeOverridden || $row.capability eq 'unavailable'}disabled="disabled"{/if}><span>{ts}Choose items{/ts}</span></button>
                <div class="civicfg-selected-chips" data-civicfg-selected-chips></div>

                <label class="civicfg-watch-rest">
                  <input type="checkbox" name="scope_watch_unmanaged[{$row.type|escape}]" value="1" {if $row.watch_unmanaged}checked="checked"{/if} {if $scopeOverridden || $row.capability eq 'unavailable'}disabled="disabled"{/if} />
                  <span>{ts}Monitor everything else in this type{/ts}</span>
                </label>

                <details class="civicfg-advanced-selection">
                  <summary>{ts}Advanced selection{/ts}</summary>
                  <p class="description">{ts}Normally use Choose items above. Advanced selectors are useful for deployment automation or an item that is temporarily missing from CiviCRM.{/ts}</p>
                  <textarea name="scope_selectors[{$row.type|escape}]" class="crm-form-textarea civicfg-scope-selectors" rows="3" data-civicfg-scope-selectors {if $scopeOverridden || $row.capability eq 'unavailable'}disabled="disabled"{/if}>{$row.selectors_text|escape}</textarea>
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
      </details>

      <details class="civicfg-settings-section civicfg-advanced-settings" open="open">
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
            <label for="ignore_paths"><strong>{ts}Config Ignore{/ts}</strong></label>
            <div>
              <textarea id="ignore_paths" name="ignore_paths" class="crm-form-textarea">{$ignorePaths|escape}</textarea>
              <p class="description">{ts}One rule per line. Use path/to/file.yml (or a wildcard) to ignore an entire YAML file. Use path/to/file.yml:dot.path to ignore only one value while keeping the rest of that file managed. The self-extension rule and proven runtime timestamp rules shown here are built in and remain active on every installation.{/ts}</p>
            </div>
          </div>
        </div>
      </details>

      <div class="crm-submit-buttons"><button type="submit" class="button"><span>{ts}Save settings{/ts}</span></button></div>
    </form>
  {/if}
