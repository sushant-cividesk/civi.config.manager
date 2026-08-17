/* Configuration Manager vanilla JavaScript. */
/*
 * Configuration Manager UI interactions.
 * Uses vanilla JavaScript only for CiviCRM 5.x/6.x compatibility.
 */
(function() {
  function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function() {
    document.querySelectorAll('.crm-configmanager-block [data-civicfg-open]').forEach(function(btn) {
      btn.addEventListener('click', function(ev) {
        ev.preventDefault();
        var modal = document.getElementById(btn.getAttribute('data-civicfg-open'));
        if (modal) {
          modal.hidden = false;
          modal.setAttribute('aria-hidden', 'false');
          modal.classList.add('is-open');
          modal.querySelectorAll('form').forEach(function(form) {
            var fileRadio = form.querySelector('input[data-civicfg-ignore-file]');
            if (fileRadio && fileRadio.checked) {
              form.querySelectorAll('[data-civicfg-ignore-fields] input[type="checkbox"]').forEach(function(box) { box.checked = false; });
            }
          });
        }
      });
    });
    document.querySelectorAll('.crm-configmanager-block [data-civicfg-close]').forEach(function(btn) {
      btn.addEventListener('click', function(ev) {
        ev.preventDefault();
        var modal = btn.closest('.civicfg-modal');
        if (modal) { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); modal.hidden = true; }
      });
    });
    document.querySelectorAll('.crm-configmanager-block .civicfg-modal').forEach(function(modal) {
      modal.addEventListener('click', function(ev) { if (ev.target === modal) { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); modal.hidden = true; } });
    });
    document.addEventListener('keydown', function(ev) {
      if (ev.key === 'Escape') {
        document.querySelectorAll('.crm-configmanager-block .civicfg-modal.is-open').forEach(function(modal) { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); modal.hidden = true; });
      }
    });

    document.addEventListener('change', function(ev) {
      var target = ev.target;
      if (!target || !target.closest || !target.closest('.crm-configmanager-block')) { return; }
      var form = target.closest('form');
      if (!form) { return; }
      if (target.matches('[data-civicfg-ignore-fields] input[type="checkbox"]')) {
        var fieldsRadio = form.querySelector('input[data-civicfg-ignore-fields-radio]');
        if (target.checked && fieldsRadio) {
          fieldsRadio.checked = true;
        }
      }
      if (target.matches('input[data-civicfg-ignore-file]') && target.checked) {
        form.querySelectorAll('[data-civicfg-ignore-fields] input[type="checkbox"]').forEach(function(box) { box.checked = false; });
      }
      if (target.matches('input[data-civicfg-ignore-fields-radio]') && target.checked) {
        var first = form.querySelector('[data-civicfg-ignore-fields] input[type="checkbox"]');
        if (first && !form.querySelector('[data-civicfg-ignore-fields] input[type="checkbox"]:checked')) {
          first.focus();
        }
      }
    });

    document.querySelectorAll('.crm-configmanager-block form input[data-civicfg-ignore-file]').forEach(function(fileRadio) {
      fileRadio.addEventListener('change', function() {
        if (!fileRadio.checked) { return; }
        var form = fileRadio.closest('form');
        if (!form) { return; }
        form.querySelectorAll('[data-civicfg-ignore-fields] input[type="checkbox"]').forEach(function(box) {
          box.checked = false;
        });
      });
    });

    document.querySelectorAll('.crm-configmanager-block [data-civicfg-ignore-fields] input[type="checkbox"]').forEach(function(box) {
      box.addEventListener('change', function() {
        var form = box.closest('form');
        if (!form) { return; }
        var fieldsRadio = form.querySelector('input[data-civicfg-ignore-fields-radio]');
        if (box.checked && fieldsRadio) {
          fieldsRadio.checked = true;
        }
      });
    });

    document.querySelectorAll('.crm-configmanager-block input[data-civicfg-ignore-fields-radio]').forEach(function(fieldsRadio) {
      fieldsRadio.addEventListener('change', function() {
        if (!fieldsRadio.checked) { return; }
        var form = fieldsRadio.closest('form');
        if (!form) { return; }
        var first = form.querySelector('[data-civicfg-ignore-fields] input[type="checkbox"]');
        if (first && !form.querySelector('[data-civicfg-ignore-fields] input[type="checkbox"]:checked')) {
          first.focus();
        }
      });
    });



    function parseScopeSelectors(textarea) {
      if (!textarea) { return []; }
      var seen = {};
      return (textarea.value || '').split(/[\r\n,]+/).map(function(value) {
        return value.trim();
      }).filter(function(value) {
        if (!value || seen[value]) { return false; }
        seen[value] = true;
        return true;
      });
    }

    function updateScopeCount(row) {
      if (!row) { return; }
      var textarea = row.querySelector('[data-civicfg-scope-selectors]');
      var count = parseScopeSelectors(textarea).length;
      var target = row.querySelector('[data-civicfg-scope-count]');
      if (target) {
        target.textContent = count ? (count + ' selected') : 'None selected yet';
      }
    }

    function renderScopeChips(row, selectedItems) {
      if (!row) { return; }
      var host = row.querySelector('[data-civicfg-selected-chips]');
      if (!host) { return; }
      host.innerHTML = '';
      (selectedItems || []).slice(0, 8).forEach(function(item) {
        var chip = document.createElement('span');
        chip.className = 'civicfg-selected-chip';
        chip.textContent = item.label || item.path || item.selector || 'Selected item';
        host.appendChild(chip);
      });
      if ((selectedItems || []).length > 8) {
        var more = document.createElement('span');
        more.className = 'civicfg-muted';
        more.textContent = '+' + ((selectedItems || []).length - 8) + ' more';
        host.appendChild(more);
      }
    }

    function refreshScopeRow(row) {
      if (!row) { return; }
      var mode = row.querySelector('[data-civicfg-scope-mode]');
      var value = mode ? mode.value : 'all';
      var controls = row.querySelector('[data-civicfg-selected-controls]');
      if (controls) { controls.hidden = value !== 'selected'; }
      row.querySelectorAll('[data-civicfg-mode-help]').forEach(function(help) {
        help.hidden = help.getAttribute('data-civicfg-mode-help') !== value;
      });
      updateScopeCount(row);
    }

    document.querySelectorAll('[data-civicfg-scope-row]').forEach(function(row) {
      refreshScopeRow(row);
      var mode = row.querySelector('[data-civicfg-scope-mode]');
      if (mode) {
        mode.addEventListener('change', function() { refreshScopeRow(row); renderScopeSettingsExample(); });
      }
      var textarea = row.querySelector('[data-civicfg-scope-selectors]');
      if (textarea) {
        textarea.addEventListener('input', function() { updateScopeCount(row); renderScopeSettingsExample(); });
      }
      var watch = row.querySelector('input[name^="scope_watch_unmanaged"]');
      if (watch) { watch.addEventListener('change', renderScopeSettingsExample); }
    });

    function ensureScopePickerModal() {
      var existing = document.getElementById('civicfg-scope-picker-modal');
      if (existing) { return existing; }
      var modal = document.createElement('div');
      modal.id = 'civicfg-scope-picker-modal';
      modal.className = 'civicfg-modal civicfg-scope-picker-modal';
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = '' +
        '<div class="civicfg-modal-box civicfg-scope-picker-box" role="dialog" aria-modal="true" aria-labelledby="civicfg-scope-picker-title">' +
          '<div class="civicfg-modal-header"><strong id="civicfg-scope-picker-title">Choose managed items</strong><button type="button" class="civicfg-close" data-civicfg-scope-picker-close aria-label="Close">×</button></div>' +
          '<div class="civicfg-modal-body">' +
            '<p class="description" id="civicfg-scope-picker-help">Choose the items Configuration Manager should manage. Stable portable identities are saved automatically.</p>' +
            '<input type="search" class="crm-form-text civicfg-scope-search" id="civicfg-scope-picker-search" placeholder="Search items..." />' +
            '<div class="civicfg-scope-picker-status" id="civicfg-scope-picker-status" aria-live="polite"></div>' +
            '<div class="civicfg-scope-picker-list" id="civicfg-scope-picker-list"></div>' +
            '<div class="civicfg-actions"><button type="button" class="button" data-civicfg-scope-picker-apply><span>Use selected items</span></button><button type="button" class="button" data-civicfg-scope-picker-close><span>Cancel</span></button></div>' +
          '</div>' +
        '</div>';
      var host = document.querySelector('.crm-configmanager-block') || document.body;
      host.appendChild(modal);
      modal.querySelectorAll('[data-civicfg-scope-picker-close]').forEach(function(btn) {
        btn.addEventListener('click', function() { closeScopePicker(modal); });
      });
      modal.addEventListener('click', function(ev) { if (ev.target === modal) { closeScopePicker(modal); } });
      return modal;
    }

    function closeScopePicker(modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      modal.hidden = true;
      modal._civicfgRow = null;
    }

    function renderScopePickerItems(modal, items, currentSelectors) {
      var list = modal.querySelector('#civicfg-scope-picker-list');
      var status = modal.querySelector('#civicfg-scope-picker-status');
      list.innerHTML = '';
      var hasCurrentSelectors = currentSelectors.length > 0;
      var current = {};
      currentSelectors.forEach(function(selector) { current[selector] = true; });

      items.forEach(function(item, index) {
        var option = document.createElement('label');
        option.className = 'civicfg-scope-picker-item' + (item.missing ? ' is-missing' : '') + (!item.write_safe && !item.missing ? ' is-readonly' : '');
        option.setAttribute('data-search', ((item.label || '') + ' ' + (item.path || '') + ' ' + (item.source_id || '')).toLowerCase());
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = item.selector || '';
        checkbox.setAttribute('data-civicfg-scope-option', '1');
        checkbox.checked = !!current[checkbox.value] || (hasCurrentSelectors && !!item.selected);
        var body = document.createElement('span');
        body.className = 'civicfg-scope-picker-item-body';
        var title = document.createElement('strong');
        title.textContent = item.label || item.path || ('Item ' + (index + 1));
        var meta = document.createElement('span');
        meta.className = 'civicfg-muted civicfg-scope-picker-meta';
        var parts = [];
        if (item.path) { parts.push(item.path); }
        if (item.source_id) { parts.push('Local ID ' + item.source_id); }
        if (item.missing) { parts.push('Currently missing'); }
        else if (!item.write_safe) { parts.push('Backup/monitor only: automatic writes blocked'); }
        meta.textContent = parts.join(' • ');
        body.appendChild(title);
        if (parts.length) { body.appendChild(meta); }
        option.appendChild(checkbox);
        option.appendChild(body);
        list.appendChild(option);
      });
      status.textContent = items.length ? (items.length + ' item(s) available') : 'No selectable items were found for this configuration type.';
      status.setAttribute('data-civicfg-total-items', String(items.length));
    }

    function filterScopePickerItems(modal, query) {
      query = (query || '').trim().toLowerCase();
      var items = Array.prototype.slice.call(modal.querySelectorAll('.civicfg-scope-picker-item'));
      var visible = 0;
      items.forEach(function(item) {
        var matches = !query || (item.getAttribute('data-search') || '').indexOf(query) !== -1;
        item.classList.toggle('is-filtered', !matches);
        item.setAttribute('aria-hidden', matches ? 'false' : 'true');
        if (matches) { visible++; }
      });
      var status = modal.querySelector('#civicfg-scope-picker-status');
      if (!status) { return; }
      if (!items.length) {
        status.textContent = 'No selectable items were found for this configuration type.';
      }
      else if (query) {
        status.textContent = visible + ' of ' + items.length + ' item(s) shown';
      }
      else {
        status.textContent = items.length + ' item(s) available';
      }
    }

    function openScopePicker(row) {
      var form = row.closest('form');
      var endpoint = form ? form.getAttribute('data-civicfg-scope-options-url') : '';
      var type = row.getAttribute('data-civicfg-scope-row') || '';
      var label = row.getAttribute('data-scope-label') || type;
      var textarea = row.querySelector('[data-civicfg-scope-selectors]');
      var currentSelectors = parseScopeSelectors(textarea);
      var modal = ensureScopePickerModal();
      modal._civicfgRow = row;
      modal.querySelector('#civicfg-scope-picker-title').textContent = 'Choose ' + label;
      modal.querySelector('#civicfg-scope-picker-search').value = '';
      modal.querySelector('#civicfg-scope-picker-list').innerHTML = '';
      modal.querySelector('#civicfg-scope-picker-status').textContent = 'Loading current CiviCRM items...';
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');

      fetch(endpoint + '&scope_type=' + encodeURIComponent(type), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
        .then(function(response) { return response.json(); })
        .then(function(data) {
          if (!data || !data.ok) { throw new Error((data && data.error) ? data.error : 'Could not load configuration items.'); }
          var loadedItems = data.items || [];
          renderScopePickerItems(modal, loadedItems, currentSelectors);
          var current = {};
          currentSelectors.forEach(function(selector) { current[selector] = true; });
          var hasCurrent = currentSelectors.length > 0;
          renderScopeChips(row, loadedItems.filter(function(item) {
            return !!current[item.selector || ''] || (hasCurrent && !!item.selected);
          }));
        })
        .catch(function(error) {
          modal.querySelector('#civicfg-scope-picker-status').textContent = error.message || 'Could not load configuration items.';
        });
    }

    document.querySelectorAll('[data-civicfg-scope-picker]').forEach(function(button) {
      button.addEventListener('click', function() {
        var row = button.closest('[data-civicfg-scope-row]');
        if (row) { openScopePicker(row); }
      });
    });

    var scopePickerTrigger = document.querySelector('[data-civicfg-scope-picker]');
    if (scopePickerTrigger) {
      var scopePickerModal = ensureScopePickerModal();
      scopePickerModal.querySelector('#civicfg-scope-picker-search').addEventListener('input', function(ev) {
        filterScopePickerItems(scopePickerModal, ev.target.value || '');
      });
      scopePickerModal.querySelector('[data-civicfg-scope-picker-apply]').addEventListener('click', function() {
        var row = scopePickerModal._civicfgRow;
        if (!row) { closeScopePicker(scopePickerModal); return; }
        var textarea = row.querySelector('[data-civicfg-scope-selectors]');
        var selectors = [];
        var selectedItems = [];
        scopePickerModal.querySelectorAll('[data-civicfg-scope-option]:checked').forEach(function(box) {
          if (box.value) { selectors.push(box.value); }
          var option = box.closest('.civicfg-scope-picker-item');
          var title = option ? option.querySelector('strong') : null;
          selectedItems.push({selector: box.value, label: title ? title.textContent : box.value});
        });
        if (textarea) {
          textarea.value = selectors.join('\n');
          textarea.dispatchEvent(new Event('input', {bubbles: true}));
        }
        renderScopeChips(row, selectedItems);
        closeScopePicker(scopePickerModal);
      });
    }

    function phpQuote(value) {
      return "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    }

    function renderScopeSettingsExample() {
      var code = document.getElementById('civicfg-scope-settings-example');
      if (!code) { return; }
      var lines = ['global $civicrm_setting;', '', "$civicrm_setting['domain']['civicfg_scope'] = ["];
      document.querySelectorAll('[data-civicfg-scope-row]').forEach(function(row) {
        var type = row.getAttribute('data-civicfg-scope-row') || '';
        var modeField = row.querySelector('[data-civicfg-scope-mode]');
        var mode = modeField ? modeField.value : 'all';
        var selectors = parseScopeSelectors(row.querySelector('[data-civicfg-scope-selectors]'));
        var watch = row.querySelector('input[name^="scope_watch_unmanaged"]');
        if (mode === 'all') { return; }
        lines.push('  ' + phpQuote(type) + ' => [');
        lines.push('    \'mode\' => ' + phpQuote(mode) + ',');
        if (mode === 'selected') {
          lines.push('    \'selectors\' => [');
          selectors.forEach(function(selector) { lines.push('      ' + phpQuote(selector) + ','); });
          lines.push('    ],');
          if (watch && watch.checked) { lines.push('    \'watch_unmanaged\' => TRUE,'); }
        }
        lines.push('  ],');
      });
      lines.push('];');
      code.textContent = lines.join('\n');
    }
    renderScopeSettingsExample();

    var copyScopeButton = document.querySelector('[data-civicfg-copy-scope-example]');
    if (copyScopeButton) {
      copyScopeButton.addEventListener('click', function() {
        var code = document.getElementById('civicfg-scope-settings-example');
        var status = document.querySelector('[data-civicfg-copy-status]');
        var text = code ? code.textContent : '';
        if (!text || !navigator.clipboard) {
          if (status) { status.textContent = 'Select and copy the example manually.'; }
          return;
        }
        navigator.clipboard.writeText(text).then(function() {
          if (status) { status.textContent = 'Copied.'; }
        }).catch(function() {
          if (status) { status.textContent = 'Could not copy automatically.'; }
        });
      });
    }


    function showProgress(title, text) {
      var host = document.querySelector('.crm-configmanager-block') || document.body;
      if (document.getElementById('civicfg-progress-overlay')) { return; }
      host.classList.add('is-busy');
      var overlay = document.createElement('div');
      overlay.id = 'civicfg-progress-overlay';
      overlay.className = 'civicfg-progress-overlay';
      overlay.innerHTML = '' +
        '<div class="civicfg-progress-box" role="alert" aria-live="assertive">' +
          '<div class="civicfg-progress-title"></div>' +
          '<div class="civicfg-progress-text"></div>' +
          '<div class="civicfg-progress-bar"><span class="civicfg-progress-fill"></span></div>' +
        '</div>';
      overlay.querySelector('.civicfg-progress-title').textContent = title || 'Working...';
      overlay.querySelector('.civicfg-progress-text').textContent = text || 'Please wait. Do not refresh or leave this page.';
      host.appendChild(overlay);
    }

    function progressTextForForm(form) {
      var action = form.querySelector('input[name="_action"]');
      var value = action ? action.value : '';
      if (value.indexOf('import') === 0) { return ['Importing configuration', 'Applying YAML to CiviCRM. This may create, update, or delete supported records.']; }
      if (value.indexOf('export') === 0) { return ['Exporting configuration', 'Writing active CiviCRM configuration to YAML files.']; }
      if (value === 'validate_files') { return ['Validating configuration', 'Checking YAML files and dependency metadata.']; }
      if (value === 'revert_file') { return ['Reverting active CiviCRM', 'Applying the selected YAML file and dependencies back to CiviCRM.']; }
      if (value === 'ignore_config') { return ['Saving ignore rule', 'Updating Config Ignore settings.']; }
      if (value === 'save_settings') { return ['Saving settings', 'Updating Configuration Manager settings.']; }
      return ['Working', 'Please wait. Do not refresh or leave this page.'];
    }

    function ensureConfirmModal() {
      var existing = document.getElementById('civicfg-confirm-modal');
      if (existing) { return existing; }
      var modal = document.createElement('div');
      modal.id = 'civicfg-confirm-modal';
      modal.className = 'civicfg-modal';
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      modal.innerHTML = '' +
        '<div class="civicfg-modal-box civicfg-confirm-box" role="dialog" aria-modal="true" aria-labelledby="civicfg-confirm-title">' +
          '<div class="civicfg-modal-header"><strong id="civicfg-confirm-title">Confirm import</strong><button type="button" class="civicfg-close" data-civicfg-confirm-cancel="1" aria-label="Close">×</button></div>' +
          '<div class="civicfg-modal-body">' +
            '<p id="civicfg-confirm-message"></p>' +
            '<div id="civicfg-confirm-warning" class="messages warning no-popup"></div>' +
            '<label class="civicfg-confirm-check"><input type="checkbox" id="civicfg-confirm-reviewed" /> I reviewed the changed files, dependency notes, and understand this action can change active configuration.</label>' +
            '<label class="civicfg-confirm-label" for="civicfg-confirm-text">Type the confirmation word to continue</label>' +
            '<input type="text" id="civicfg-confirm-text" autocomplete="off" />' +
            '<div class="civicfg-actions"><button type="button" class="button" data-civicfg-confirm-apply="1" disabled><span>Continue</span></button><button type="button" class="button" data-civicfg-confirm-cancel="1"><span>Cancel</span></button></div>' +
          '</div>' +
        '</div>';
      var host = document.querySelector('.crm-configmanager-block') || document.body;
      host.appendChild(modal);
      return modal;
    }

    function closeConfirmModal(modal) {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      modal.hidden = true;
      modal._civicfgForm = null;
    }

    document.querySelectorAll('.crm-configmanager-block form[data-civicfg-confirm-modal]').forEach(function(form) {
      form.addEventListener('submit', function(ev) {
        if (form.getAttribute('data-civicfg-confirmed') === '1') {
          form.removeAttribute('data-civicfg-confirmed');
          return;
        }
        ev.preventDefault();
        var modal = ensureConfirmModal();
        var title = form.getAttribute('data-civicfg-confirm-title') || 'Confirm action';
        var message = form.getAttribute('data-civicfg-confirm-message') || 'This action will update configuration.';
        var word = form.getAttribute('data-civicfg-confirm-word') || 'IMPORT';
        var buttonText = form.getAttribute('data-civicfg-confirm-button') || 'Continue';
        var warning = form.getAttribute('data-civicfg-confirm-warning') || 'This action changes the YAML/CiviCRM sync state. Review the details before continuing.';
        modal._civicfgForm = form;
        modal.querySelector('#civicfg-confirm-title').textContent = title;
        modal.querySelector('#civicfg-confirm-message').textContent = message;
        modal.querySelector('#civicfg-confirm-warning').textContent = warning;
        var reviewed = modal.querySelector('#civicfg-confirm-reviewed');
        var text = modal.querySelector('#civicfg-confirm-text');
        var apply = modal.querySelector('[data-civicfg-confirm-apply]');
        reviewed.checked = false;
        text.value = '';
        apply.disabled = true;
        modal.querySelector('.civicfg-confirm-label').textContent = 'Type ' + word + ' to continue';
        apply.querySelector('span').textContent = buttonText;
        function refresh() { apply.disabled = !(reviewed.checked && text.value === word); }
        reviewed.onchange = refresh;
        text.oninput = refresh;
        modal.querySelectorAll('[data-civicfg-confirm-cancel]').forEach(function(btn) { btn.onclick = function() { closeConfirmModal(modal); }; });
        apply.onclick = function() {
          var target = modal._civicfgForm;
          closeConfirmModal(modal);
          if (target) {
            target.setAttribute('data-civicfg-confirmed', '1');
            var p = progressTextForForm(target);
            showProgress(p[0], p[1]);
            target.submit();
          }
        };
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('is-open');
        text.focus();
      });
    });

    document.querySelectorAll('.crm-configmanager-block form').forEach(function(form) {
      form.addEventListener('submit', function() {
        if (form.hasAttribute('data-civicfg-confirm-modal') && form.getAttribute('data-civicfg-confirmed') !== '1') {
          return;
        }
        var p = progressTextForForm(form);
        showProgress(p[0], p[1]);
        form.querySelectorAll('button[type=submit]').forEach(function(btn) { btn.disabled = true; });
      });
    });

    var exportSelect = document.getElementById('export_item');
    if (exportSelect) {
      var endpoint = exportSelect.getAttribute('data-civicfg-single-url');
      var empty = document.getElementById('civicfg-single-export-empty');
      var preview = document.getElementById('civicfg-single-export-preview');
      var error = document.getElementById('civicfg-single-export-error');
      var path = document.getElementById('civicfg-single-export-path');
      var label = document.getElementById('civicfg-single-export-label');
      var yaml = document.getElementById('civicfg-single-export-yaml');
      var download = document.getElementById('civicfg-single-export-download');

      function show(el, state) { if (el) { el.hidden = !state; } }
      function setText(el, value) { if (el) { el.textContent = value || ''; } }

      function loadSingleExport() {
        var key = exportSelect.value || '';
        show(error, false);
        if (!key) {
          show(preview, false);
          show(empty, true);
          return;
        }
        if (!endpoint) { return; }
        show(empty, false);
        show(preview, true);
        if (preview) { preview.classList.add('civicfg-loading'); }
        setText(path, 'Loading...');
        setText(label, '');
        if (yaml) { yaml.value = ''; }
        if (download) { download.removeAttribute('href'); download.setAttribute('aria-disabled', 'true'); }

        fetch(endpoint + '&export_item=' + encodeURIComponent(key), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
          .then(function(response) { return response.json(); })
          .then(function(data) {
            if (!data || !data.ok) { throw new Error((data && data.error) ? data.error : 'Could not load YAML preview.'); }
            setText(path, data.path || '');
            setText(label, data.label || '');
            if (yaml) { yaml.value = data.yaml || ''; }
            if (download && data.download_url) { download.setAttribute('href', data.download_url); download.removeAttribute('aria-disabled'); }
          })
          .catch(function(err) {
            show(preview, false);
            show(error, true);
            setText(error, err.message || 'Could not load YAML preview.');
          })
          .finally(function() {
            if (preview) { preview.classList.remove('civicfg-loading'); }
          });
      }

      exportSelect.addEventListener('change', loadSingleExport);
      if (exportSelect.value) { loadSingleExport(); }
    }
  });
})();
