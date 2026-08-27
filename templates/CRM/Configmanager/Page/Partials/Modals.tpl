  {foreach from=$diffFiles item=file}
    <div class="civicfg-modal" id="{$file.id|escape}" aria-hidden="true" hidden="hidden"{if $file.details_lazy} data-civicfg-diff-detail="1" data-civicfg-path="{$file.path|escape}" data-civicfg-detail-url="{$diffDetailUrl|escape}"{/if}>
      <div class="civicfg-modal-box">
        <div class="civicfg-modal-header">
          <strong>{$file.path|escape}</strong>
          <button type="button" class="civicfg-close" data-civicfg-close="1" aria-label="{ts}Close{/ts}">×</button>
        </div>
        <div class="civicfg-modal-body">
          <p>{ts}YAML file is the intended configuration from disk. Active CiviCRM is the current database state. Only changed fields are shown.{/ts}</p>
          {if $file.details_lazy}
            <div class="civicfg-lazy-detail" data-civicfg-lazy-detail-host="1">
              <p class="description">{ts}Loading field-level details for this item…{/ts}</p>
            </div>
          {else}
            {if $file.rows}
              <table class="civicfg-diff-table">
                <thead><tr><th>{ts}Field{/ts}</th><th>{ts}YAML File{/ts}</th><th>{ts}Active CiviCRM{/ts}</th></tr></thead>
                <tbody>
                  {foreach from=$file.rows item=row}
                    <tr>
                      <td><strong>{$row.label|escape}</strong><br /><code>{$row.path|escape}</code><div class="civicfg-row-sentence">{$row.sentence|escape}</div></td>
                      <td class="civicfg-diff-old"><div class="civicfg-diff-value">{$row.old_html nofilter}</div></td>
                      <td class="civicfg-diff-new"><div class="civicfg-diff-value">{$row.new_html nofilter}</div></td>
                    </tr>
                  {/foreach}
                </tbody>
              </table>
            {/if}
            <details>
              <summary>{ts}Show Diff Text{/ts}</summary>
              <pre class="civicfg-diff">{$file.diff|escape}</pre>
            </details>
          {/if}
        </div>
      </div>
    </div>
    {if $canAdminister}
      <div class="civicfg-modal" id="{$file.id|escape}-ignore" aria-hidden="true" hidden="hidden">
        <div class="civicfg-modal-box">
          <div class="civicfg-modal-header">
            <strong>{ts}Ignore configuration{/ts}: {$file.path|escape}</strong>
            <button type="button" class="civicfg-close" data-civicfg-close="1" aria-label="{ts}Close{/ts}">×</button>
          </div>
          <div class="civicfg-modal-body">
            <p class="description">{ts}Ignoring config hides it from diff, validate, export, import, ZIP download, and preview. Use this only for intentional environment-specific differences. Dependency or identity fields should normally not be ignored.{/ts}</p>
            <form method="post" action="{crmURL p='civicrm/admin/config-manager' q='reset=1&op=sync'}" data-civicfg-confirm-modal="1" data-civicfg-confirm-title="Confirm Config Ignore" data-civicfg-confirm-word="IGNORE" data-civicfg-confirm-button="Ignore" data-civicfg-confirm-message="This will save a Config Ignore rule in Configuration Manager settings." data-civicfg-confirm-warning="Ignored config will no longer appear in sync/import/export checks until the ignore rule is removed.">
          <input type="hidden" name="civicfg_csrf" value="{$civicfgCsrfToken|escape}" />
              <input type="hidden" name="_action" value="ignore_config" />
              <input type="hidden" name="path" value="{$file.path|escape}" />
              <div class="civicfg-ignore-choice">
                <label><input type="radio" name="ignore_scope" value="file" checked="checked" data-civicfg-ignore-file="1" /> {ts}Ignore the whole YAML file{/ts}</label>
              </div>
              {if $file.rows || $file.details_lazy}
                <div class="civicfg-ignore-choice"{if $file.details_lazy} data-civicfg-ignore-lazy-choice="1" hidden="hidden"{/if}>
                  <label><input type="radio" name="ignore_scope" value="fields" data-civicfg-ignore-fields-radio="1" /> {ts}Ignore only selected field(s){/ts}</label>
                  <div class="civicfg-ignore-fields" data-civicfg-ignore-fields="1"{if $file.details_lazy} data-civicfg-ignore-field-host="1"{/if}>
                    {foreach from=$file.rows item=row}
                      <label><input type="checkbox" name="value_path[]" value="{$row.path|escape}" /> {$row.label|escape} <code>{$row.path|escape}</code></label>
                    {/foreach}
                  </div>
                </div>
              {/if}
              <div class="civicfg-actions">
                <button type="submit" class="button"><span>{ts}Save Ignore Rule{/ts}</span></button>
                <button type="button" class="button" data-civicfg-close="1"><span>{ts}Cancel{/ts}</span></button>
              </div>
            </form>
          </div>
        </div>
      </div>
    {/if}
  {/foreach}
