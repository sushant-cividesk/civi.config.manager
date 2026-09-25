  {if $op eq 'import'}
    <h3>{ts}Import From Sync Directory{/ts}</h3>
    <p>{ts}Review Saved Config changes that can be applied to CiviCRM. Import follows Saved Config as the source of truth for supported handlers and may create, update, or delete records after confirmation.{/ts}</p>

    {if $importPlan|@count eq 0}
      <div class="messages status no-popup">{ts}Nothing to import from the sync directory. If changes are listed as In CiviCRM on the Synchronize tab, use Export to write them to Saved Config first.{/ts}</div>
    {else}
      <details class="civicfg-panel" open="open">
        <summary>{ts}Import Preview{/ts}{if $diffTotal gt 0} <span class="civicfg-muted">({$diffTotal|escape} {ts}changed items{/ts})</span>{/if}</summary>
        <div class="civicfg-panel-body">
          <div class="civicfg-actions">
            {if $canImport and $importApplyTypes|@count gt 0 and $importErrorCount eq 0 and $importResult.ok and $importPlanId}
              <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Import Saved Config to CiviCRM" data-civicfg-confirm-word="IMPORT" data-civicfg-confirm-button="Import" data-civicfg-confirm-message="Import will apply Saved Config as the source of truth. Supported records may be created, updated, or deleted. Continue only after reviewing the changed files and dependency warnings." data-civicfg-confirm-warning="Import uses Saved Config as the source of truth. Supported CiviCRM records may be created, updated, deleted, or recreated with new database IDs.">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
                <input type="hidden" name="_action" value="import_apply" />
                <input type="hidden" name="import_plan_id" value="{$importPlanId|escape}" />
                {foreach from=$importApplyTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
                <button type="submit" class="button"><span>{ts}Import{/ts}</span></button>
              </form>
            {/if}
            <a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}"><span>{ts}Back{/ts}</span></a>
          </div>

          {if $importPlanId}
            <div class="messages status no-popup civicfg-import-plan-status"><strong>{ts}Reviewed preview protected.{/ts}</strong> {ts}Import will use this exact reviewed plan. If Saved Config, Current CiviCRM, scope, or provider capability changes before apply, Configuration Manager will stop and require a fresh preview.{/ts}</div>
          {/if}

          {if $importExcludedComponents|@count gt 0}
            <div class="messages warning no-popup civicfg-import-excluded-status">
              <strong>{ts}Reduced Import preview.{/ts}</strong>
              {ts}The following dependency component(s) were explicitly excluded from this preview and are not considered applied. Synchronize remains authoritative for their current state:{/ts}
              <ul>
                {foreach from=$importExcludedComponents item=excludedComponent}
                  <li><strong>{$excludedComponent.title|escape}</strong>{if $excludedComponent.types|@count gt 0} — {ts}excluded types:{/ts} {foreach from=$excludedComponent.types item=excludedType name=excludedhistory}{$excludedType|escape}{if !$smarty.foreach.excludedhistory.last}, {/if}{/foreach}{/if}{if $excludedComponent.files|@count gt 0} — {$excludedComponent.files|@count} {ts}affected Saved Config file(s){/ts}{/if}</li>
                {/foreach}
              </ul>
            </div>
          {/if}

          {if $importDependencyComponents|@count gt 0}
            <div class="messages error no-popup civicfg-import-blockers">
              <strong>{ts}Import is blocked by dependency component(s).{/ts}</strong>
              {ts}Fix the missing dependency and preview again, or exclude a complete component only when Configuration Manager proves the remaining import is dependency-closed.{/ts}
            </div>
            {foreach from=$importDependencyComponents item=component}
              <details class="civicfg-panel civicfg-dependency-component" open="open">
                <summary><strong>{$component.title|escape}</strong> — {$component.blocker_count|escape} {ts}blocker(s){/ts}</summary>
                <div class="civicfg-panel-body">
                  <p><strong>{ts}Affected configuration types:{/ts}</strong> {$component.title|escape}</p>
                  <p><strong>{ts}Full exclusion scope:{/ts}</strong>
                    {foreach from=$component.excluded_types item=excludedType name=excludedtypes}{$excludedType|escape}{if !$smarty.foreach.excludedtypes.last}, {/if}{/foreach}
                  </p>
                  <p><strong>{ts}Remaining Import scope after exclusion:{/ts}</strong>
                    {foreach from=$component.remaining_requested_types item=remainingType name=remainingtypes}{$remainingType|escape}{if !$smarty.foreach.remainingtypes.last}, {/if}{/foreach}
                  </p>
                  <p><strong>{ts}Planned actions:{/ts}</strong> {$component.action_text|escape}</p>
                  {if $component.files|@count gt 0}
                    <p><strong>{ts}Affected Saved Configs:{/ts}</strong> {$component.files|@count}</p>
                    <ul>
                      {foreach from=$component.files item=componentFile}
                        <li><code>{$componentFile|escape}</code></li>
                      {/foreach}
                    </ul>
                  {/if}
                  <ul>
                    {foreach from=$component.blockers item=blocker}
                      <li>{$blocker.message|escape}</li>
                    {/foreach}
                  </ul>
                  <div class="civicfg-actions">
                    <a class="button" href="{crmURL p='civicrm/admin/config-manager' q=$diffPageBaseQuery}"><span>{ts}Fix and preview again{/ts}</span></a>
                    {if $canImport and $component.can_exclude}
                      <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=import'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Exclude Dependency Component" data-civicfg-confirm-word="EXCLUDE" data-civicfg-confirm-button="Build New Preview" data-civicfg-confirm-message="This excludes the complete dependency component from this Import only. Configuration Manager will discard any prior reviewed plan and build a completely new preview from current Saved Config and Current CiviCRM state." data-civicfg-confirm-warning="Excluded differences remain visible on Synchronize and are not considered applied or in sync.">
                        <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
                        <input type="hidden" name="_action" value="import_exclude_component" />
                        <input type="hidden" name="dependency_component_id" value="{$component.id|escape}" />
                        {foreach from=$importApplyTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
                        <button type="submit" class="button"><span>{ts}Exclude component and build new preview{/ts}</span></button>
                      </form>
                    {else}
                      <span class="civicfg-muted">{$component.exclusion_reason|escape}</span>
                    {/if}
                  </div>
                </div>
              </details>
            {/foreach}
          {/if}

          {if $diffPageCount gt 1}
            <div class="civicfg-pagination-summary">{ts}Showing page{/ts} {$diffPage|escape} {ts}of{/ts} {$diffPageCount|escape}. {ts}The Import action still applies the full selected managed type after complete server-side preflight; this list is paginated only for review.{/ts}</div>
          {/if}
          <div class="civicfg-change-list">
            {foreach from=$importPlan item=item}
              <div class="civicfg-file-card civicfg-state-{$item.status|escape}">
                <div class="civicfg-file-main">
                  <div class="civicfg-file-title">{if $item.show_inline_path}<code class="civicfg-file-code">{$item.path|escape}</code>{else}<strong>{$item.display_title|escape}</strong>{/if}</div>
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
                          <div class="civicfg-import-diff-cell civicfg-diff-old"><span class="civicfg-muted">{ts}Saved Config To Import{/ts}</span><div class="civicfg-diff-value">{$row.old_html nofilter}</div></div>
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
            {if $canImport and $importApplyTypes|@count gt 0 and $importErrorCount eq 0 and $importResult.ok and $importPlanId}
              <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Import Saved Config to CiviCRM" data-civicfg-confirm-word="IMPORT" data-civicfg-confirm-button="Import" data-civicfg-confirm-message="Import will apply Saved Config as the source of truth. Supported records may be created, updated, or deleted. Continue only after reviewing the changed files and dependency warnings." data-civicfg-confirm-warning="Import uses Saved Config as the source of truth. Supported CiviCRM records may be created, updated, deleted, or recreated with new database IDs.">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
                <input type="hidden" name="_action" value="import_apply" />
                <input type="hidden" name="import_plan_id" value="{$importPlanId|escape}" />
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
      <summary>{ts}Upload Single Saved Config{/ts}</summary>
      <div class="civicfg-panel-body">
        <p class="description">{ts}Upload one Saved Config into the sync directory. After upload, review Synchronize before importing to CiviCRM.{/ts}</p>
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
            <label for="single_yaml">{ts}Saved Config{/ts}</label>
            <input type="file" id="single_yaml" name="single_yaml" accept=".yml,.yaml,text/yaml,text/plain" />
          </div>
          <div class="civicfg-actions"><button type="submit" class="button"><span>{ts}Upload{/ts}</span></button></div>
        </form>
      </div>
    </details>

    <details class="civicfg-panel">
      <summary>{ts}Upload ZIP Archive{/ts}</summary>
      <div class="civicfg-panel-body">
        <p class="description">{ts}Upload a full config archive. Saved Configs are staged into the sync directory; no CiviCRM records are changed until you review and import.{/ts}</p>
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
