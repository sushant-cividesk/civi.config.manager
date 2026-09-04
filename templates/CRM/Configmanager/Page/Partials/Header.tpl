  <div class="civicfg-tabs">
    {foreach from=$tabs item=tab}
      <a class="civicfg-tab{if $tab.active} active{/if}" href="{$tab.url|escape}">{$tab.label|escape}</a>
    {/foreach}
  </div>

  {if $op eq 'sync'}
    {if !$managedScopeConfigured}
      <p class="civicfg-help">{if $watchOnlyScope}{ts}Watch-only monitoring is configured. Scan watched configuration for changes, or choose managed configuration in Settings to begin synchronization.{/ts}{else}{ts}Choose what Configuration Manager should manage in Settings, then create the first Saved Config export before synchronization can be checked.{/ts}{/if}</p>
    {else}
      <p class="civicfg-help">{ts}Review pending differences between Current CiviCRM and Saved Configs. Export saves Current CiviCRM changes. Import applies Saved Config changes to Current CiviCRM.{/ts}</p>
    {/if}
  {elseif $op eq 'import'}
    <p class="civicfg-help">{ts}Review Saved Configs before applying them to Current CiviCRM. Import treats Saved Configs as the source of truth and may update, create, or delete supported records after confirmation.{/ts}</p>
  {elseif $op eq 'export'}
    <p class="civicfg-help">{ts}Export Current CiviCRM configuration as a ZIP archive or preview one Saved Config before saving it.{/ts}</p>
  {elseif $op eq 'settings'}
    <p class="civicfg-help">{ts}Choose what Configuration Manager manages, watches, or ignores, and configure the sync directory.{/ts}</p>
  {/if}

  {if $notice}<div class="messages status no-popup">{$notice|escape}</div>{/if}
  {if $result.error}<div class="messages error no-popup">{$result.error|escape}</div>{/if}
  {if $summary.error_count gt 0}<div class="messages error no-popup" data-civicfg-transient-summary-error>{ts 1=$summary.error_count}%1 Error(s) Reported. Review the page messages and logs for details.{/ts}</div>{/if}

  {if $op eq 'sync' && $syncErrors|@count gt 0}
    <div class="messages error no-popup civicfg-sync-error-summary">
      <strong>{ts 1=$syncErrors|@count}Synchronization has %1 active error(s).{/ts}</strong> {ts}Review Synchronization Errors below for details.{/ts}
    </div>
  {/if}

  {if $importErrorMessages|@count gt 0}
    <details class="messages error no-popup civicfg-import-message-summary" open="open">
      <summary><strong>{ts 1=$importErrorMessages|@count}Import has %1 blocking error(s).{/ts}</strong></summary>
      <ul class="civicfg-import-message-list">
        {foreach from=$importErrorMessages item=message}
          <li><strong>{$message.title|escape}:</strong> {$message.message|escape}</li>
        {/foreach}
      </ul>
    </details>
  {/if}

  {if $importWarningMessages|@count gt 0}
    <details class="messages warning no-popup civicfg-import-message-summary" open="open">
      <summary><strong>{ts 1=$importWarningMessages|@count}Import has %1 warning(s).{/ts}</strong></summary>
      <ul class="civicfg-import-message-list">
        {foreach from=$importWarningMessages item=message}
          <li><strong>{$message.title|escape}:</strong> {$message.message|escape}</li>
        {/foreach}
      </ul>
    </details>
  {/if}


  <div class="civicfg-cards">
    <div class="civicfg-card">
      <div class="civicfg-card-label">{ts}Status{/ts}</div>
      <div class="civicfg-card-value">
        {if !$managedScopeConfigured}
          <span class="civicfg-badge warn">{if $watchOnlyScope}{ts}Monitoring Only{/ts}{else}{ts}Setup Required{/ts}{/if}</span>
        {elseif $initialExportRequired}
          <span class="civicfg-badge warn">{ts}Initial Export Required{/ts}</span>
        {elseif $summary.error_count gt 0}
          <span class="civicfg-badge bad">{ts}Error{/ts}</span>
        {elseif $summary.total_changes gt 0}
          <span class="civicfg-badge warn">{ts 1=$summary.total_changes}%1 Difference(s){/ts}</span>
        {else}
          <span class="civicfg-badge good">{ts}In Sync{/ts}</span>
        {/if}
      </div>
    </div>
    <div class="civicfg-card">
      <div class="civicfg-card-label">{ts}Changes{/ts}</div>
      {if $op eq 'settings'}
        <div>{ts}Scope changes are edited here. Open Synchronize to run the managed comparison after saving.{/ts}</div>
      {elseif !$managedScopeConfigured}
        <div>{if $watchOnlyScope}{ts}No configuration is currently saved and managed. Watch-only items can still be scanned.{/ts}{else}{ts}Choose configuration to manage in Settings before starting synchronization.{/ts}{/if}</div>
      {elseif $initialExportRequired}
        <div>{ts}Differences will be shown after the initial Saved Config export.{/ts}</div>
      {else}
        {if $summary.error_count gt 0}<div><strong>{ts}Comparison incomplete - review the error details below.{/ts}</strong></div>{/if}
        <div>{ts}Saved Configs{/ts}: {$summary.saved_config_count|escape}</div>
        <div>{ts}Changed{/ts}: {$summary.changed_count|escape}</div>
        <div>{ts}Not Yet Saved{/ts}: {$summary.new_count|escape}</div>
        <div>{ts}Not in Current CiviCRM{/ts}: {$summary.missing_count|escape}</div>
      {/if}
    </div>
    <div class="civicfg-card">
      <div class="civicfg-card-label">{ts}Sync Directory{/ts}</div>
      <div class="civicfg-path">{$summary.sync_dir|escape}</div>
    </div>
    <div class="civicfg-card">
      <div class="civicfg-card-label">{ts}Directory{/ts}</div>
      {if $summary.exists === null}
        <span class="civicfg-badge">{ts}Not Checked{/ts}</span>
      {elseif $summary.exists && $summary.writable}
        <span class="civicfg-badge good">{ts}Ready{/ts}</span>
      {elseif $summary.exists}
        <span class="civicfg-badge warn">{ts}Not Writable{/ts}</span>
      {else}
        <span class="civicfg-badge warn">{ts}Missing{/ts}</span>
      {/if}
    </div>
  </div>

  {if $op eq 'sync' && $lastExportResult|@count gt 0}
    <details class="civicfg-panel civicfg-last-result" open="open">
      <summary><strong>{ts}Last Export{/ts}</strong> <span class="civicfg-muted">{$lastExportResult.completed_at|escape}</span></summary>
      <div class="civicfg-panel-body">
        <div class="civicfg-result-summary">
          <span><strong>{$lastExportResult.saved_config_count|escape}</strong> {ts}Saved Configs{/ts}</span>
          <span><strong>{$lastExportResult.created|escape}</strong> {ts}Created{/ts}</span>
          <span><strong>{$lastExportResult.updated|escape}</strong> {ts}Updated{/ts}</span>
          <span><strong>{$lastExportResult.unchanged|escape}</strong> {ts}Unchanged{/ts}</span>
          <span><strong>{$lastExportResult.removed|escape}</strong> {ts}Removed{/ts}</span>
          {if $lastExportResult.monitor_only gt 0}<span><strong>{$lastExportResult.monitor_only|escape}</strong> {ts}Monitor only{/ts}</span>{/if}
          {if $lastExportResult.warnings gt 0}<span><strong>{$lastExportResult.warnings|escape}</strong> {ts}Warnings{/ts}</span>{/if}
          {if $lastExportResult.errors gt 0}<span><strong>{$lastExportResult.errors|escape}</strong> {ts}Errors{/ts}</span>{/if}
        </div>
        {if $lastExportResult.ok}
          <p class="description">{ts}Export finished safely. This summary stays on the page so you can review what happened after the notification disappears.{/ts}</p>
        {else}
          <p class="description"><strong>{ts}Export stopped safely.{/ts}</strong> {ts}Existing Saved Configs were left unchanged or rolled back. Review the problem before trying again.{/ts}</p>
          {if $lastExportResult.problem}<div class="messages error no-popup"><strong>{ts}Problem:{/ts}</strong> {$lastExportResult.problem|escape}</div>{/if}
          {if $lastExportResult.next_action}<p><strong>{ts}Next:{/ts}</strong> {$lastExportResult.next_action|escape}</p>{/if}
        {/if}
      </div>
    </details>
  {/if}

  {if $op eq 'sync' && $lastImportSummary|@count gt 0}
    <details class="civicfg-panel civicfg-last-result" open="open">
      <summary><strong>{ts}Last Import{/ts}</strong> <span class="civicfg-muted">{$lastImportSummary.completed_at|escape}</span></summary>
      <div class="civicfg-panel-body">
        <div class="civicfg-result-summary">
          <span><strong>{$lastImportSummary.created|escape}</strong> {ts}Created{/ts}</span>
          <span><strong>{$lastImportSummary.updated|escape}</strong> {ts}Updated{/ts}</span>
          <span><strong>{$lastImportSummary.removed|escape}</strong> {ts}Removed{/ts}</span>
          <span><strong>{$lastImportSummary.unchanged|escape}</strong> {ts}Unchanged{/ts}</span>
          {if $lastImportSummary.warnings gt 0}<span><strong>{$lastImportSummary.warnings|escape}</strong> {ts}Warnings{/ts}</span>{/if}
          {if $lastImportSummary.errors gt 0}<span><strong>{$lastImportSummary.errors|escape}</strong> {ts}Errors{/ts}</span>{/if}
        </div>
        {if $lastImportSummary.ok}
          <p class="description">{ts}Import finished. Synchronize below shows the current result after applying the Saved Configs.{/ts}</p>
        {else}
          <p class="description"><strong>{ts}Import stopped with a problem.{/ts}</strong> {ts}Review what was applied and the problem below before trying again. No later removal step runs after an earlier import failure.{/ts}</p>
          {if $lastImportSummary.problem}<div class="messages error no-popup"><strong>{ts}Problem:{/ts}</strong> {$lastImportSummary.problem|escape}</div>{/if}
          {if $lastImportSummary.next_action}<p><strong>{ts}Next:{/ts}</strong> {$lastImportSummary.next_action|escape}</p>{/if}
        {/if}
      </div>
    </details>
  {/if}
