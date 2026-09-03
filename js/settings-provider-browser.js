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
        renderRejectedRegistrations(groupsRoot, payload.providers || []);
        placeRows();
        if (state) {
          var count = payload.summary && payload.summary.provider_count !== undefined ? payload.summary.provider_count : rows.length;
          state.textContent = 'Provider safety details loaded for ' + count + ' registered provider(s).';
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
    init: init
  };
});
