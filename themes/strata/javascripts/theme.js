/* Strata: small behaviours that go with the layout. */
(function () {
  'use strict';

  function ready(callback) {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', callback); } else { callback(); }
  }

  ready(function () {
    // The search field has no visible label in the rail: its label becomes the placeholder.
    var search = document.getElementById('q');
    var label = document.querySelector('#quick-search label[for="q"]');
    if (search && label && !search.placeholder) {
      search.placeholder = label.textContent.replace(/[:\s]+$/, '').trim();
    }
  });

  // Redmine decides once, on load, whether the menu needs its scroll arrows. Options that change the width of the
  // menu (navigation, density, typeface, text size) are applied live: show every item again and measure anew.
  document.addEventListener('appearance:change', function () {
    window.requestAnimationFrame(function () {
      document.querySelectorAll('#main-menu > ul > li').forEach(function (item) { item.style.display = ''; });
      if (typeof window.displayTabsButtons === 'function') { window.displayTabsButtons(); }
    });
    themeCharts();
  });

  // Tabs that do not fit are scrolled with Redmine's arrow buttons, which start at the first tab even when the
  // selected one is further right: step until the selected tab is in view.
  function revealSelectedTabs() {
    document.querySelectorAll('#content .tabs').forEach(function (tabs) {
      var selected = tabs.querySelector('ul li a.selected');
      var buttons = tabs.querySelector('.tabs-buttons');
      var next = buttons && buttons.querySelector('.tab-right');
      if (!selected || !next) { return; }
      for (var i = 0; i < 40; i++) {
        if (buttons.offsetParent === null || selected.getBoundingClientRect().right <= buttons.getBoundingClientRect().left) { break; }
        next.click();
      }
    });
  }
  // Redmine measures the tabs when the document is ready; the web font arriving later changes their width.
  function measureTabs() {
    if (typeof window.displayTabsButtons === 'function' && document.querySelector('.tabs')) { window.displayTabsButtons(); }
    revealSelectedTabs();
  }
  window.addEventListener('load', measureTabs);
  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(measureTabs); }

  // Charts (repository statistics, issue reports) are drawn on a canvas with Chart.js's own fixed greys: give the
  // library the colours of the theme, and redraw what is already there when the colour scheme changes.
  function tokenColor(name) {
    var probe = document.createElement('span');
    probe.style.color = 'var(' + name + ')';
    document.body.appendChild(probe);
    var color = window.getComputedStyle(probe).color;
    probe.remove();
    return color;
  }
  function themeCharts() {
    if (!document.querySelector('canvas')) { return; }
    import('chart.js').then(function (module) {
      var Chart = module.default || module.Chart;
      if (!Chart || !Chart.defaults) { return; }
      var text = tokenColor('--st-text-muted');
      var line = tokenColor('--st-line');
      Chart.defaults.color = text;
      Chart.defaults.borderColor = line;
      // a chart copies the colours of its axes when it is created: set them again on the ones already drawn
      Object.keys(Chart.instances || {}).forEach(function (id) {
        var chart = Chart.instances[id];
        var scales = (chart.options && chart.options.scales) || {};
        Object.keys(scales).forEach(function (axis) {
          var scale = scales[axis];
          if (scale.ticks) { scale.ticks.color = text; }
          if (scale.title) { scale.title.color = text; }
          if (scale.grid) { scale.grid.color = line; }
          if (scale.border) { scale.border.color = line; }
        });
        chart.update();
      });
    }).catch(function () { /* no chart library on this page */ });
  }
  ready(themeCharts);
})();
