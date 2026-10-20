/* Configuration Manager Settings selection and provider scope controls. */
/* Executed by configmanager.js after DOM readiness using the same shared URL/JSON helpers. */
(function() {
  window.civicfgInitScopeSettings = function(civicfgNormalizeUrl, civicfgParseJsonResponse) {
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
      var available = row.querySelector('[data-civicfg-scope-available-count]');
      if (available) {
        var total = row.getAttribute('data-civicfg-loaded-item-count');
        var missing = row.getAttribute('data-civicfg-missing-item-count') || '0';
        available.textContent = total === null
          ? 'Item count loads when Choose items is opened.'
          : total + ' available in Current CiviCRM' + (missing !== '0' ? '; ' + missing + ' saved selector(s) currently missing' : '');
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

    // Progressive enhancement: native select remains the authoritative form value.
    // Radios offer direct, keyboard-accessible mode choices without changing
    // the server's scope_mode[] POST contract. With JS disabled, select works.
    function enhanceScopeModeChoices(row) {
      var mode = row.querySelector('[data-civicfg-scope-mode]');
      if (!mode || row.querySelector('[data-civicfg-mode-options]')) { return; }
      var choices = document.createElement('fieldset');
      choices.className = 'civicfg-mode-options';
      choices.setAttribute('data-civicfg-mode-options', '1');
      var legend = document.createElement('legend');
      legend.className = 'civicfg-mode-options-legend';
      legend.textContent = 'Handling mode for ' + (row.getAttribute('data-scope-label') || 'configuration');
      choices.appendChild(legend);
      var descriptions = {
        all: 'Export, compare and safely import supported configuration.',
        selected: 'Manage only specifically chosen items.',
        watch: 'Monitor changes without exporting or importing.',
        ignore: 'Do not manage or monitor this type.'
      };
      Array.prototype.slice.call(mode.options).forEach(function(option) {
        var label = document.createElement('label');
        label.className = 'civicfg-mode-option';
        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'civicfg_ui_mode[' + (row.getAttribute('data-civicfg-scope-row') || '') + ']';
        radio.value = option.value;
        radio.disabled = !!option.disabled || !!mode.disabled;
        radio.checked = mode.value === option.value;
        radio.setAttribute('data-civicfg-mode-choice', '1');
        var title = document.createElement('strong');
        title.textContent = option.textContent;
        var detail = document.createElement('span');
        detail.className = 'civicfg-mode-option-description';
        detail.textContent = descriptions[option.value] || '';
        label.appendChild(radio);
        var content = document.createElement('span');
        content.appendChild(title);
        content.appendChild(detail);
        label.appendChild(content);
        radio.addEventListener('change', function() {
          if (!radio.checked || radio.disabled || mode.disabled) { return; }
          mode.value = radio.value;
          mode.dispatchEvent(new Event('change', {bubbles: true}));
        });
        choices.appendChild(label);
      });
      mode.parentNode.insertBefore(choices, mode.nextSibling);
      // Only hide the select after the replacement radio controls exist.
      mode.hidden = true;
      mode.setAttribute('aria-hidden', 'true');
      mode.tabIndex = -1;
    }

    function refreshScopeRow(row) {
      if (!row) { return; }
      var mode = row.querySelector('[data-civicfg-scope-mode]');
      var value = mode ? mode.value : 'all';
      row.querySelectorAll('[data-civicfg-mode-choice]').forEach(function(choice) {
        choice.checked = choice.value === value;
      });
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
      enhanceScopeModeChoices(row);
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
            '<div class="civicfg-scope-picker-selection" data-civicfg-picker-selection-count role="status" aria-live="polite"></div>' +
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
      modal.addEventListener('change', function(ev) {
        if (ev.target && ev.target.hasAttribute('data-civicfg-scope-option')) {
          updatePickerSelectionCount(modal);
        }
      });
      modal.addEventListener('keydown', function(ev) {
        if (modal.hidden) { return; }
        if (ev.key === 'Escape') {
          ev.preventDefault();
          ev.stopPropagation();
          closeScopePicker(modal);
          return;
        }
        if (ev.key !== 'Tab') { return; }
        var focusable = Array.prototype.slice.call(modal.querySelectorAll('button, input, select, textarea, a[href]'))
          .filter(function(item) { return !item.disabled && !item.closest('[hidden]') && !item.closest('.is-filtered'); });
        if (!focusable.length) { ev.preventDefault(); return; }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      });
      return modal;
    }

    function closeScopePicker(modal) {
      modal._civicfgRequest = (modal._civicfgRequest || 0) + 1;
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      modal.hidden = true;
      modal._civicfgRow = null;
      var returnFocus = modal._civicfgReturnFocus;
      modal._civicfgReturnFocus = null;
      if (returnFocus && typeof returnFocus.focus === 'function') { returnFocus.focus(); }
    }

    function updatePickerSelectionCount(modal) {
      var target = modal.querySelector('[data-civicfg-picker-selection-count]');
      if (!target) { return; }
      var selected = modal.querySelectorAll('[data-civicfg-scope-option]:checked').length;
      var total = modal.querySelectorAll('[data-civicfg-scope-option]').length;
      target.textContent = selected + ' of ' + total + ' item(s) selected for management.';
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
      updatePickerSelectionCount(modal);
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

    function openScopePicker(row, trigger) {
      var form = row.closest('form');
      var endpoint = civicfgNormalizeUrl(form ? form.getAttribute('data-civicfg-scope-options-url') : '');
      var type = row.getAttribute('data-civicfg-scope-row') || '';
      var label = row.getAttribute('data-scope-label') || type;
      var textarea = row.querySelector('[data-civicfg-scope-selectors]');
      var currentSelectors = parseScopeSelectors(textarea);
      var modal = ensureScopePickerModal();
      modal._civicfgRow = row;
      modal._civicfgReturnFocus = trigger || document.activeElement;
      modal._civicfgRequest = (modal._civicfgRequest || 0) + 1;
      var requestId = modal._civicfgRequest;
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
      pickerSearch.focus();

      fetch(endpoint + '&scope_type=' + encodeURIComponent(type), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
        .then(civicfgParseJsonResponse)
        .then(function(data) {
          if (modal.hidden || modal._civicfgRequest !== requestId) { return; }
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
          row.setAttribute('data-civicfg-loaded-item-count', String(loadedItems.filter(function(item) { return !item.missing; }).length));
          row.setAttribute('data-civicfg-missing-item-count', String(loadedItems.filter(function(item) { return !!item.missing; }).length));
          updateScopeCount(row);
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
          if (modal.hidden || modal._civicfgRequest !== requestId) { return; }
          pickerStatus.textContent = error.message || 'Could not load configuration items.';
          pickerStatus.classList.add('is-error');
          pickerSearch.disabled = true;
          pickerApply.disabled = true;
        });
    }

    document.querySelectorAll('[data-civicfg-scope-picker]').forEach(function(button) {
      button.addEventListener('click', function() {
        var row = button.closest('[data-civicfg-scope-row]');
        if (row) { openScopePicker(row, button); }
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


  };
})();
