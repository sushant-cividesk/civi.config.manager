/* Configuration Manager vanilla JavaScript. */
/*
 * Configuration Manager UI interactions.
 * Uses vanilla JavaScript only for CiviCRM 5.x/6.x compatibility.
 */
(function() {
  function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
  ready(function() {
    document.querySelectorAll('[data-civicfg-transient-summary-error]').forEach(function(message) {
      window.setTimeout(function() {
        if (message && message.parentNode) {
          message.parentNode.removeChild(message);
        }
      }, 7000);
    });

    function civicfgDisplayValue(value) {
      if (value === null || typeof value === 'undefined') { return '(not set)'; }
      if (typeof value === 'string') { return value; }
      try { return JSON.stringify(value, null, 2); }
      catch (e) { return String(value); }
    }

    function civicfgNormalizeUrl(value) {
      var url = String(value || '');
      // Some CiviCRM/CMS combinations can hand template/JSON consumers an
      // already HTML-escaped URL even when URL generation requested raw output.
      // Decode repeated ampersand escaping at the machine-consumption boundary
      // so query keys never become `amp;op`, `amp;scope_type`, etc.
      while (url.indexOf('&amp;') !== -1) {
        url = url.replace(/&amp;/g, '&');
      }
      return url;
    }

    function civicfgParseJsonResponse(response) {
      return response.text().then(function(text) {
        if (!response.ok) {
          throw new Error('Server returned HTTP ' + response.status + '.');
        }
        try {
          return JSON.parse(text);
        }
        catch (e) {
          var trimmed = (text || '').replace(/^\s+/, '');
          if (trimmed.charAt(0) === '<') {
            throw new Error('Configuration Manager expected JSON but the server returned HTML. Check the PHP/CiviCRM log and the YAML runtime status; no import action was performed.');
          }
          throw new Error('Configuration Manager received an invalid JSON response from the server. Check the PHP/CiviCRM log; no import action was performed.');
        }
      });
    }

    function populateLazyIgnoreFields(detailModal, changes) {
      if (!detailModal || !detailModal.id) { return; }
      var ignoreModal = document.getElementById(detailModal.id + '-ignore');
      if (!ignoreModal) { return; }
      var host = ignoreModal.querySelector('[data-civicfg-ignore-field-host]');
      var choice = ignoreModal.querySelector('[data-civicfg-ignore-lazy-choice]');
      if (!host || !choice) { return; }
      host.textContent = '';
      var seen = {};
      (changes || []).forEach(function(change) {
        var path = change && change.path ? String(change.path) : '';
        if (!path || seen[path]) { return; }
        seen[path] = true;
        var label = document.createElement('label');
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.name = 'value_path[]';
        box.value = path;
        label.appendChild(box);
        label.appendChild(document.createTextNode(' ' + path + ' '));
        var code = document.createElement('code');
        code.textContent = path;
        label.appendChild(code);
        host.appendChild(label);
      });
      if (host.children.length) {
        choice.hidden = false;
      }
    }

    function loadLazyDiffDetail(modal) {
      if (!modal || modal.getAttribute('data-civicfg-diff-detail') !== '1') { return; }
      if (modal.getAttribute('data-civicfg-detail-loaded') === '1' || modal.getAttribute('data-civicfg-detail-loading') === '1') { return; }
      var host = modal.querySelector('[data-civicfg-lazy-detail-host]');
      var endpoint = civicfgNormalizeUrl(modal.getAttribute('data-civicfg-detail-url') || '');
      var path = modal.getAttribute('data-civicfg-path') || '';
      if (!host || !endpoint || !path) { return; }
      modal.setAttribute('data-civicfg-detail-loading', '1');
      host.textContent = 'Loading field-level details…';
      var url = new URL(endpoint, window.location.href);
      url.searchParams.set('path', path);
      fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
        .then(civicfgParseJsonResponse)
        .then(function(payload) {
          if (!payload || payload.ok !== true) {
            throw new Error(payload && payload.error ? payload.error : 'Could not load diff details.');
          }
          host.textContent = '';
          var file = payload.file || null;
          if (!file) {
            var note = document.createElement('p');
            note.className = 'description';
            note.textContent = payload.renamed && payload.renamed.length
              ? 'Only the portable YAML filename changed for this configuration item.'
              : 'No field-level differences remain for this item.';
            host.appendChild(note);
            populateLazyIgnoreFields(modal, []);
            modal.setAttribute('data-civicfg-detail-loaded', '1');
            return;
          }
          var changes = Array.isArray(file.changes) ? file.changes : [];
          if (changes.length) {
            var table = document.createElement('table');
            table.className = 'civicfg-diff-table';
            var thead = document.createElement('thead');
            var headerRow = document.createElement('tr');
            ['Field', 'YAML File', 'Active CiviCRM'].forEach(function(title) {
              var th = document.createElement('th'); th.textContent = title; headerRow.appendChild(th);
            });
            thead.appendChild(headerRow); table.appendChild(thead);
            var tbody = document.createElement('tbody');
            changes.forEach(function(change) {
              var tr = document.createElement('tr');
              var fieldCell = document.createElement('td');
              var strong = document.createElement('strong');
              strong.textContent = change.path || 'value';
              fieldCell.appendChild(strong);
              var oldCell = document.createElement('td'); oldCell.className = 'civicfg-diff-old';
              var oldPre = document.createElement('pre'); oldPre.className = 'civicfg-diff-value'; oldPre.textContent = civicfgDisplayValue(change.old); oldCell.appendChild(oldPre);
              var newCell = document.createElement('td'); newCell.className = 'civicfg-diff-new';
              var newPre = document.createElement('pre'); newPre.className = 'civicfg-diff-value'; newPre.textContent = civicfgDisplayValue(change.new); newCell.appendChild(newPre);
              tr.appendChild(fieldCell); tr.appendChild(oldCell); tr.appendChild(newCell); tbody.appendChild(tr);
            });
            table.appendChild(tbody); host.appendChild(table);
          }
          else {
            var noChanges = document.createElement('p');
            noChanges.className = 'description';
            noChanges.textContent = 'No field-level differences were returned.';
            host.appendChild(noChanges);
          }
          var details = document.createElement('details');
          var summary = document.createElement('summary'); summary.textContent = 'Show Diff Text'; details.appendChild(summary);
          var pre = document.createElement('pre'); pre.className = 'civicfg-diff'; pre.textContent = file.diff || ''; details.appendChild(pre); host.appendChild(details);
          populateLazyIgnoreFields(modal, changes);
          modal.setAttribute('data-civicfg-detail-loaded', '1');
        })
        .catch(function(error) {
          host.textContent = '';
          var message = document.createElement('div');
          message.className = 'messages error no-popup';
          message.textContent = error && error.message ? error.message : 'Could not load field-level details.';
          host.appendChild(message);
        })
        .then(function() {
          modal.removeAttribute('data-civicfg-detail-loading');
        });
    }

    document.querySelectorAll('.crm-configmanager-block [data-civicfg-open]').forEach(function(btn) {
      btn.addEventListener('click', function(ev) {
        ev.preventDefault();
        var modal = document.getElementById(btn.getAttribute('data-civicfg-open'));
        if (modal) {
          modal.hidden = false;
          modal.setAttribute('aria-hidden', 'false');
          modal.classList.add('is-open');
          loadLazyDiffDetail(modal);
          if (modal.id && /-ignore$/.test(modal.id)) {
            var detailModal = document.getElementById(modal.id.replace(/-ignore$/, ''));
            loadLazyDiffDetail(detailModal);
          }
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

    function scopeRowIsManaged(row) {
      if (!row) { return false; }
      var mode = row.querySelector('[data-civicfg-scope-mode]');
      var value = mode ? mode.value : 'ignore';
      if (value === 'all') { return true; }
      if (value !== 'selected') { return false; }
      return parseScopeSelectors(row.querySelector('[data-civicfg-scope-selectors]')).length > 0;
    }

    function scopeDependencyTypes(row) {
      var raw = row ? (row.getAttribute('data-civicfg-scope-dependencies') || '') : '';
      return raw.split(',').map(function(value) { return value.trim(); }).filter(function(value) { return value !== ''; });
    }

    function refreshScopeDependencies() {
      var rows = Array.prototype.slice.call(document.querySelectorAll('[data-civicfg-scope-row]'));
      if (!rows.length) { return; }
      var rowByType = {};
      rows.forEach(function(row) {
        rowByType[row.getAttribute('data-civicfg-scope-row') || ''] = row;
        row.classList.remove('has-dependency-warning', 'has-dependency-review');
        var cardWarning = row.querySelector('[data-civicfg-scope-card-dependency-warning]');
        if (cardWarning) { cardWarning.hidden = true; cardWarning.textContent = ''; }
        var manageButton = row.querySelector('[data-civicfg-manage-dependencies]');
        if (manageButton) { manageButton.hidden = true; manageButton.removeAttribute('data-civicfg-fix-dependencies'); }
      });

      var messages = [];
      rows.forEach(function(row) {
        if (!scopeRowIsManaged(row)) { return; }
        var sourceLabel = row.getAttribute('data-scope-label') || row.getAttribute('data-civicfg-scope-row') || 'Configuration';
        var sourceMessages = [];
        var fixable = [];
        var hasReview = false;

        scopeDependencyTypes(row).forEach(function(dependencyType) {
          var dependencyRow = rowByType[dependencyType];
          if (!dependencyRow) { return; }
          var dependencyLabel = dependencyRow.getAttribute('data-scope-label') || dependencyType;
          var capability = dependencyRow.getAttribute('data-scope-capability') || '';
          var dependencyModeNode = dependencyRow.querySelector('[data-civicfg-scope-mode]');
          var dependencyMode = dependencyModeNode ? dependencyModeNode.value : 'ignore';
          var message = '';
          var level = 'warning';

          if (capability === 'unavailable') {
            message = sourceLabel + ' can reference ' + dependencyLabel + ', but ' + dependencyLabel + ' is unavailable on this site.';
            level = 'warning';
          }
          else if (dependencyMode === 'ignore') {
            message = sourceLabel + ' can reference ' + dependencyLabel + ', but ' + dependencyLabel + ' is ignored and will not be deployed.';
            fixable.push(dependencyType);
          }
          else if (dependencyMode === 'watch') {
            message = sourceLabel + ' can reference ' + dependencyLabel + ', but ' + dependencyLabel + ' is monitor-only and will not be imported.';
            fixable.push(dependencyType);
          }
          else if (dependencyMode === 'selected') {
            var dependencySelectors = parseScopeSelectors(dependencyRow.querySelector('[data-civicfg-scope-selectors]'));
            if (dependencySelectors.length === 0) {
              message = dependencyLabel + ' uses selected-item scope but no items are selected, so referenced dependencies will not be deployed.';
            }
            else {
              message = dependencyLabel + ' uses selected-item scope. Verify every item referenced by ' + sourceLabel + ' is selected.';
              level = 'review';
              hasReview = true;
            }
          }

          if (message !== '') {
            sourceMessages.push(message);
            messages.push({message: message, level: level});
          }
        });

        if (sourceMessages.length) {
          row.classList.add(fixable.length ? 'has-dependency-warning' : 'has-dependency-review');
          var warningHost = row.querySelector('[data-civicfg-scope-card-dependency-warning]');
          if (warningHost) {
            warningHost.hidden = false;
            warningHost.textContent = sourceMessages.join(' ');
          }
          var button = row.querySelector('[data-civicfg-manage-dependencies]');
          if (button && fixable.length) {
            button.hidden = false;
            button.setAttribute('data-civicfg-fix-dependencies', fixable.join(','));
          }
          if (hasReview && !fixable.length) { row.classList.add('has-dependency-review'); }
        }
      });

      var summary = document.querySelector('[data-civicfg-scope-dependency-summary]');
      var heading = document.querySelector('[data-civicfg-scope-dependency-heading]');
      var list = document.querySelector('[data-civicfg-scope-dependency-list]');
      if (!summary || !heading || !list) { return; }
      list.innerHTML = '';
      messages.forEach(function(item) {
        var li = document.createElement('li');
        li.textContent = item.message;
        if (item.level === 'review') { li.className = 'civicfg-dependency-review'; }
        list.appendChild(li);
      });
      summary.hidden = messages.length === 0;
      heading.textContent = messages.length ? (messages.length + ' scope dependency item(s) need review.') : '';
    }

    var scopeSettingsForm = document.querySelector('[data-civicfg-settings-form]');
    var scopeUnsaved = document.querySelector('[data-civicfg-scope-unsaved]');
    var scopeDirty = false;

    function markScopeDirty() {
      scopeDirty = true;
      if (scopeUnsaved) { scopeUnsaved.hidden = false; }
    }

    if (scopeSettingsForm) {
      scopeSettingsForm.addEventListener('submit', function() {
        scopeDirty = false;
        if (scopeUnsaved) { scopeUnsaved.hidden = true; }
      });
      document.querySelectorAll('.civicfg-tab').forEach(function(tab) {
        tab.addEventListener('click', function(event) {
          if (!scopeDirty || tab.classList.contains('active')) { return; }
          if (!window.confirm('You have unsaved Configuration Manager scope changes. Leave Settings without saving them?')) {
            event.preventDefault();
          }
        });
      });
    }

    function refreshScopeBulkControls() {
      var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-civicfg-scope-select]'));
      var enabled = boxes.filter(function(box) { return !box.disabled; });
      var checked = enabled.filter(function(box) { return box.checked; });
      var all = document.querySelector('[data-civicfg-scope-select-all]');
      var count = document.querySelector('[data-civicfg-scope-selected-count]');
      var apply = document.querySelector('[data-civicfg-scope-bulk-apply]');
      var mode = document.querySelector('[data-civicfg-scope-bulk-mode]');
      if (count) { count.textContent = checked.length + ' selected'; }
      if (all) {
        all.checked = enabled.length > 0 && checked.length === enabled.length;
        all.indeterminate = checked.length > 0 && checked.length < enabled.length;
      }
      if (apply) { apply.disabled = checked.length === 0 || !mode || !mode.value; }
    }

    var scopeSelectAll = document.querySelector('[data-civicfg-scope-select-all]');
    if (scopeSelectAll) {
      scopeSelectAll.addEventListener('change', function() {
        document.querySelectorAll('[data-civicfg-scope-select]').forEach(function(box) {
          if (!box.disabled) { box.checked = scopeSelectAll.checked; }
        });
        refreshScopeBulkControls();
      });
    }
    document.querySelectorAll('[data-civicfg-scope-select]').forEach(function(box) {
      box.addEventListener('change', refreshScopeBulkControls);
    });
    var scopeBulkMode = document.querySelector('[data-civicfg-scope-bulk-mode]');
    if (scopeBulkMode) { scopeBulkMode.addEventListener('change', refreshScopeBulkControls); }
    var scopeBulkApply = document.querySelector('[data-civicfg-scope-bulk-apply]');
    if (scopeBulkApply) {
      scopeBulkApply.addEventListener('click', function() {
        if (!scopeBulkMode || !scopeBulkMode.value) { return; }
        document.querySelectorAll('[data-civicfg-scope-select]:checked').forEach(function(box) {
          var row = box.closest('[data-civicfg-scope-row]');
          var mode = row ? row.querySelector('[data-civicfg-scope-mode]') : null;
          if (!mode || mode.disabled) { return; }
          mode.value = scopeBulkMode.value;
          mode.dispatchEvent(new Event('change', {bubbles: true}));
        });
        refreshScopeBulkControls();
      });
    }
    refreshScopeBulkControls();

    document.querySelectorAll('[data-civicfg-scope-row]').forEach(function(row) {
      refreshScopeRow(row);
      var mode = row.querySelector('[data-civicfg-scope-mode]');
      if (mode) {
        mode.addEventListener('change', function() { refreshScopeRow(row); renderScopeSettingsExample(); refreshScopeDependencies(); markScopeDirty(); });
      }
      var textarea = row.querySelector('[data-civicfg-scope-selectors]');
      if (textarea) {
        textarea.addEventListener('input', function() { updateScopeCount(row); renderScopeSettingsExample(); refreshScopeDependencies(); markScopeDirty(); });
      }
      var watch = row.querySelector('input[name^="scope_watch_unmanaged"]');
      if (watch) {
        watch.addEventListener('change', function() { renderScopeSettingsExample(); markScopeDirty(); });
      }
      var manageDependencies = row.querySelector('[data-civicfg-manage-dependencies]');
      if (manageDependencies) {
        manageDependencies.addEventListener('click', function() {
          var types = (manageDependencies.getAttribute('data-civicfg-fix-dependencies') || '').split(',').filter(function(value) { return value !== ''; });
          types.forEach(function(type) {
            var dependencyRow = document.querySelector('[data-civicfg-scope-row="' + type + '"]');
            var dependencyMode = dependencyRow ? dependencyRow.querySelector('[data-civicfg-scope-mode]') : null;
            if (!dependencyMode || dependencyMode.disabled || dependencyRow.getAttribute('data-scope-capability') === 'unavailable') { return; }
            if (dependencyMode.value === 'ignore' || dependencyMode.value === 'watch') {
              dependencyMode.value = 'all';
              dependencyMode.dispatchEvent(new Event('change', {bubbles: true}));
            }
          });
          refreshScopeDependencies();
        });
      }
    });
    refreshScopeDependencies();

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

    function renderScopePickerItems(modal, items, currentSelectors, autoSelectRecommended) {
      var list = modal.querySelector('#civicfg-scope-picker-list');
      var status = modal.querySelector('#civicfg-scope-picker-status');
      list.innerHTML = '';
      var hasCurrentSelectors = currentSelectors.length > 0;
      var current = {};
      currentSelectors.forEach(function(selector) { current[selector] = true; });

      items.forEach(function(item, index) {
        var option = document.createElement('label');
        option.className = 'civicfg-scope-picker-item' + (item.missing ? ' is-missing' : '') + (!item.write_safe && !item.missing ? ' is-readonly' : '') + (item.recommended ? ' is-recommended' : '') + (item.reference ? ' is-reference' : '');
        option.setAttribute('data-search', ((item.label || '') + ' ' + (item.path || '') + ' ' + (item.source_id || '') + ' ' + (item.recommendation || '')).toLowerCase());
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = item.selector || '';
        checkbox.setAttribute('data-civicfg-scope-option', '1');
        checkbox.checked = !!current[checkbox.value] || (hasCurrentSelectors && !!item.selected) || (!hasCurrentSelectors && autoSelectRecommended && !!item.recommended);
        var body = document.createElement('span');
        body.className = 'civicfg-scope-picker-item-body';
        var titleLine = document.createElement('span');
        titleLine.className = 'civicfg-scope-picker-title-line';
        var title = document.createElement('strong');
        title.textContent = item.label || item.path || ('Item ' + (index + 1));
        titleLine.appendChild(title);
        if (item.recommended) {
          var recommendedBadge = document.createElement('span');
          recommendedBadge.className = 'civicfg-badge good civicfg-scope-picker-badge';
          recommendedBadge.textContent = 'Customized in CiviCRM';
          titleLine.appendChild(recommendedBadge);
        }
        else if (item.reference) {
          var referenceBadge = document.createElement('span');
          referenceBadge.className = 'civicfg-badge civicfg-scope-picker-badge';
          referenceBadge.textContent = 'System reference';
          titleLine.appendChild(referenceBadge);
        }
        var meta = document.createElement('span');
        meta.className = 'civicfg-muted civicfg-scope-picker-meta';
        var parts = [];
        if (item.path) { parts.push(item.path); }
        if (item.source_id) { parts.push('Local ID ' + item.source_id); }
        if (item.recommendation) { parts.push(item.recommendation); }
        if (item.missing) { parts.push('Currently missing'); }
        else if (!item.write_safe) { parts.push('Backup/monitor only: automatic writes blocked'); }
        meta.textContent = parts.join(' • ');
        body.appendChild(titleLine);
        if (parts.length) { body.appendChild(meta); }
        option.appendChild(checkbox);
        option.appendChild(body);
        list.appendChild(option);
      });
      var recommendedCount = items.filter(function(item) { return !!item.recommended; }).length;
      var referenceCount = items.filter(function(item) { return !!item.reference; }).length;
      if (!items.length) {
        status.textContent = 'No selectable items were found for this configuration type.';
      }
      else if (recommendedCount > 0 && autoSelectRecommended) {
        status.textContent = recommendedCount + ' customized workflow template(s) are shown first and pre-selected because CiviCRM currently shows Revert for them. ' + referenceCount + ' system reference template(s) are kept at the end for advanced use.';
      }
      else if (recommendedCount > 0) {
        status.textContent = recommendedCount + ' customized workflow template(s) are shown first because CiviCRM currently shows Revert for them. Existing saved selections are kept; check or uncheck items explicitly before saving. ' + referenceCount + ' system reference template(s) are kept at the end for advanced use.';
      }
      else {
        status.textContent = items.length + ` item(s) available. No customized workflow template currently matches CiviCRM's Revert condition.`;
      }
      status.setAttribute('data-civicfg-total-items', String(items.length));
      status.setAttribute('data-civicfg-base-status', status.textContent);
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
        status.textContent = status.getAttribute('data-civicfg-base-status') || (items.length + ' item(s) available');
      }
    }

    function openScopePicker(row) {
      var form = row.closest('form');
      var endpoint = civicfgNormalizeUrl(form ? form.getAttribute('data-civicfg-scope-options-url') : '');
      var type = row.getAttribute('data-civicfg-scope-row') || '';
      var label = row.getAttribute('data-scope-label') || type;
      var textarea = row.querySelector('[data-civicfg-scope-selectors]');
      var currentSelectors = parseScopeSelectors(textarea);
      var modal = ensureScopePickerModal();
      modal._civicfgRow = row;
      modal.querySelector('#civicfg-scope-picker-title').textContent = 'Choose ' + label;
      var pickerSearch = modal.querySelector('#civicfg-scope-picker-search');
      var pickerStatus = modal.querySelector('#civicfg-scope-picker-status');
      var pickerApply = modal.querySelector('[data-civicfg-scope-picker-apply]');
      pickerSearch.value = '';
      pickerSearch.disabled = false;
      pickerApply.disabled = false;
      modal.querySelector('#civicfg-scope-picker-list').innerHTML = '';
      pickerStatus.classList.remove('is-error', 'is-warning', 'is-success');
      pickerStatus.textContent = 'Loading current CiviCRM items...';
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');

      fetch(endpoint + '&scope_type=' + encodeURIComponent(type), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
        .then(civicfgParseJsonResponse)
        .then(function(data) {
          if (!data || !data.ok) { throw new Error((data && data.error) ? data.error : 'Could not load configuration items.'); }
          if (data.available === false) {
            pickerStatus.textContent = data.unavailable_reason || 'This configuration provider is unavailable on this site.';
            pickerStatus.classList.add('is-error');
            pickerSearch.disabled = true;
            pickerApply.disabled = true;
            modal.querySelector('#civicfg-scope-picker-list').innerHTML = '';
            return;
          }
          var loadedItems = data.items || [];
          var savedPolicy = data.policy || {};
          var autoSelectRecommended = currentSelectors.length === 0 && String(savedPolicy.mode || '') !== 'selected';
          renderScopePickerItems(modal, loadedItems, currentSelectors, autoSelectRecommended);
          var current = {};
          currentSelectors.forEach(function(selector) { current[selector] = true; });
          var hasCurrent = currentSelectors.length > 0;
          renderScopeChips(row, loadedItems.filter(function(item) {
            return !!current[item.selector || ''] || (hasCurrent && !!item.selected);
          }));
        })
        .catch(function(error) {
          pickerStatus.textContent = error.message || 'Could not load configuration items.';
          pickerStatus.classList.add('is-error');
          pickerSearch.disabled = true;
          pickerApply.disabled = true;
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
      var existing = document.getElementById('civicfg-progress-overlay');
      if (existing) {
        existing.querySelector('.civicfg-progress-title').textContent = title || 'Working...';
        existing.querySelector('.civicfg-progress-text').textContent = text || 'Progress is saved on the server while this operation runs.';
        return existing;
      }
      host.classList.add('is-busy');
      var overlay = document.createElement('div');
      overlay.id = 'civicfg-progress-overlay';
      overlay.className = 'civicfg-progress-overlay';
      overlay.innerHTML = '' +
        '<div class="civicfg-progress-box" role="alert" aria-live="assertive">' +
          '<div class="civicfg-progress-title"></div>' +
          '<div class="civicfg-progress-text"></div>' +
          '<div class="civicfg-progress-bar" role="progressbar"><span class="civicfg-progress-fill"></span></div>' +
          '<div class="civicfg-progress-meta"><span class="civicfg-progress-percent">Running</span><span class="civicfg-progress-step"></span><span class="civicfg-progress-items"></span></div>' +
          '<div class="civicfg-progress-heartbeat"></div>' +
        '</div>';
      overlay.querySelector('.civicfg-progress-title').textContent = title || 'Working...';
      overlay.querySelector('.civicfg-progress-text').textContent = text || 'Progress is saved on the server while this operation runs.';
      host.appendChild(overlay);
      return overlay;
    }

    function heartbeatAgeText(value) {
      if (!value) { return ''; }
      var normalized = String(value).replace(' ', 'T');
      if (!/[zZ]|[+-]\d\d:?\d\d$/.test(normalized)) {
        normalized += 'Z';
      }
      var timestamp = Date.parse(normalized);
      if (!isFinite(timestamp)) { return ''; }
      var seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
      if (seconds < 2) { return 'Server update: just now'; }
      if (seconds < 60) { return 'Server update: ' + seconds + 's ago'; }
      var minutes = Math.floor(seconds / 60);
      return 'Server update: ' + minutes + 'm ago';
    }

    function updateProgress(event) {
      var overlay = showProgress(event.label || 'Working...', event.message || 'Processing saved Configuration Manager work.');
      var known = event.progress_known === true || event.progress_known === 1 || event.progress_known === '1';
      var percent = Math.max(0, Math.min(100, parseInt(event.percent || 0, 10)));
      var bar = overlay.querySelector('.civicfg-progress-bar');
      var fill = overlay.querySelector('.civicfg-progress-fill');
      var percentNode = overlay.querySelector('.civicfg-progress-percent');
      var stepNode = overlay.querySelector('.civicfg-progress-step');
      var itemsNode = overlay.querySelector('.civicfg-progress-items');
      var heartbeatNode = overlay.querySelector('.civicfg-progress-heartbeat');

      if (known) {
        overlay.classList.remove('civicfg-progress-unknown');
        if (fill) { fill.style.width = percent + '%'; }
        if (bar) {
          bar.setAttribute('aria-valuemin', '0');
          bar.setAttribute('aria-valuemax', '100');
          bar.setAttribute('aria-valuenow', String(percent));
          bar.removeAttribute('aria-valuetext');
        }
        if (percentNode) { percentNode.textContent = percent + '%'; }
      }
      else {
        overlay.classList.add('civicfg-progress-unknown');
        if (fill) { fill.style.width = '0'; }
        if (bar) {
          bar.removeAttribute('aria-valuemin');
          bar.removeAttribute('aria-valuemax');
          bar.removeAttribute('aria-valuenow');
          bar.setAttribute('aria-valuetext', 'Progress total is not known yet');
        }
        if (percentNode) { percentNode.textContent = event.status === 'queued' ? 'Queued' : 'Running'; }
      }

      if (stepNode) {
        var phaseIndex = parseInt(event.phase_index || 0, 10);
        var phaseTotal = parseInt(event.phase_total || 0, 10);
        var itemCompleted = parseInt(event.item_completed || 0, 10);
        var itemTotal = parseInt(event.item_total || 0, 10);
        if (phaseTotal > 0 && phaseIndex > 0) {
          stepNode.textContent = 'Phase ' + phaseIndex + ' of ' + phaseTotal;
        }
        else if (itemTotal > 0) {
          stepNode.textContent = itemCompleted + ' / ' + itemTotal + ' in this work unit';
        }
        else {
          stepNode.textContent = '';
        }
      }
      if (itemsNode) {
        var items = parseInt(event.processed_items || 0, 10);
        itemsNode.textContent = items > 0 ? ('Processed: ' + items + ' record' + (items === 1 ? '' : 's')) : '';
      }
      if (heartbeatNode) {
        var heartbeat = heartbeatAgeText(event.heartbeat_at || '');
        var reconnect = event.terminal ? '' : ' Job state is saved; refreshing this page will reconnect.';
        heartbeatNode.textContent = heartbeat + (heartbeat && reconnect ? ' · ' : '') + reconnect;
      }
    }

    function isStreamedOperationForm(form) {
      var action = form ? form.querySelector('input[name="_action"]') : null;
      var value = action ? action.value : '';
      return value === 'export_write' || value === 'import_apply';
    }

    function runStreamedOperation(form) {
      if (!form || form.getAttribute('data-civicfg-stream-running') === '1') { return; }
      form.setAttribute('data-civicfg-stream-running', '1');
      form.removeAttribute('data-civicfg-confirmed');
      var p = progressTextForForm(form);
      updateProgress({label: p[0], message: 'Creating a persistent Configuration Manager job. No changes have been made yet.', progress_known: false, status: 'queued', processed_items: 0});
      form.querySelectorAll('button[type=submit]').forEach(function(btn) { btn.disabled = true; });

      var startUrl = new URL(form.action || window.location.href, window.location.href);
      startUrl.searchParams.set('op', 'operation-start-json');
      var formData = new FormData(form);
      var csrf = String(formData.get('civicfg_csrf') || '');
      var pollTimer = null;
      var stopped = false;
      var activeJobId = 0;
      var links = null;

      function fetchJson(url, options) {
        return fetch(civicfgNormalizeUrl(url), options || {credentials: 'same-origin'}).then(civicfgParseJsonResponse).then(function(payload) {
          if (!payload || payload.ok === false) {
            throw new Error(payload && payload.error ? payload.error : 'Configuration operation request failed.');
          }
          return payload;
        });
      }

      function updateFromJob(job) {
        if (!job) { return; }
        var status = String(job.status || 'queued');
        var label = String(job.current || '').trim();
        if (!label) {
          label = status === 'queued' ? (String(job.operation || 'Operation') + ' queued') : (status === 'running' ? 'Processing Configuration Manager work' : 'Configuration operation');
        }
        var message = String(job.message || '').trim();
        if (!message) {
          message = status === 'queued'
            ? 'Waiting for the first bounded worker unit. No active CiviCRM configuration or live YAML has been changed.'
            : 'The server is processing the named work unit. Saved job state will be used if this page is refreshed.';
        }
        updateProgress({
          label: label,
          message: message,
          status: status,
          progress_known: !!job.progress_known,
          percent: parseInt(job.percent || 0, 10),
          phase_index: parseInt(job.phase_index || 0, 10),
          phase_total: parseInt(job.phase_total || 0, 10),
          item_completed: parseInt(job.item_completed || 0, 10),
          item_total: parseInt(job.item_total || 0, 10),
          processed_items: parseInt(job.processed_items || 0, 10),
          heartbeat_at: job.heartbeat_at || ''
        });
      }

      function isTerminal(job) {
        var status = String(job && job.status || '');
        return status === 'complete' || status === 'failed' || status === 'blocked';
      }

      function stopPolling() {
        if (pollTimer) {
          window.clearTimeout(pollTimer);
          pollTimer = null;
        }
      }

      function finishFromStatus(payload) {
        if (stopped) { return; }
        stopped = true;
        stopPolling();
        var job = payload && payload.job ? payload.job : {};
        var ok = String(job.status || '') === 'complete';
        updateProgress({
          label: ok ? 'Complete' : 'Stopped safely',
          message: payload.terminal_message || job.error || (ok ? 'Configuration operation finished.' : 'Configuration operation stopped.'),
          status: String(job.status || ''),
          terminal: true,
          progress_known: ok || !!job.progress_known,
          percent: ok ? 100 : parseInt(job.percent || 0, 10),
          phase_index: parseInt(job.phase_index || 0, 10),
          phase_total: parseInt(job.phase_total || 0, 10),
          item_completed: parseInt(job.item_completed || 0, 10),
          item_total: parseInt(job.item_total || 0, 10),
          processed_items: parseInt(job.processed_items || 0, 10),
          heartbeat_at: job.heartbeat_at || ''
        });
        window.setTimeout(function() {
          window.location.href = (links && links.redirect_url) || payload.redirect_url || window.location.href;
        }, 500);
      }

      function pollStatus(delay) {
        if (stopped || !links || !links.status_url) { return; }
        stopPolling();
        pollTimer = window.setTimeout(function() {
          fetchJson(links.status_url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(function(payload) {
            if (payload.status_url || payload.step_url || payload.redirect_url) {
              links = payload;
            }
            updateFromJob(payload.job);
            if (isTerminal(payload.job)) {
              finishFromStatus(payload);
              return;
            }
            pollStatus(750);
          }).catch(function() {
            // A transient polling failure is not an operation failure. The job
            // is persistent, so keep trying while the worker request runs.
            pollStatus(1500);
          });
        }, Math.max(0, delay || 0));
      }

      function refreshTerminalStatus() {
        if (!links || !links.status_url) { return; }
        fetchJson(links.status_url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(function(payload) {
          updateFromJob(payload.job);
          if (isTerminal(payload.job)) {
            finishFromStatus(payload);
          }
        }).catch(function() {
          pollStatus(1000);
        });
      }

      function advanceQueue() {
        if (stopped || !links || !links.step_url || !activeJobId) { return; }
        var stepBody = new FormData();
        stepBody.set('_action', 'operation_step');
        stepBody.set('civicfg_csrf', csrf);
        stepBody.set('job_id', String(activeJobId));
        fetchJson(links.step_url, {
          method: 'POST',
          body: stepBody,
          credentials: 'same-origin',
          headers: {'Accept': 'application/json'}
        }).then(function(payload) {
          updateFromJob(payload.job);
          if (isTerminal(payload.job)) {
            refreshTerminalStatus();
            return;
          }
          // Future queue plans may contain several bounded items. Advance only
          // after the previous runNext() request returned; the server-side
          // worker lock additionally prevents overlapping consumers.
          window.setTimeout(advanceQueue, payload.job && payload.job.worker_busy ? 1000 : 100);
        }).catch(function(error) {
          // A proxy timeout does not mean PHP failed. Keep polling the durable
          // job, and retry advancement later; the worker lock prevents overlap.
          updateProgress({
            label: 'Checking saved job',
            message: 'The worker connection was interrupted. Server-side job state is still being checked; this does not restart the operation.',
            progress_known: false,
            status: 'running',
            processed_items: 0
          });
          window.setTimeout(advanceQueue, 2000);
        });
      }

      fetchJson(startUrl.toString(), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
      }).then(function(payload) {
        if (!payload.job || !payload.job.id) {
          throw new Error('The server did not return a persistent Configuration Manager job ID.');
        }
        activeJobId = parseInt(payload.job.id, 10);
        links = payload;
        updateFromJob(payload.job);
        if (isTerminal(payload.job)) {
          refreshTerminalStatus();
          return;
        }
        pollStatus(250);
        advanceQueue();
      }).catch(function(error) {
        stopped = true;
        stopPolling();
        var overlay = showProgress('Operation failed to start', error && error.message ? error.message : 'Configuration operation failed to start.');
        var percentNode = overlay.querySelector('.civicfg-progress-percent');
        if (percentNode) { percentNode.textContent = 'Failed'; }
        form.removeAttribute('data-civicfg-stream-running');
        form.querySelectorAll('button[type=submit]').forEach(function(btn) { btn.disabled = false; });
      });
    }

    function progressTextForForm(form) {
      var action = form.querySelector('input[name="_action"]');
      var value = action ? action.value : '';
      if (value.indexOf('import') === 0) { return ['Preparing managed import', 'Creating a durable import job. Full preflight will finish before any active CiviCRM write.']; }
      if (value.indexOf('export') === 0) { return ['Preparing managed export', 'Creating a durable staged-export job. Live YAML will not change until safety verification succeeds.']; }
      if (value === 'validate_files') { return ['Validating configuration', 'Checking YAML files and dependency metadata.']; }
      if (value === 'revert_file') { return ['Reverting active CiviCRM', 'Applying the selected YAML file and dependencies back to CiviCRM.']; }
      if (value === 'ignore_config') { return ['Saving ignore rule', 'Updating Config Ignore settings.']; }
      if (value === 'save_settings') { return ['Saving settings', 'Updating Configuration Manager settings.']; }
      return ['Working', 'Progress is saved on the server while this operation runs.'];
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
            if (target.requestSubmit) {
              target.requestSubmit();
            }
            else if (isStreamedOperationForm(target)) {
              runStreamedOperation(target);
            }
            else {
              target.submit();
            }
          }
        };
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('is-open');
        text.focus();
      });
    });

    document.querySelectorAll('.crm-configmanager-block form').forEach(function(form) {
      form.addEventListener('submit', function(ev) {
        if (form.hasAttribute('data-civicfg-confirm-modal') && form.getAttribute('data-civicfg-confirmed') !== '1') {
          return;
        }
        if (isStreamedOperationForm(form)) {
          ev.preventDefault();
          runStreamedOperation(form);
          return;
        }
        var p = progressTextForForm(form);
        showProgress(p[0], p[1]);
        form.querySelectorAll('button[type=submit]').forEach(function(btn) { btn.disabled = true; });
      });
    });

    var exportSelect = document.getElementById('export_item');
    if (exportSelect) {
      var endpoint = civicfgNormalizeUrl(exportSelect.getAttribute('data-civicfg-single-url'));
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
          .then(civicfgParseJsonResponse)
          .then(function(data) {
            if (!data || !data.ok) { throw new Error((data && data.error) ? data.error : 'Could not load YAML preview.'); }
            setText(path, data.path || '');
            setText(label, data.label || '');
            if (yaml) { yaml.value = data.yaml || ''; }
            if (download && data.download_url) { download.setAttribute('href', civicfgNormalizeUrl(data.download_url)); download.removeAttribute('aria-disabled'); }
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
