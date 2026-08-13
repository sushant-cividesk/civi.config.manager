  <div class="civicfg-tabs">
    {foreach from=$tabs item=tab}
      <a class="civicfg-tab{if $tab.active} active{/if}" href="{$tab.url|escape}">{$tab.label|escape}</a>
    {/foreach}
  </div>

  {if $op eq 'sync'}
    <p class="civicfg-help">{ts}Review pending differences between active CiviCRM configuration and YAML files. Export writes CiviCRM changes to YAML. Import applies YAML changes to CiviCRM.{/ts}</p>
  {elseif $op eq 'import'}
    <p class="civicfg-help">{ts}Review YAML files in the sync directory before applying them to CiviCRM. Import treats YAML as the source of truth and may update, create, or delete supported records after confirmation.{/ts}</p>
  {elseif $op eq 'export'}
    <p class="civicfg-help">{ts}Export the active CiviCRM configuration as a ZIP archive or preview one YAML file before saving it.{/ts}</p>
  {elseif $op eq 'settings'}
    <p class="civicfg-help">{ts}Choose the sync directory and which config types are managed by this site.{/ts}</p>
  {/if}

  {if $notice}<div class="messages status no-popup">{$notice|escape}</div>{/if}
  {if $result.error}<div class="messages error no-popup">{$result.error|escape}</div>{/if}
  {if $summary.error_count gt 0}<div class="messages error no-popup">{ts 1=$summary.error_count}%1 Error(s) Reported. Review the page messages and logs for details.{/ts}</div>{/if}

  {foreach from=$importMessages item=message}
    <div class="messages {if $message.type eq 'error'}error{else}warning{/if} no-popup"><strong>{$message.title|escape}:</strong> {$message.message|escape}</div>
  {/foreach}

  <div class="civicfg-cards">
    <div class="civicfg-card">
      <div class="civicfg-card-label">{ts}Status{/ts}</div>
      <div class="civicfg-card-value">
        {if $summary.total_changes gt 0}
          <span class="civicfg-badge warn">{ts 1=$summary.total_changes}%1 Difference(s){/ts}</span>
        {else}
          <span class="civicfg-badge good">{ts}In Sync{/ts}</span>
        {/if}
      </div>
    </div>
    <div class="civicfg-card">
      <div class="civicfg-card-label">{ts}Changes{/ts}</div>
      <div>{ts}Changed{/ts}: {$summary.changed_count|escape}</div>
      <div>{ts}Only in CiviCRM{/ts}: {$summary.new_count|escape}</div>
      <div>{ts}Only in YAML{/ts}: {$summary.missing_count|escape}</div>
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
