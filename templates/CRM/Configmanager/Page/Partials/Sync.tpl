  {if $op eq 'sync'}
    <div class="civicfg-actions">
      {if $managedScopeConfigured && $canExport}<form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" {if $exportNeedsConfirmation}data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Export YAML Changes" data-civicfg-confirm-word="EXPORT" data-civicfg-confirm-button="Export" data-civicfg-confirm-message="{$exportConfirmMessage|escape}" data-civicfg-confirm-warning="{$exportConfirmWarning|escape}"{/if}>
        <input type="hidden" name="_action" value="export_write" />
        {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
        <button type="submit" class="button"><span>{if $initialExportRequired}{ts}Create Initial Export{/ts}{else}{ts}Export{/ts}{/if}</span></button>
      </form>{/if}
      {if $managedScopeConfigured && !$initialExportRequired && $canImport}<a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=import'}"><span>{ts}Import{/ts}</span></a>{/if}
      {if $managedScopeConfigured && !$initialExportRequired}
        <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}">
          <input type="hidden" name="_action" value="validate_files" />
          {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
          <button type="submit" class="button"><span>{ts}Validate{/ts}</span></button>
        </form>
      {/if}
      {if $canAdminister && $watchedScopeConfigured}
        <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}">
          <input type="hidden" name="_action" value="scan_watch" />
          {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
          <button type="submit" class="button"><span>{ts}Scan Watched Config{/ts}</span></button>
        </form>
      {/if}
    </div>

    {if !$managedScopeConfigured}
      <div class="civicfg-panel civicfg-initial-export-panel">
        <div class="civicfg-panel-body">
          {if $watchOnlyScope}
            <h3>{ts}Monitoring is configured; nothing is managed in YAML{/ts}</h3>
            <p>{ts}Watch-only configuration can be scanned for drift, but it is intentionally excluded from YAML synchronization.{/ts}</p>
            <p>{ts}Choose Manage everything or Manage selected items in Settings when you want Configuration Manager to establish a managed YAML baseline.{/ts}</p>
          {else}
            <h3>{ts}Choose what Configuration Manager should manage{/ts}</h3>
            <p>{ts}No configuration is currently managed in YAML, so there is no synchronization baseline and the site cannot be reported as In Sync yet.{/ts}</p>
            <p>{ts}Open Settings, choose Manage everything or Manage selected items for at least one configuration type, save, then create the initial export.{/ts}</p>
          {/if}
          {if $canAdminister}<p><a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=settings'}"><span>{ts}Review Configuration Scope{/ts}</span></a></p>{/if}
        </div>
      </div>
    {elseif $initialExportRequired}
      <div class="civicfg-panel civicfg-initial-export-panel">
        <div class="civicfg-panel-body">
          <h3>{ts}Create the initial configuration export{/ts}</h3>
          <p>{ts}There is no YAML baseline yet, so Configuration Manager is not showing every existing CiviCRM record as a difference.{/ts}</p>
          <p>{ts}Your managed scope is ready. Create the initial export; after that, Synchronize will show only real changes between managed YAML and active CiviCRM.{/ts}</p>
          {if $canAdminister}<p><a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=settings'}"><span>{ts}Review Configuration Scope{/ts}</span></a></p>{/if}
        </div>
      </div>
    {else}
      {if $diffResult.errors|@count gt 0}
        <details class="civicfg-panel civicfg-error-panel" open="open">
          <summary>{ts}Synchronization Errors{/ts} <span class="civicfg-badge bad">{$diffResult.errors|@count|escape}</span></summary>
          <div class="civicfg-panel-body">
            <div class="messages error no-popup">
              <strong>{ts}The managed comparison is incomplete. Configuration Manager will not report this site as In Sync until these errors are resolved.{/ts}</strong>
              <ul>
                {foreach from=$diffResult.errors item=error}
                  <li>{if $error.type}<code>{$error.type|escape}</code>: {/if}{$error.message|escape}</li>
                {/foreach}
              </ul>
            </div>
          </div>
        </details>
      {/if}

      {if $validationResult}
        <details class="civicfg-panel civicfg-validation-panel" open="open">
          <summary>{ts}Validation Details{/ts}</summary>
          <div class="civicfg-panel-body">
            {if $validationResult.ok}
              <div class="messages status no-popup">{ts}YAML validation passed.{/ts}</div>
            {else}
              <div class="messages error no-popup">{ts}YAML validation found problems.{/ts}</div>
            {/if}

            {if $validationResult.errors|@count gt 0}
              <div class="messages error no-popup">
                <strong>{ts}Validation errors{/ts}</strong>
                <ul>
                  {foreach from=$validationResult.errors item=error}
                    <li>{if $error.type}<code>{$error.type|escape}</code>: {/if}{$error.message|escape}</li>
                  {/foreach}
                </ul>
              </div>
            {/if}

            {foreach from=$validationResult.items item=item}
              {if $item.errors|@count gt 0 || $item.warnings|@count gt 0 || $item.compatibility|@count gt 0}
                <div class="civicfg-change-card">
                  <h4>{$item.type|escape}</h4>
                  {if $item.errors|@count gt 0}
                    <div class="messages error no-popup">
                      <strong>{ts}Errors{/ts}</strong>
                      <ul>{foreach from=$item.errors item=error}<li>{if $error.file}<code>{$error.file|escape}</code>: {/if}{$error.message|escape}</li>{/foreach}</ul>
                    </div>
                  {/if}
                  {if $item.warnings|@count gt 0}
                    <div class="messages warning no-popup">
                      <strong>{ts}Warnings{/ts}</strong>
                      <ul>{foreach from=$item.warnings item=warning}<li>{if $warning.file}<code>{$warning.file|escape}</code>: {/if}{$warning.message|escape}</li>{/foreach}</ul>
                    </div>
                  {/if}
                  {if $item.compatibility|@count gt 0}
                    <div class="messages status no-popup civicfg-compatibility-note">
                      <strong>{ts}Compatibility information{/ts}</strong>
                      <p>{ts}These items are valid YAML. Configuration Manager keeps them safe by limiting automatic writes where the provider does not expose a reliable portable identity.{/ts}</p>
                      <ul>{foreach from=$item.compatibility item=note}<li>{if $note.file}<code>{$note.file|escape}</code>: {/if}{$note.message|escape}</li>{/foreach}</ul>
                    </div>
                  {/if}
                </div>
              {/if}
            {/foreach}
          </div>
        </details>
      {/if}

      {if $summary.total_changes eq 0}
        {if $summary.error_count gt 0}
          <div class="messages error no-popup">{ts}No differences were calculated, but synchronization is not confirmed because one or more managed configuration providers could not be read.{/ts}</div>
        {else}
          <div class="messages status no-popup">{ts}Managed configuration is in sync. No pending changes were found.{/ts}</div>
        {/if}
      {else}
        <details class="civicfg-panel civicfg-summary-panel" open="open">
          <summary>{ts}Pending Changes{/ts}</summary>
          <div class="civicfg-panel-body">
            <p class="description">{ts}These counts cover managed configuration only. Open a changed item for the exact field-level comparison before exporting, importing, reverting, or ignoring it.{/ts}</p>
            <div class="civicfg-type-lines">
              {foreach from=$allTypes item=row}
                {if $row.changedCount gt 0 || $row.newCount gt 0 || $row.missingCount gt 0}
                  <div class="civicfg-type-line">
                    <strong>{$row.label|escape}</strong>
                    {if $row.changedCount gt 0}<span class="civicfg-badge warn">{$row.changedCount|escape} {ts}Changed{/ts}</span>{/if}
                    {if $row.newCount gt 0}<span class="civicfg-badge warn">{$row.newCount|escape} {ts}New in CiviCRM{/ts}</span>{/if}
                    {if $row.missingCount gt 0}<span class="civicfg-badge warn">{$row.missingCount|escape} {ts}Missing from CiviCRM{/ts}</span>{/if}
                  </div>
                {/if}
              {/foreach}
            </div>
          </div>
        </details>

        <details class="civicfg-panel civicfg-files-panel" {if $diffFiles|@count lt 6}open="open"{/if}>
          <summary>{ts}Changes to Review{/ts}{if $diffFiles|@count gt 5} <span class="civicfg-muted">({$diffFiles|@count|escape} {ts}items{/ts})</span>{/if}</summary>
          <div class="civicfg-panel-body">
            <p class="description">{ts}Only managed configuration with a real difference is listed here. The short explanation highlights useful changes; technical metadata and the complete field comparison stay under Details.{/ts}</p>
            <div class="civicfg-file-lines">
              {foreach from=$diffFiles item=file}
                <div class="civicfg-file-card civicfg-state-{$file.status|escape}">
                  <div class="civicfg-file-main">
                    <div class="civicfg-file-title"><strong>{$file.display_title|escape}</strong></div>
                    <div class="civicfg-file-meta">
                      <span class="civicfg-badge civicfg-badge-{$file.status|escape}">{$file.status_label|escape}</span>
                      {if $file.sync_state_label}<span class="civicfg-badge warn">{$file.sync_state_label|escape}</span>{/if}
                      <span class="civicfg-muted">{$file.type_label|escape}</span>
                      <span class="civicfg-muted"><code>{$file.path|escape}</code></span>
                    </div>
                    <div class="civicfg-file-summary">{$file.summary_sentence|escape}</div>
                    {if $file.detail_sentences|@count gt 0}
                      <ul class="civicfg-change-sentences">
                        {foreach from=$file.detail_sentences item=sentence name=sentences}
                          {if $smarty.foreach.sentences.index lt 3}<li>{$sentence|escape}</li>{/if}
                        {/foreach}
                      </ul>
                    {/if}
                  </div>
                  <div class="civicfg-row-actions">
                    <button type="button" class="button civicfg-action-secondary civicfg-line-button" data-civicfg-open="{$file.id|escape}"><span>{ts}Details{/ts}</span></button>
                    {if $canImport && ($file.status neq 'new_in_db' || $file.delete_allowed)}
                      <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Revert Active CiviCRM From YAML" data-civicfg-confirm-word="REVERT" data-civicfg-confirm-button="Revert" data-civicfg-confirm-message="This will apply this YAML file back to active CiviCRM. If the YAML file has dependencies, those dependency YAML files are applied with it. If the YAML file does not exist, the matching managed CiviCRM record is removed only when that scope permits deletion." data-civicfg-confirm-warning="Only the selected managed file and its dependency closure are reverted: {$file.path|escape}.">
                        <input type="hidden" name="_action" value="revert_file" />
                        <input type="hidden" name="path" value="{$file.path|escape}" />
                        <button type="submit" class="button civicfg-action-apply civicfg-line-button"><span>{ts}Restore from YAML{/ts}</span></button>
                      </form>
                    {elseif $file.status eq 'new_in_db' && !$file.delete_allowed}
                      <span class="civicfg-muted">{ts}Export to add this selected item to managed YAML.{/ts}</span>
                    {/if}
                    {if $canAdminister}<button type="button" class="button civicfg-action-ignore civicfg-line-button" data-civicfg-open="{$file.id|escape}-ignore"><span>{ts}Ignore{/ts}</span></button>{/if}
                  </div>
                </div>
              {/foreach}
            </div>
          </div>
        </details>
      {/if}
    {/if}

    {if $watchSummary.scanned_at || $watchHistory|@count gt 0}
      <details id="civicfg-watch-panel" class="civicfg-panel civicfg-watch-panel" {if $watchPanelOpen}open="open"{/if}>
        <summary>
          <span>{ts}Watched Configuration{/ts}</span>
          {if $watchDetectedCount gt 0}<span class="civicfg-badge warn">{$watchDetectedCount|escape} {ts}detected this scan{/ts}</span>{elseif $watchHistory|@count gt 0}<span class="civicfg-badge">{$watchHistory|@count|escape} {ts}in history{/ts}</span>{/if}
        </summary>
        <div class="civicfg-panel-body">
          {if $watchSummary.scanned_at}
            <div class="civicfg-watch-overview">
              <div><span class="civicfg-watch-stat-label">{ts}Last scan{/ts}</span><strong>{$watchSummary.scanned_at|escape}</strong></div>
              <div><span class="civicfg-watch-stat-label">{ts}Watched{/ts}</span><strong>{$watchSummary.watched|default:0|escape}</strong></div>
              <div><span class="civicfg-watch-stat-label">{ts}Baseline{/ts}</span><strong>{$watchSummary.baseline|default:0|escape}</strong></div>
              <div><span class="civicfg-watch-stat-label">{ts}New{/ts}</span><strong>{$watchSummary.new|default:0|escape}</strong></div>
              <div><span class="civicfg-watch-stat-label">{ts}Changed{/ts}</span><strong>{$watchSummary.changed|default:0|escape}</strong></div>
              <div><span class="civicfg-watch-stat-label">{ts}Missing{/ts}</span><strong>{$watchSummary.missing|default:0|escape}</strong></div>
            </div>
            {if $watchSummary.baseline gt 0}<p class="description">{ts}A monitoring baseline was captured for newly watched items. Future scans compare against these fingerprints; watched items still stay out of YAML and cannot be imported or deleted.{/ts}</p>{/if}
          {/if}
          <p class="description">{ts}Watched configuration is not part of YAML and cannot be imported, restored, or deleted by Configuration Manager unless you explicitly move it into managed scope. Detected watch changes are kept in local history so later scans do not hide earlier findings.{/ts}</p>

          <div class="civicfg-watch-section">
            <h4>{ts}Latest scan{/ts}</h4>
            {if $watchSummary.items|@count gt 0}
              <div class="civicfg-watch-findings">
                {foreach from=$watchSummary.items item=item}
                  <div class="civicfg-watch-finding">
                    <span class="civicfg-badge {if $item.status eq 'missing'}warn{elseif $item.status eq 'changed'}warn{/if}">{if $item.status eq 'new'}{ts}New{/ts}{elseif $item.status eq 'changed'}{ts}Changed{/ts}{elseif $item.status eq 'missing'}{ts}Missing{/ts}{else}{$item.status|escape}{/if}</span>
                    <div><strong>{$item.label|escape}</strong><div class="civicfg-muted"><code>{$item.path|escape}</code></div></div>
                  </div>
                {/foreach}
              </div>
            {elseif $watchSummary.scanned_at}
              <div class="messages status no-popup civicfg-watch-no-change">{ts}No new watch-only changes were detected in the latest scan. Previous detections remain available in Recent watch history below.{/ts}</div>
            {/if}
          </div>

          {if $watchHistory|@count gt 0}
            <div class="civicfg-watch-section">
              <div class="civicfg-watch-history-heading">
                <div>
                  <h4>{ts}Recent watch history{/ts}</h4>
                  <p class="description">{ts}Newest detections are shown first. This is local operational history and does not change YAML or accepted managed baselines.{/ts}</p>
                </div>
                {if $canAdminister}
                  <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Clear Watch History" data-civicfg-confirm-word="CLEAR" data-civicfg-confirm-button="Clear history" data-civicfg-confirm-message="This clears the local list of previously detected watch-only changes. Monitoring fingerprints and baselines are kept." data-civicfg-confirm-warning="This does not change CiviCRM configuration or YAML.">
                    <input type="hidden" name="_action" value="clear_watch_history" />
                    <button type="submit" class="button civicfg-action-secondary"><span>{ts}Clear history{/ts}</span></button>
                  </form>
                {/if}
              </div>
              <div class="civicfg-watch-history-list">
                {foreach from=$watchHistory item=item}
                  <div class="civicfg-watch-history-row">
                    <div class="civicfg-watch-history-time">{$item.detected_at|escape}</div>
                    <span class="civicfg-badge {if $item.status eq 'missing'}warn{elseif $item.status eq 'changed'}warn{/if}">{if $item.status eq 'new'}{ts}New{/ts}{elseif $item.status eq 'changed'}{ts}Changed{/ts}{elseif $item.status eq 'missing'}{ts}Missing{/ts}{else}{$item.status|escape}{/if}</span>
                    <div class="civicfg-watch-history-item"><strong>{$item.label|escape}</strong><div class="civicfg-muted"><code>{$item.path|escape}</code></div></div>
                  </div>
                {/foreach}
              </div>
            </div>
          {/if}

          {if $watchSummary.errors|@count gt 0}
            <div class="messages warning no-popup"><ul>{foreach from=$watchSummary.errors item=error}<li>{$error.type|escape}: {$error.message|escape}</li>{/foreach}</ul></div>
          {/if}
        </div>
      </details>
    {/if}
  {/if}
