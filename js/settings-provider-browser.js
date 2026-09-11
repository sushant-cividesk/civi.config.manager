(function(root, factory) {
  var api = factory();
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }
  if (root && root.document) {
    root.CiviCfgProviderBrowser = api;
    if (root.document.readyState === 'loading') {
      root.document.addEventListener('DOMContentLoaded', function() { api.init(root.document); });
    }
    else {
      api.init(root.document);
    }
  }
})(typeof window !== 'undefined' ? window : null, function() {
  'use strict';

  function normalize(value) {
    return String(value || '').trim().toLowerCase();
  }

  function classifyProvider(provider) {
    provider = provider || {};
    var capability = normalize(provider.capability);
    if (capability === 'unavailable' || provider.admitted === false) {
      return 'unavailable';
    }
    if (capability === 'export_only') {
      return 'limited';
    }

    var source = normalize(provider.registration_source);
    var owner = normalize(provider.owner);
    if (source === 'core_handler') {
      return 'core';
    }
    if (owner && owner !== 'hook-provider' && owner !== 'civi.config.manager') {
      return 'contributed';
    }
    return 'custom';
  }

  function matchesSearch(provider, query) {
    query = normalize(query);
    if (!query) { return true; }
    provider = provider || {};
    var haystack = [
      provider.label,
      provider.type,
      provider.owner,
      provider.registration_source,
      provider.capability,
      provider.capability_reason,
      provider.capability_reason_code
    ].map(normalize).join(' ');
    return haystack.indexOf(query) !== -1;
  }

  function isRejectedProvider(provider) {
    return normalize(provider && provider.provider_key).indexOf('rejected:') === 0;
  }

  function classifyDetectedProvider(provider) {
    provider = provider || {};
    var capability = normalize(provider.capability);
    var reasonCode = normalize(provider.capability_reason_code);

    if (capability === 'unavailable') {
      return 'unavailable';
    }
    if (reasonCode === 'dedicated_or_excluded_extension') {
      return 'not_separate';
    }
    if (capability === 'review_only' || capability === 'monitor_only') {
      return 'review_only';
    }
    if (capability === 'export_only') {
      return 'export_only';
    }
    if (provider.admitted === true && capability === 'managed_no_delete') {
      return 'create_update';
    }
    if (provider.admitted === true && capability === 'full') {
      return 'managed';
    }
    return 'unsupported';
  }

  var detectedProviderGroups = {
    managed: {label: 'Managed through Extensions', help: 'Portable providers which passed the current safety checks and are controlled by the Extensions configuration type.'},
    create_update: {label: 'Create + update through Extensions', help: 'Portable providers which can be created and updated through Extensions while automatic removal remains disabled.'},
    export_only: {label: 'Export + compare', help: 'Readable providers which can be saved and compared, but are not approved for automatic restore/import.'},
    review_only: {label: 'Review only', help: 'Providers which remain visible for review while automatic write and remove actions stay blocked.'},
    not_separate: {label: 'Not offered separately', help: 'Providers handled by a dedicated configuration type or intentionally excluded from generic management.'},
    unsupported: {label: 'Cannot be managed automatically', help: 'Detected providers which did not prove the portability or safety required for automatic management.'},
    unavailable: {label: 'Unavailable', help: 'Providers which are registered or known but cannot currently be used on this site.'}
  };

  function detectedProviderGroupMeta(status) {
    return detectedProviderGroups[status] || {label: 'Needs review', help: 'Provider metadata needs review.'};
  }

  function detectedProviderStatusLabel(provider) {
    return detectedProviderGroupMeta(classifyDetectedProvider(provider)).label;
  }

  function detectedProviderReason(provider) {
    provider = provider || {};
    var reasonCode = normalize(provider.capability_reason_code);
    var reasons = {
      dedicated_or_excluded_extension: 'This provider is not offered as a separate configuration type because it is handled by a dedicated type or intentionally excluded from generic management.',
      business_data_marker: 'This provider looks like business or transactional data, so automatic configuration management is blocked.',
      api3_requires_explicit_adapter: 'This provider needs a reviewed adapter before Configuration Manager can manage it automatically.',
      missing_portable_identity: 'This provider does not yet have a proven stable cross-site identity, so automatic management is blocked.',
      incomplete_identity_metadata: 'This provider does not provide enough identity information for safe cross-site management.',
      sensitive_identity: 'This provider relies on a potentially sensitive value for identity, so automatic management is blocked.',
      sensitive_writable_field: 'This provider may write sensitive values, so automatic management is blocked.',
      unmapped_reference_field: 'This provider has related values that cannot yet be mapped safely across sites.',
      reviewed_adapter: 'This provider uses reviewed portability and write-safety rules.',
      portable_identity_and_field_policy: 'This provider passed the current cross-site identity and field-safety checks.'
    };
    if (reasons[reasonCode]) {
      return reasons[reasonCode];
    }

    var status = classifyDetectedProvider(provider);
    if (status === 'managed') {
      return 'This provider passed the current safety checks and is managed through the Extensions configuration type.';
    }
    if (status === 'export_only') {
      return 'This provider can be saved and compared, but automatic restore/import is not enabled.';
    }
    if (status === 'review_only') {
      return 'This provider is visible for review, but automatic write and remove actions remain blocked.';
    }
    if (status === 'unavailable') {
      return 'This provider is not available in the current site or runtime.';
    }
    return 'This provider was detected, but automatic management is blocked until its portability and safety are proven.';
  }

  function selectDetectedProviders(providers, representedTypes) {
    var represented = {};
    (representedTypes || []).forEach(function(type) {
      represented[normalize(type)] = true;
    });
    return (providers || []).filter(function(provider) {
      if (!provider || !provider.type || isRejectedProvider(provider)) {
        return false;
      }
      return !represented[normalize(provider.type)];
    }).sort(function(a, b) {
      var aKey = [normalize(a.owner), normalize(a.label), normalize(a.provider_key)].join('\u0000');
      var bKey = [normalize(b.owner), normalize(b.label), normalize(b.provider_key)].join('\u0000');
      if (aKey === bKey) { return 0; }
      return aKey < bKey ? -1 : 1;
    });
  }

  function summarizeDetectedProviders(providers) {
    var summary = {
      total: 0,
      managed: 0,
      create_update: 0,
      export_only: 0,
      review_only: 0,
      not_separate: 0,
      unsupported: 0,
      unavailable: 0
    };
    (providers || []).forEach(function(provider) {
      var status = classifyDetectedProvider(provider);
      summary.total++;
      if (Object.prototype.hasOwnProperty.call(summary, status)) {
        summary[status]++;
      }
    });
    return summary;
  }

  function providerInventoryStatus(total, configurationTypeCount, detectedCount, rejectedCount) {
    total = Number(total) || 0;
    configurationTypeCount = Number(configurationTypeCount) || 0;
    detectedCount = Number(detectedCount) || 0;
    rejectedCount = Number(rejectedCount) || 0;

    var message = 'Provider safety details loaded: ' + total + ' provider entr' + (total === 1 ? 'y' : 'ies') + ' detected. ' + configurationTypeCount + ' configuration type' + (configurationTypeCount === 1 ? '' : 's') + ' available in Settings.';
    if (detectedCount > 0) {
      message += ' ' + detectedCount + ' additional provider' + (detectedCount === 1 ? '' : 's') + ' explained under Other detected providers.';
    }
    if (rejectedCount > 0) {
      message += ' ' + rejectedCount + ' registration' + (rejectedCount === 1 ? '' : 's') + ' rejected.';
    }
    return message;
  }

  function appendMeta(dl, label, value) {
    if (!dl || !value) { return; }
    var dt = document.createElement('dt');
    dt.textContent = label;
    var dd = document.createElement('dd');
    var code = document.createElement('code');
    code.textContent = String(value);
    dd.appendChild(code);
    dl.appendChild(dt);
    dl.appendChild(dd);
  }

  function hydrateSafety(row, provider) {
    if (!row || !provider) { return; }
    var owner = row.querySelector('[data-civicfg-provider-owner]');
    var reason = row.querySelector('[data-civicfg-provider-reason]');
    var meta = row.querySelector('[data-civicfg-provider-safety-meta]');
    if (owner) { owner.textContent = provider.owner || 'Unknown owner'; }
    if (reason) { reason.textContent = provider.capability_reason || 'No additional provider warning.'; }
    if (meta) {
      meta.innerHTML = '';
      appendMeta(meta, 'Registration', provider.registration_source);
      appendMeta(meta, 'Reason code', provider.capability_reason_code);
      appendMeta(meta, 'Identity evidence', provider.identity_evidence);
      appendMeta(meta, 'Metadata', provider.metadata_completeness);
    }
    row.setAttribute('data-civicfg-provider-group-key', classifyProvider(provider));
    row.setAttribute('data-civicfg-provider-owner-value', provider.owner || '');
    row.setAttribute('data-civicfg-provider-registration-value', provider.registration_source || '');
  }

  function providerActionSummary(provider) {
    var actions = provider && provider.actions ? provider.actions : {};
    return ['read', 'create', 'update', 'delete'].filter(function(action) {
      return actions[action] !== null && actions[action] !== undefined;
    }).map(function(action) {
      return action + ': ' + (actions[action] ? 'yes' : 'no');
    }).join(', ');
  }

  function renderDetectedProviderItem(provider) {
    var item = document.createElement('li');
    item.className = 'civicfg-detected-provider';

    var header = document.createElement('div');
    header.className = 'civicfg-detected-provider-header';
    var title = document.createElement('strong');
    title.textContent = provider.label || provider.entity || provider.type || 'Detected provider';
    header.appendChild(title);
    var badge = document.createElement('span');
    badge.className = 'civicfg-detected-provider-status civicfg-detected-provider-status-' + classifyDetectedProvider(provider);
    badge.textContent = detectedProviderStatusLabel(provider);
    header.appendChild(badge);
    item.appendChild(header);

    var reason = document.createElement('div');
    reason.className = 'civicfg-detected-provider-reason';
    reason.textContent = detectedProviderReason(provider);
    item.appendChild(reason);

    var technical = document.createElement('details');
    technical.className = 'civicfg-detected-provider-technical';
    var technicalSummary = document.createElement('summary');
    technicalSummary.textContent = 'Technical details';
    technical.appendChild(technicalSummary);
    var meta = document.createElement('dl');
    meta.className = 'civicfg-provider-safety-meta';
    appendMeta(meta, 'Type', provider.type);
    appendMeta(meta, 'Provider', provider.provider_key);
    appendMeta(meta, 'Extension / owner', provider.owner);
    appendMeta(meta, 'Registration', provider.registration_source);
    appendMeta(meta, 'API', provider.api_version);
    appendMeta(meta, 'Entity', provider.entity);
    appendMeta(meta, 'Actions', providerActionSummary(provider));
    appendMeta(meta, 'Identity', (provider.identity_fields || []).join(', '));
    appendMeta(meta, 'Reason code', provider.capability_reason_code);
    appendMeta(meta, 'Provider detail', provider.capability_reason);
    technical.appendChild(meta);
    item.appendChild(technical);

    return item;
  }

  function renderDetectedProviders(panel, providers, representedTypes) {
    if (!panel) {
      return summarizeDetectedProviders([]);
    }
    var detected = selectDetectedProviders(providers, representedTypes);
    var summary = summarizeDetectedProviders(detected);
    var count = panel.querySelector('[data-civicfg-provider-discovery-count]');
    var summaryHost = panel.querySelector('[data-civicfg-provider-discovery-summary]');
    var groupsHost = panel.querySelector('[data-civicfg-provider-discovery-groups]');

    if (count) { count.textContent = '(' + summary.total + ')'; }
    panel.hidden = summary.total === 0;
    if (!summaryHost || !groupsHost || summary.total === 0) {
      return summary;
    }

    summaryHost.innerHTML = '';
    groupsHost.innerHTML = '';
    var order = ['managed', 'create_update', 'export_only', 'review_only', 'not_separate', 'unsupported', 'unavailable'];
    order.forEach(function(status) {
      var statusCount = summary[status] || 0;
      if (!statusCount) { return; }
      var meta = detectedProviderGroupMeta(status);

      var chip = document.createElement('span');
      chip.className = 'civicfg-detected-provider-summary-item';
      chip.textContent = meta.label + ': ' + statusCount;
      summaryHost.appendChild(chip);

      var group = document.createElement('details');
      group.className = 'civicfg-detected-provider-group';
      group.setAttribute('data-civicfg-detected-provider-group', status);
      var groupSummary = document.createElement('summary');
      var groupTitle = document.createElement('strong');
      groupTitle.textContent = meta.label;
      groupSummary.appendChild(groupTitle);
      groupSummary.appendChild(document.createTextNode(' (' + statusCount + ')'));
      group.appendChild(groupSummary);

      var help = document.createElement('p');
      help.className = 'description';
      help.textContent = meta.help;
      group.appendChild(help);

      var list = document.createElement('ul');
      list.className = 'civicfg-detected-provider-list';
      detected.forEach(function(provider) {
        if (classifyDetectedProvider(provider) === status) {
          list.appendChild(renderDetectedProviderItem(provider));
        }
      });
      group.appendChild(list);
      groupsHost.appendChild(group);
    });

    return summary;
  }

  function renderRejectedRegistrations(groupsRoot, providers) {
    var host = groupsRoot ? groupsRoot.querySelector('[data-civicfg-provider-rejections]') : null;
    if (!host) { return; }
    host.innerHTML = '';
    host.setAttribute('data-civicfg-provider-rejection-count', '0');
    var rejected = (providers || []).filter(function(provider) {
      return normalize(provider.provider_key).indexOf('rejected:') === 0;
    });
    if (!rejected.length) { return; }
    host.setAttribute('data-civicfg-provider-rejection-count', String(rejected.length));
    var box = document.createElement('div');
    box.className = 'messages warning no-popup';
    var strong = document.createElement('strong');
    strong.textContent = rejected.length + ' rejected provider registration(s)';
    box.appendChild(strong);
    var list = document.createElement('ul');
    rejected.forEach(function(provider) {
      var li = document.createElement('li');
      li.textContent = (provider.type ? provider.type + ': ' : '') + (provider.capability_reason || 'Provider registration was rejected.');
      list.appendChild(li);
    });
    box.appendChild(list);
    host.appendChild(box);
  }

  function init(doc) {
    var form = doc.querySelector('[data-civicfg-settings-form]');
    var groupsRoot = doc.querySelector('[data-civicfg-provider-groups]');
    if (!form || !groupsRoot || form.getAttribute('data-civicfg-provider-browser-ready') === '1') { return; }
    form.setAttribute('data-civicfg-provider-browser-ready', '1');

    var endpoint = form.getAttribute('data-civicfg-provider-inventory-url') || '';
    var state = doc.querySelector('[data-civicfg-provider-inventory-state]');
    var search = doc.querySelector('[data-civicfg-provider-search]');
    var filter = doc.querySelector('[data-civicfg-provider-group-filter]');
    var visibleCount = doc.querySelector('[data-civicfg-provider-visible-count]');
    var empty = doc.querySelector('[data-civicfg-provider-empty]');
    var discoveryPanel = doc.querySelector('[data-civicfg-provider-discovery]');
    var loadingGroup = groupsRoot.querySelector('[data-civicfg-provider-group="loading"]');
    var rows = Array.prototype.slice.call(groupsRoot.querySelectorAll('[data-civicfg-scope-row]'));
    var providerByType = {};

    function groupSection(key) {
      return groupsRoot.querySelector('[data-civicfg-provider-group="' + key + '"]');
    }

    function applyFilters() {
      var query = search ? search.value : '';
      var selectedGroup = filter ? filter.value : 'all';
      var visible = 0;
      var counts = {};
      rows.forEach(function(row) {
        var type = row.getAttribute('data-civicfg-scope-row') || '';
        var provider = providerByType[type] || {
          type: type,
          label: row.getAttribute('data-scope-label') || type,
          capability: row.getAttribute('data-scope-capability') || '',
          owner: row.getAttribute('data-civicfg-provider-owner-value') || '',
          registration_source: row.getAttribute('data-civicfg-provider-registration-value') || ''
        };
        var group = row.getAttribute('data-civicfg-provider-group-key') || 'loading';
        var show = (selectedGroup === 'all' || selectedGroup === group) && matchesSearch(provider, query);
        row.hidden = !show;
        if (show) { visible++; }
        counts[group] = (counts[group] || 0) + (show ? 1 : 0);
      });

      groupsRoot.querySelectorAll('[data-civicfg-provider-group]').forEach(function(section) {
        var key = section.getAttribute('data-civicfg-provider-group') || '';
        if (key === 'loading') { return; }
        var count = counts[key] || 0;
        var rejectionHost = key === 'unavailable' ? section.querySelector('[data-civicfg-provider-rejections]') : null;
        var rejectionCount = rejectionHost ? parseInt(rejectionHost.getAttribute('data-civicfg-provider-rejection-count') || '0', 10) : 0;
        var groupVisible = count > 0 || (rejectionCount > 0 && (selectedGroup === 'all' || selectedGroup === 'unavailable') && !normalize(query));
        section.hidden = !groupVisible;
        var countNode = section.querySelector('[data-civicfg-provider-group-count]');
        if (countNode) {
          countNode.textContent = rejectionCount > 0 ? ('(' + count + ' + ' + rejectionCount + ' rejected)') : ('(' + count + ')');
        }
      });
      if (visibleCount) { visibleCount.textContent = visible + ' configuration type(s) shown'; }
      if (empty) { empty.hidden = visible !== 0; }
    }

    function placeRows() {
      rows.forEach(function(row) {
        var type = row.getAttribute('data-civicfg-scope-row') || '';
        var provider = providerByType[type];
        if (!provider) { return; }
        hydrateSafety(row, provider);
        var group = classifyProvider(provider);
        var section = groupSection(group);
        var grid = section ? section.querySelector('[data-civicfg-provider-group-grid]') : null;
        if (grid) { grid.appendChild(row); }
      });
      if (loadingGroup) { loadingGroup.hidden = true; }
      applyFilters();
    }

    if (search) { search.addEventListener('input', applyFilters); }
    if (filter) { filter.addEventListener('change', applyFilters); }
    applyFilters();

    if (!endpoint) {
      if (state) { state.textContent = 'Provider safety details are unavailable because no inventory endpoint was supplied.'; }
      return;
    }

    fetch(endpoint, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
      .then(function(response) {
        return response.json().then(function(payload) {
          if (!response.ok || !payload || payload.ok === false) {
            throw new Error(payload && payload.error ? payload.error : 'Could not load provider inventory.');
          }
          return payload;
        });
      })
      .then(function(payload) {
        (payload.providers || []).forEach(function(provider) {
          if (!provider || !provider.type || normalize(provider.provider_key).indexOf('rejected:') === 0) { return; }
          if (!providerByType[provider.type]) { providerByType[provider.type] = provider; }
        });
        rows.forEach(function(row) {
          var type = row.getAttribute('data-civicfg-scope-row') || '';
          if (providerByType[type]) { return; }
          providerByType[type] = {
            type: type,
            label: row.getAttribute('data-scope-label') || type,
            capability: row.getAttribute('data-scope-capability') || 'unavailable',
            admitted: false,
            capability_reason: 'Provider inventory did not return metadata for this registered configuration type.'
          };
        });
        var providers = payload.providers || [];
        var representedTypes = rows.map(function(row) {
          return row.getAttribute('data-civicfg-scope-row') || '';
        });
        renderRejectedRegistrations(groupsRoot, providers);
        var detectedSummary = renderDetectedProviders(discoveryPanel, providers, representedTypes);
        placeRows();
        if (state) {
          var total = payload.summary && payload.summary.provider_count !== undefined ? payload.summary.provider_count : providers.length;
          var rejectedCount = providers.filter(isRejectedProvider).length;
          state.textContent = providerInventoryStatus(total, rows.length, detectedSummary.total, rejectedCount);
        }
      })
      .catch(function(error) {
        if (state) { state.textContent = 'Provider safety details could not be loaded: ' + (error && error.message ? error.message : 'Unknown error'); }
        rows.forEach(function(row) {
          var owner = row.querySelector('[data-civicfg-provider-owner]');
          var reason = row.querySelector('[data-civicfg-provider-reason]');
          if (owner) { owner.textContent = 'Not loaded'; }
          if (reason) { reason.textContent = 'The saved scope controls remain usable. Reload the page to retry provider metadata.'; }
        });
      });
  }

  return {
    classifyProvider: classifyProvider,
    matchesSearch: matchesSearch,
    classifyDetectedProvider: classifyDetectedProvider,
    detectedProviderReason: detectedProviderReason,
    selectDetectedProviders: selectDetectedProviders,
    summarizeDetectedProviders: summarizeDetectedProviders,
    providerInventoryStatus: providerInventoryStatus,
    init: init
  };
});
