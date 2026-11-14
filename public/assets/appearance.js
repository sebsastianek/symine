/**
 * Symine appearance runtime.
 *
 * Personal options of a theme are plain attributes of <html> (data-color-scheme, data-theme-<option>) and custom
 * properties in its style attribute. This script applies a change at once and, for the quick switcher of the top
 * menu, saves it. Everything also works without it: the forms submit normally.
 *
 * Themes can listen to the "appearance:change" event of document: detail = {option, value}.
 */
(function () {
  'use strict';
  var root = document.documentElement;

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function setAttribute(name, value) {
    if (name) { root.setAttribute(name, value); }
  }

  function setToken(token, value) {
    if (!token) { return; }
    if (value === null || value === '') { root.style.removeProperty('--' + token); } else { root.style.setProperty('--' + token, value); }
  }

  function announce(option, value) {
    document.dispatchEvent(new CustomEvent('appearance:change', {detail: {option: option, value: value}}));
  }

  // --- quick switcher: apply, then save -------------------------------------
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-appearance-value]');
    if (!button) { return; }
    var group = button.closest('[data-appearance-option]');
    var holder = button.closest('[data-appearance-url]');
    if (!group || !holder) { return; }
    event.preventDefault();

    var previous = root.getAttribute(group.dataset.appearanceAttribute);
    setAttribute(group.dataset.appearanceAttribute, button.dataset.appearanceAttributeValue);
    group.querySelectorAll('[data-appearance-value]').forEach(function (other) {
      other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
    });
    announce(group.dataset.appearanceOption, button.dataset.appearanceValue);

    var body = new URLSearchParams();
    body.set('option', group.dataset.appearanceOption);
    body.set('value', button.dataset.appearanceValue);
    fetch(holder.dataset.appearanceUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'X-CSRF-Token': csrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
      body: body
    }).then(function (response) {
      if (!response.ok) { throw new Error(String(response.status)); }
    }).catch(function () {
      // not saved: show what is still in effect
      if (previous !== null) { setAttribute(group.dataset.appearanceAttribute, previous); }
      group.querySelectorAll('[data-appearance-value]').forEach(function (other) {
        other.setAttribute('aria-pressed', other.dataset.appearanceAttributeValue === previous ? 'true' : 'false');
      });
    });
  });

  // --- forms: show changes as they are made, saved on submit -----------------
  function applyField(field) {
    var data = field.dataset;
    var name = data.appearanceLive;
    if (field.type === 'radio' && !field.checked) { return; }

    if (field.type === 'checkbox') {
      setAttribute(data.appearanceAttribute, field.checked ? 'on' : 'off');
      return announce(name, field.checked ? '1' : '0');
    }
    if (data.appearanceCustom) {
      // colour picker of a colour option: selects "custom" when the option has presets
      var custom = field.form.querySelector('input[type="radio"][data-appearance-live="' + name + '"][value="custom"]');
      if (custom) { custom.checked = true; }
      setAttribute(data.appearanceAttribute, 'custom');
      setToken(data.appearanceToken, field.value);
      return announce(name, field.value);
    }
    if (field.type === 'radio' && data.appearanceToken) {
      // colour option: a preset, or the colour of the picker
      if (field.value === 'custom') {
        var picker = field.form.querySelector('input[data-appearance-custom][data-appearance-live="' + name + '"]');
        setAttribute(data.appearanceAttribute, 'custom');
        setToken(data.appearanceToken, picker ? picker.value : null);
      } else {
        setAttribute(data.appearanceAttribute, field.value);
        setToken(data.appearanceToken, null);
      }
      return announce(name, field.value);
    }
    if (data.appearanceAttribute) {
      setAttribute(data.appearanceAttribute, field.value);
      return announce(name, field.value);
    }
    if (data.appearanceToken && field.value !== '' && field.checkValidity()) {
      setToken(data.appearanceToken, field.value + (data.appearanceUnit || ''));
      announce(name, field.value);
    }
  }

  ['input', 'change'].forEach(function (type) {
    document.addEventListener(type, function (event) {
      var field = event.target;
      if (field && field.dataset && field.dataset.appearanceLive && field.closest('[data-appearance-form]')) { applyField(field); }
    });
  });
})();
