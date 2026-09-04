  {if $op neq 'settings'}
    <details class="civicfg-filter" {if $selectedTypes|@count gt 0}open="open"{/if}>
      <summary>{ts}Filter Config Types{/ts}</summary>
      <div class="civicfg-filter-body">
        <form method="get" action="{crmURL p='civicrm/admin/config-manager'}">
          <input type="hidden" name="reset" value="1" />
          <input type="hidden" name="op" value="{$op|escape}" />
          <p class="description">{ts}Leave all unchecked for all managed types. Select one or more types to narrow the preview.{/ts}</p>
          {if $selectedTypes|@count gt 0 and $exportDependencyTypes|@count gt 0}
            <div class="messages warning no-popup">
              {ts}Dependency-aware export/import will also include related config types:{/ts}
              {foreach from=$exportDependencyTypeLabels key=depType item=depLabel}<code>{$depLabel|escape}</code> {/foreach}
            </div>
          {/if}
          <div class="civicfg-type-group">
            <h4>{ts}Standard managed types{/ts}</h4>
            <div class="civicfg-checkbox-grid">
              {foreach from=$allTypes item=row}
                {if !$row.virtual}
                  <label class="civicfg-type-option"><input type="checkbox" name="type[]" value="{$row.type|escape}" {if $selectedTypesMap[$row.type]}checked="checked"{/if} /> <span class="civicfg-type-text"><span class="civicfg-type-name">{$row.label|escape}</span><small>{$row.directory|escape}{if $row.hasSavedCount} · {$row.savedCount|escape} {ts}saved{/ts}{/if}</small></span></label>
                {/if}
              {/foreach}
            </div>
          </div>
          <div class="civicfg-type-group civicfg-type-group-extension">
            <h4>{ts}Extension-owned managed config{/ts}</h4>
            <p class="description">{ts}Only extension configuration proven safe for automatic management appears here. Other detected extension providers, including monitor-only or unsupported ones, are explained in Settings.{/ts}</p>
            {if $extensionManagedTypeCount eq 0}<p class="civicfg-muted">{ts}No extension-owned configuration is currently approved for automatic management.{/ts}</p>{/if}
            <div class="civicfg-checkbox-grid">
              {foreach from=$allTypes item=row}
                {if $row.virtual}
                  <label class="civicfg-type-option civicfg-type-option-virtual"><input type="checkbox" name="type[]" value="{$row.type|escape}" {if $selectedTypesMap[$row.type]}checked="checked"{/if} /> <span class="civicfg-type-text"><span class="civicfg-type-name">{$row.label|escape}</span>{if $row.provider}<small>{$row.provider|escape}</small>{/if}</span></label>
                {/if}
              {/foreach}
            </div>
            <p><a href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=settings'}">{ts}Review detected extension providers in Settings{/ts}</a></p>
          </div>
          <div class="civicfg-actions">
            <button type="submit" class="button"><span>{ts}Apply{/ts}</span></button>
            <a class="button" href="{crmURL p='civicrm/admin/config-manager' q="reset=1&op=$op"}"><span>{ts}Clear{/ts}</span></a>
          </div>
        </form>
      </div>
    </details>
  {/if}
