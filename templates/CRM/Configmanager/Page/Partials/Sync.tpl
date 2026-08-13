  {if $op eq 'sync'}
    <div class="civicfg-actions">
      {if $canExport}<form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" {if $exportNeedsConfirmation}data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Export YAML Changes" data-civicfg-confirm-word="EXPORT" data-civicfg-confirm-button="Export" data-civicfg-confirm-message="{$exportConfirmMessage|escape}" data-civicfg-confirm-warning="{$exportConfirmWarning|escape}"{/if}>
        <input type="hidden" name="_action" value="export_write" />
        {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
        <button type="submit" class="button"><span>{ts}Export{/ts}</span></button>
      </form>{/if}
      {if $canImport}<a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=import'}"><span>{ts}Import{/ts}</span></a>{/if}
      <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}">
        <input type="hidden" name="_action" value="validate_files" />
        {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
        <button type="submit" class="button"><span>{ts}Validate{/ts}</span></button>
      </form>
    </div>

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
                  <li>
                    {if $error.type}<code>{$error.type|escape}</code>: {/if}
                    {$error.message|escape}
                  </li>
                {/foreach}
              </ul>
            </div>
          {/if}

          {foreach from=$validationResult.items item=item}
            {if $item.errors|@count gt 0 || $item.warnings|@count gt 0}
              <div class="civicfg-change-card">
                <h4>{$item.type|escape}</h4>

                {if $item.errors|@count gt 0}
                  <div class="messages error no-popup">
                    <strong>{ts}Errors{/ts}</strong>
                    <ul>
                      {foreach from=$item.errors item=error}
                        <li>
                          {if $error.file}<code>{$error.file|escape}</code>: {/if}
                          {$error.message|escape}
                        </li>
                      {/foreach}
                    </ul>
                  </div>
                {/if}

                {if $item.warnings|@count gt 0}
                  <div class="messages warning no-popup">
                    <strong>{ts}Warnings{/ts}</strong>
                    <ul>
                      {foreach from=$item.warnings item=warning}
                        <li>
                          {if $warning.file}<code>{$warning.file|escape}</code>: {/if}
                          {$warning.message|escape}
                        </li>
                      {/foreach}
                    </ul>
                  </div>
                {/if}
              </div>
            {/if}
          {/foreach}
        </div>
      </details>
    {/if}

    {if $summary.total_changes eq 0}
      <div class="messages status no-popup">{ts}No pending configuration changes.{/ts}</div>
    {else}
      <details class="civicfg-panel civicfg-summary-panel" open="open">
        <summary>{ts}Pending Changes{/ts}</summary>
        <div class="civicfg-panel-body">
          <p class="description">{ts}These are summary counts by config type. In CiviCRM means the active database has config not yet exported to YAML. In YAML means a YAML file exists for config that is missing from CiviCRM. Use Changed Files and Diff for field-level review.{/ts}</p>
          <div class="civicfg-type-lines">
            {foreach from=$allTypes item=row}
              {if $row.changedCount gt 0 || $row.newCount gt 0 || $row.missingCount gt 0}
                <div class="civicfg-type-line">
                  <strong>{$row.label|escape}</strong>
                  <code>{$row.type|escape}</code>
                  {if $row.changedCount gt 0}<span class="civicfg-badge warn">{$row.changedCount|escape} {ts}Different{/ts}</span>{/if}
                  {if $row.newCount gt 0}<span class="civicfg-badge warn">{$row.newCount|escape} {ts}Only in CiviCRM{/ts}</span>{/if}
                  {if $row.missingCount gt 0}<span class="civicfg-badge warn">{$row.missingCount|escape} {ts}Only in YAML{/ts}</span>{/if}
                </div>
              {/if}
            {/foreach}
          </div>
        </div>
      </details>

      <details class="civicfg-panel civicfg-files-panel" open="open">
        <summary>{ts}Files with Differences{/ts}</summary>
        <div class="civicfg-panel-body">
          <p class="description">{ts}Only files with differences are listed. Each row shows whether configuration exists only in active CiviCRM, only in YAML, or has different values on both sides. Open Diff for exact fields before exporting, importing, reverting, or ignoring.{/ts}</p>
          <div class="civicfg-file-lines">
            {foreach from=$diffFiles item=file}
              <div class="civicfg-file-card civicfg-state-{$file.status|escape}">
                <div class="civicfg-file-main">
                  <div class="civicfg-file-title"><code class="civicfg-file-code">{$file.path|escape}</code></div>
                  <div class="civicfg-file-meta">
                    <span class="civicfg-badge civicfg-badge-{$file.status|escape}">{$file.status_label|escape}</span>
                    <span class="civicfg-muted">{$file.change_count|escape} {if $file.status eq 'changed'}{ts}changed field(s){/ts}{elseif $file.status eq 'new_in_db'}{ts}field(s) to export{/ts}{else}{ts}field(s) in YAML{/ts}{/if}</span>
                    <span class="civicfg-muted">{$file.type_label|escape}</span>
                  </div>
                  <div class="civicfg-file-summary">{$file.summary_sentence|escape}</div>
                  {if $file.detail_sentences|@count gt 1}
                    <ul class="civicfg-change-sentences">
                      {foreach from=$file.detail_sentences item=sentence name=sentences}
                        {if $smarty.foreach.sentences.index lt 3}<li>{$sentence|escape}</li>{/if}
                      {/foreach}
                    </ul>
                  {/if}
                </div>
                <div class="civicfg-row-actions">
                  <button type="button" class="button civicfg-action-secondary civicfg-line-button" data-civicfg-open="{$file.id|escape}"><span>{ts}Details{/ts}</span></button>
                  {if $canImport}
                    <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Revert Active CiviCRM From YAML" data-civicfg-confirm-word="REVERT" data-civicfg-confirm-button="Revert" data-civicfg-confirm-message="This will apply this YAML file back to active CiviCRM. If the YAML file has dependencies, those dependency YAML files are applied with it. If the YAML file does not exist, the matching CiviCRM record is removed when the handler supports deletion." data-civicfg-confirm-warning="Only the selected file and its dependency closure are reverted: {$file.path|escape}.">
                      <input type="hidden" name="_action" value="revert_file" />
                      <input type="hidden" name="path" value="{$file.path|escape}" />
                      <button type="submit" class="button civicfg-action-apply civicfg-line-button"><span>{ts}Revert CiviCRM{/ts}</span></button>
                    </form>
                  {/if}
                  {if $canAdminister}
                    <button type="button" class="button civicfg-action-ignore civicfg-line-button" data-civicfg-open="{$file.id|escape}-ignore"><span>{ts}Ignore{/ts}</span></button>
                  {/if}
                </div>
              </div>
            {/foreach}
          </div>
        </div>
      </details>
    {/if}

    <div class="civicfg-actions">
      {if $canExport}<form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" {if $exportNeedsConfirmation}data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Export YAML Changes" data-civicfg-confirm-word="EXPORT" data-civicfg-confirm-button="Export" data-civicfg-confirm-message="{$exportConfirmMessage|escape}" data-civicfg-confirm-warning="{$exportConfirmWarning|escape}"{/if}>
        <input type="hidden" name="_action" value="export_write" />
        {foreach from=$selectedTypes item=type}<input type="hidden" name="type[]" value="{$type|escape}" />{/foreach}
        <button type="submit" class="button"><span>{ts}Export{/ts}</span></button>
      </form>{/if}
      {if $canImport}<a class="button" href="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=import'}"><span>{ts}Import{/ts}</span></a>{/if}
    </div>
  {/if}
