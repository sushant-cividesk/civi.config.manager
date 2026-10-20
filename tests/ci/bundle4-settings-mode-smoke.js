/* DOM-lite behavioral smoke for progressive Settings mode radio enhancement. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function element(tag) {
  const attrs = {};
  const callbacks = {};
  return {
    tagName: tag.toUpperCase(), children: [], attributes: attrs, callbacks,
    classList: {add() {}, remove() {}},
    textContent: '', value: '', checked: false, hidden: false, disabled: false,
    setAttribute(key, value) { attrs[key] = value; },
    getAttribute(key) { return Object.prototype.hasOwnProperty.call(attrs, key) ? attrs[key] : null; },
    appendChild(child) { this.children.push(child); return child; },
    addEventListener(type, callback) { callbacks[type] = callback; },
    dispatchEvent(event) { if (callbacks[event.type]) { callbacks[event.type](event); } },
  };
}

const mode = element('select');
mode.value = 'watch';
mode.options = ['all', 'selected', 'watch', 'ignore'].map(value => ({
  value, textContent: value === 'all' ? 'Manage everything' : value, disabled: value === 'all',
}));
let modeCards;
mode.parentNode = {insertBefore(cards) { modeCards = cards; }};
const selectedControls = element('div');
const selectedCount = element('span');
const availableCount = element('span');
const row = element('section');
row.getAttribute = name => name === 'data-civicfg-scope-row' ? 'sample' : name === 'data-scope-label' ? 'Sample' : null;
row.querySelector = selector => ({
  '[data-civicfg-scope-mode]': mode,
  '[data-civicfg-mode-options]': modeCards,
  '[data-civicfg-selected-controls]': selectedControls,
  '[data-civicfg-scope-count]': selectedCount,
  '[data-civicfg-scope-available-count]': availableCount,
}[selector] || null);
row.querySelectorAll = selector => selector === '[data-civicfg-mode-choice]'
  ? modeCards.children.slice(1).map(label => label.children[0])
  : [];
const doc = {
  querySelectorAll(selector) { return selector === '[data-civicfg-scope-row]' ? [row] : []; },
  querySelector() { return null; },
  getElementById() { return null; },
  createElement: element,
};
const context = vm.createContext({window: {}, document: doc, Event: class {constructor(type) {this.type = type;} }});
const js = fs.readFileSync(path.resolve(__dirname, '../../js/configmanager-scope.js'), 'utf8');
vm.runInContext(js, context);
context.window.civicfgInitScopeSettings(value => value, response => response);
assert(modeCards, 'mode-choice group must be constructed');
assert.strictEqual(modeCards.children.length, 5, 'four choices and legend');
assert.strictEqual(mode.hidden, true, 'enhancement must hide only the authoritative select, not disable it');
assert.strictEqual(mode.disabled, false, 'mode POST control must remain enabled');
const radios = row.querySelectorAll('[data-civicfg-mode-choice]');
assert.strictEqual(radios.length, 4);
assert.strictEqual(radios[0].disabled, true, 'unavailable mode options must stay disabled');
assert.strictEqual(radios[2].checked, true, 'saved mode must be shown');
radios[1].checked = true;
radios[1].dispatchEvent({type: 'change'});
assert.strictEqual(mode.value, 'selected', 'radio choice must update actual scope_mode form value');
assert.strictEqual(selectedControls.hidden, false, 'selected mode must show the managed item picker');
assert.strictEqual(radios[1].checked, true);
mode.value = 'ignore';
mode.dispatchEvent({type: 'change'});
assert.strictEqual(radios[3].checked, true, 'existing bulk changes must refresh mode cards');
assert.strictEqual(selectedControls.hidden, true, 'ignore mode must conceal selected-only controls');
assert.match(availableCount.textContent, /Item count loads when Choose items is opened/);
console.log('Bundle 4 Settings mode choices and on-demand count smoke OK.');
