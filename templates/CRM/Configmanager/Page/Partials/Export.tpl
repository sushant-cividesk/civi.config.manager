  {if $op eq 'export'}
    <details class="civicfg-panel" open="open">
      <summary>{ts}Full Archive{/ts}</summary>
      <div class="civicfg-panel-body">
        <p>{ts}Export the active CiviCRM configuration to the sync directory, or download the current sync directory as a ZIP archive.{/ts}</p>
        {if $result.dependency_types|@count gt 0}
          <div class="messages status no-popup">{ts}Related dependency types are included automatically in this export preview:{/ts} {foreach from=$result.dependency_types item=type}<code>{$type|escape}</code> {/foreach}</div>
        {/if}
        {if $result.delete_planned|@count gt 0}
          <div class="messages warning no-popup">
            <strong>{ts}Stale YAML files will be deleted on export because the matching CiviCRM records no longer exist:{/ts}</strong>
            <ul>{foreach from=$result.delete_planned item=file}<li><code>{$file|escape}</code></li>{/foreach}</ul>
          </div>
        {/if}
        <div class="civicfg-actions">
          <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" {if $exportNeedsConfirmation}data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Export YAML Changes" data-civicfg-confirm-word="EXPORT" data-civicfg-confirm-button="Export" data-civicfg-confirm-message="{$exportConfirmMessage|escape}" data-civicfg-confirm-warning="{$exportConfirmWarning|escape}"{/if}>
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
            <input type="hidden" name="_action" value="export_write" />
            {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
            <button type="submit" class="button"><span>{ts}Export{/ts}</span></button>
          </form>
          <a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=download-archive'}"><span>{ts}Download ZIP{/ts}</span></a>
        </div>
        {if $diffFiles|@count gt 0}
          <div class="civicfg-change-list">
            {foreach from=$diffFiles item=file}
              <div class="civicfg-file-card civicfg-state-{$file.status|escape}">
                <div class="civicfg-file-main">
                  <div class="civicfg-file-title"><code class="civicfg-file-code">{$file.path|escape}</code></div>
                  <div class="civicfg-file-meta">
                    <span class="civicfg-badge civicfg-badge-{$file.status|escape}">{$file.status_label|escape}</span>
                    <span class="civicfg-muted">{$file.type_label|escape}</span>
                  </div>
                  <div class="civicfg-file-summary">{$file.summary_sentence|escape}</div>
                </div>
              </div>
            {/foreach}
          </div>
        {/if}
        <div class="messages status no-popup">
          {ts}Use Export to write current CiviCRM configuration to the sync directory. Use Download ZIP to download the full current sync directory archive.{/ts}
        </div>
      </div>
    </details>

    <details class="civicfg-panel">
      <summary>{ts}Single File{/ts}</summary>
      <div class="civicfg-panel-body">
        <p>{ts}Choose one config file. The YAML preview loads immediately from the active CiviCRM database without reloading the page.{/ts}</p>
        <div class="civicfg-form-grid">
          <label for="export_item">{ts}Config File{/ts}</label>
          <select id="export_item" name="export_item" data-civicfg-single-url="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=single-export-json' h=0}">
            <option value="">{ts}- Choose -{/ts}</option>
            {foreach from=$exportItems item=item}
              <option value="{$item.key|escape}" {if $selectedExportItem eq $item.key}selected="selected"{/if}>{$item.path|escape}</option>
            {/foreach}
          </select>
        </div>

        <div id="civicfg-single-export-empty" class="messages status no-popup"{if $singleExport.has_value or $singleExport.error} hidden="hidden"{/if}>{ts}Choose a config file to preview its YAML.{/ts}</div>
        <div id="civicfg-single-export-error" class="messages error no-popup"{if !$singleExport.error} hidden="hidden"{/if}>{if $singleExport.error}{$singleExport.error|escape}{/if}</div>
        <div id="civicfg-single-export-preview"{if !$singleExport.has_value or $singleExport.error} hidden="hidden"{/if}>
          <div class="civicfg-single-export-meta">
            <strong><code id="civicfg-single-export-path" class="civicfg-file-code">{if $singleExport.path}{$singleExport.path|escape}{/if}</code></strong>
            <span id="civicfg-single-export-label" class="civicfg-muted">{if $singleExport.label}{$singleExport.label|escape}{/if}</span>
          </div>
          <textarea id="civicfg-single-export-yaml" readonly="readonly" class="civicfg-yaml-preview">{if $singleExport.yaml}{$singleExport.yaml|escape}{/if}</textarea>
          <div class="civicfg-actions">
            <a id="civicfg-single-export-download" class="button" href="{if $singleExport.download_url}{$singleExport.download_url|escape}{/if}"><span>{ts}Download{/ts}</span></a>
          </div>
        </div>
      </div>
    </details>
  {/if}
