  {if $op eq 'import'}
    <h3>{ts}Import From Sync Directory{/ts}</h3>
    <p>{ts}Review YAML changes that can be applied to CiviCRM. Import follows YAML as the source of truth for supported handlers and may create, update, or delete records after confirmation.{/ts}</p>

    {if $importPlan|@count eq 0}
      <div class="messages status no-popup">{ts}Nothing to import from the sync directory. If changes are listed as In CiviCRM on the Synchronize tab, use Export to write them to YAML first.{/ts}</div>
    {else}
      <details class="civicfg-panel" open="open">
        <summary>{ts}Import Preview{/ts}{if $diffTotal gt 0} <span class="civicfg-muted">({$diffTotal|escape} {ts}changed items{/ts})</span>{/if}</summary>
        <div class="civicfg-panel-body">
          <div class="civicfg-actions">
            {if $canImport and $importApplyTypes|@count gt 0 and $importErrorCount eq 0 and $importResult.ok}
              <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Import YAML to CiviCRM" data-civicfg-confirm-word="IMPORT" data-civicfg-confirm-button="Import" data-civicfg-confirm-message="Import will apply YAML as the source of truth. Supported records may be created, updated, or deleted. Continue only after reviewing the changed files and dependency warnings." data-civicfg-confirm-warning="Import uses YAML as the source of truth. Supported CiviCRM records may be created, updated, deleted, or recreated with new database IDs.">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
                <input type="hidden" name="_action" value="import_apply" />
                {foreach from=$importApplyTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
                <button type="submit" class="button"><span>{ts}Import{/ts}</span></button>
              </form>
            {/if}
            <a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}"><span>{ts}Back{/ts}</span></a>
          </div>

          {if $diffPageCount gt 1}
            <div class="civicfg-pagination-summary">{ts}Showing page{/ts} {$diffPage|escape} {ts}of{/ts} {$diffPageCount|escape}. {ts}The Import action still applies the full selected managed type after complete server-side preflight; this list is paginated only for review.{/ts}</div>
          {/if}
          <div class="civicfg-change-list">
            {foreach from=$importPlan item=item}
              <div class="civicfg-file-card civicfg-state-{$item.status|escape}">
                <div class="civicfg-file-main">
                  <div class="civicfg-file-title"><code class="civicfg-file-code">{$item.path|escape}</code></div>
                  <div class="civicfg-file-meta">
                    <span class="civicfg-badge {if !$item.importable}warn{elseif $item.status eq 'new_in_db'}bad{else}good{/if}">{if $item.importable}{$item.action|escape}{else}{ts}Not Ready{/ts}{/if}</span>
                    <span>{$item.change_count|escape} {if $item.status eq 'changed'}{ts}changed field(s){/ts}{elseif $item.status eq 'new_in_db'}{ts}field(s) to remove{/ts}{else}{ts}field(s) to create{/ts}{/if}</span>
                    <span class="civicfg-muted">{$item.type_label|escape}</span>
                  </div>
                  <div class="civicfg-file-summary">{if $item.summary_sentence}{$item.summary_sentence|escape}{elseif $item.note}{$item.note|escape}{/if}</div>
                  {if $item.detail_sentences|@count gt 1}
                    <ul class="civicfg-change-sentences">
                      {foreach from=$item.detail_sentences item=sentence name=importsentences}
                        {if $smarty.foreach.importsentences.index lt 3}<li>{$sentence|escape}</li>{/if}
                      {/foreach}
                    </ul>
                  {/if}
                </div>
                {if $item.rows}
                  <div class="civicfg-import-diff-list">
                    {foreach from=$item.rows item=row name=importrowloop}
                      {if $smarty.foreach.importrowloop.index lt 6}
                        <div class="civicfg-import-diff-row">
                          <div class="civicfg-import-diff-field"><strong>{$row.label|escape}</strong><br /><code>{$row.path|escape}</code><div class="civicfg-row-sentence">{$row.sentence|escape}</div></div>
                          <div class="civicfg-import-diff-cell civicfg-diff-new"><span class="civicfg-muted">{ts}Current CiviCRM{/ts}</span><div class="civicfg-diff-value">{$row.new_html nofilter}</div></div>
                          <div class="civicfg-import-diff-cell civicfg-diff-old"><span class="civicfg-muted">{ts}YAML To Import{/ts}</span><div class="civicfg-diff-value">{$row.old_html nofilter}</div></div>
                        </div>
                      {/if}
                    {/foreach}
                  </div>
                {/if}
              </div>
            {/foreach}
          </div>

          {if $diffPageCount gt 1}
            <div class="civicfg-actions civicfg-pagination-actions">
              {if $diffPrevUrl}<a class="button" href="{$diffPrevUrl|escape}"><span>{ts}Previous 100{/ts}</span></a>{/if}
              <span class="civicfg-muted">{ts}Page{/ts} {$diffPage|escape} / {$diffPageCount|escape}</span>
              {if $diffNextUrl}<a class="button" href="{$diffNextUrl|escape}"><span>{ts}Next 100{/ts}</span></a>{/if}
            </div>
          {/if}
          <div class="civicfg-actions">
            {if $canImport and $importApplyTypes|@count gt 0 and $importErrorCount eq 0 and $importResult.ok}
              <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Import YAML to CiviCRM" data-civicfg-confirm-word="IMPORT" data-civicfg-confirm-button="Import" data-civicfg-confirm-message="Import will apply YAML as the source of truth. Supported records may be created, updated, or deleted. Continue only after reviewing the changed files and dependency warnings." data-civicfg-confirm-warning="Import uses YAML as the source of truth. Supported CiviCRM records may be created, updated, deleted, or recreated with new database IDs.">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
                <input type="hidden" name="_action" value="import_apply" />
                {foreach from=$importApplyTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
                <button type="submit" class="button"><span>{ts}Import{/ts}</span></button>
              </form>
            {/if}
            <a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}"><span>{ts}Back{/ts}</span></a>
          </div>
        </div>
      </details>
    {/if}

    {if $canImport}
    <details class="civicfg-panel">
      <summary>{ts}Upload Single YAML{/ts}</summary>
      <div class="civicfg-panel-body">
        <p class="description">{ts}Upload one YAML file into the sync directory. After upload, review Synchronize before importing to CiviCRM.{/ts}</p>
        <form method="post" enctype="multipart/form-data" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=import'}">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
          <input type="hidden" name="_action" value="import_single_yaml" />
          <div class="civicfg-form-grid">
            <label for="single_type">{ts}Type{/ts}</label>
            <select id="single_type" name="single_type">
              {foreach from=$allTypes item=row}<option value="{$row.type|escape}">{$row.label|escape}</option>{/foreach}
            </select>
            <label for="single_filename">{ts}Target Filename{/ts}</label>
            <input type="text" id="single_filename" name="single_filename" placeholder="activity_type.yml" />
            <span></span><p class="description">{ts}Use a filename relative to the selected type directory. Example: activity_type.yml or groups/example.yml.{/ts}</p>
            <label for="single_yaml">{ts}YAML File{/ts}</label>
            <input type="file" id="single_yaml" name="single_yaml" accept=".yml,.yaml,text/yaml,text/plain" />
          </div>
          <div class="civicfg-actions"><button type="submit" class="button"><span>{ts}Upload{/ts}</span></button></div>
        </form>
      </div>
    </details>

    <details class="civicfg-panel">
      <summary>{ts}Upload ZIP Archive{/ts}</summary>
      <div class="civicfg-panel-body">
        <p class="description">{ts}Upload a full config archive. YAML files are staged into the sync directory; no CiviCRM records are changed until you review and import.{/ts}</p>
        <form method="post" enctype="multipart/form-data" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=import'}">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
          <input type="hidden" name="_action" value="import_zip_archive" />
          <div class="civicfg-form-grid">
            <label for="zip_archive">{ts}ZIP Archive{/ts}</label>
            <input type="file" id="zip_archive" name="zip_archive" accept=".zip,application/zip" />
          </div>
          <div class="civicfg-actions"><button type="submit" class="button"><span>{ts}Upload{/ts}</span></button></div>
        </form>
      </div>
    </details>
    {/if}
  {/if}
