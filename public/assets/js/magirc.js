/* MagIRC 2 — theme toggle, sortable/filterable tables, charts. No framework. */
(function () {
  'use strict';

  var root = document.documentElement;

  /* ---- Theme ------------------------------------------------------------ */
  function currentTheme() {
    return root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
  }
  function applyTheme(theme, persist) {
    root.setAttribute('data-theme', theme);
    if (persist) {
      try { localStorage.setItem('magirc-theme', theme); } catch (e) { /* private mode */ }
    }
    document.querySelectorAll('.chart').forEach(function (el) { if (el._chart) renderChart(el); });
  }
  var toggle = document.querySelector('[data-theme-toggle]');
  if (toggle) {
    toggle.addEventListener('click', function () {
      applyTheme(currentTheme() === 'dark' ? 'light' : 'dark', true);
    });
  }
  var mql = window.matchMedia('(prefers-color-scheme: dark)');
  if (mql.addEventListener) {
    mql.addEventListener('change', function (e) {
      var stored = null;
      try { stored = localStorage.getItem('magirc-theme'); } catch (err) { /* ignore */ }
      if (!stored) applyTheme(e.matches ? 'dark' : 'light', false);
    });
  }

  /* ---- Mobile navigation ----------------------------------------------- */
  var navToggle = document.querySelector('.nav__toggle');
  if (navToggle) {
    navToggle.addEventListener('click', function () {
      var nav = navToggle.closest('.nav');
      var open = nav.classList.toggle('nav--open');
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ---- Sortable tables ------------------------------------------------- */
  function cellValue(cell) {
    var v = cell.getAttribute('data-sort-value');
    if (v === null) v = cell.textContent.trim();
    var n = Number(v);
    return v !== '' && !isNaN(n) ? n : v.toLowerCase();
  }
  function sortTable(table, index, dir) {
    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.rows);
    rows.sort(function (a, b) {
      var x = cellValue(a.cells[index]), y = cellValue(b.cells[index]);
      var r = (typeof x === 'number' && typeof y === 'number') ? x - y : String(x).localeCompare(String(y));
      return dir === 'desc' ? -r : r;
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
    if (table._pager) table._pager.go(1);
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, i) {
      if (i === index) th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
      else th.removeAttribute('aria-sort');
    });
  }
  document.querySelectorAll('.table--sortable').forEach(function (table) {
    if (!table.tHead || !table.tBodies[0]) return;
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th, i) {
      th.setAttribute('tabindex', '0');
      th.setAttribute('role', 'button');
      function go() {
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc';
        if (!th.getAttribute('aria-sort') && th.classList.contains('num')) dir = 'desc';
        sortTable(table, i, dir);
      }
      th.addEventListener('click', go);
      th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
      var initial = th.getAttribute('data-sort');
      if (initial) sortTable(table, i, initial);
    });
  });

  /* ---- Table filter ---------------------------------------------------- */
  document.querySelectorAll('[data-filter-table]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-filter-table'));
    if (!table) return;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
        row.dataset.filtered = (q !== '' && row.textContent.toLowerCase().indexOf(q) === -1) ? '1' : '';
      });
      if (table._pager) table._pager.go(1); else applyVisibility(table);
    });
  });

  function applyVisibility(table) {
    Array.prototype.forEach.call(table.tBodies[0].rows, function (row) {
      row.hidden = row.dataset.filtered === '1' || row.dataset.paged === '1';
    });
  }

  /* ---- Client-side paging --------------------------------------------- */
  var pageSize = parseInt(root.getAttribute('data-page-size') || '50', 10);
  function labelOf(name, fallback) {
    var el = document.querySelector('[data-i18n-' + name + ']');
    return el ? el.getAttribute('data-i18n-' + name) : fallback;
  }
  document.querySelectorAll('.table--paged').forEach(function (table) {
    var tbody = table.tBodies[0];
    if (!tbody || pageSize <= 0 || tbody.rows.length <= pageSize) return;
    var nav = document.createElement('nav');
    nav.className = 'pager';
    nav.setAttribute('aria-label', labelOf('pager', 'Pages'));
    var prev = document.createElement('button'); prev.type = 'button'; prev.textContent = labelOf('prev', 'Previous');
    var next = document.createElement('button'); next.type = 'button'; next.textContent = labelOf('next', 'Next');
    var info = document.createElement('span'); info.className = 'pager__info';
    nav.appendChild(prev); nav.appendChild(info); nav.appendChild(next);
    table.parentNode.insertBefore(nav, table.nextSibling);
    var page = 1;
    function go(n) {
      // Page over the rows that survive the filter, in current DOM (sorted) order.
      var rows = Array.prototype.filter.call(tbody.rows, function (r) { return r.dataset.filtered !== '1'; });
      var pages = Math.max(1, Math.ceil(rows.length / pageSize));
      page = Math.min(Math.max(1, n), pages);
      rows.forEach(function (r, i) { r.dataset.paged = (i < (page - 1) * pageSize || i >= page * pageSize) ? '1' : ''; });
      applyVisibility(table);
      var from = rows.length ? (page - 1) * pageSize + 1 : 0, to = Math.min(page * pageSize, rows.length);
      info.textContent = from + '\u2013' + to + ' / ' + rows.length;
      prev.disabled = page <= 1; next.disabled = page >= pages;
      nav.hidden = rows.length <= pageSize;
    }
    prev.addEventListener('click', function () { go(page - 1); if (nav.scrollIntoView) table.scrollIntoView({ block: 'start' }); });
    next.addEventListener('click', function () { go(page + 1); table.scrollIntoView({ block: 'start' }); });
    table._pager = { go: go };
    go(1);
  });

  /* ---- Charts (ECharts) ------------------------------------------------ */
  function cssVar(name) {
    return getComputedStyle(root).getPropertyValue(name).trim();
  }
  function baseOption() {
    var text = cssVar('--muted'), rule = cssVar('--rule');
    return {
      animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
      textStyle: { fontFamily: cssVar('--font') },
      grid: { left: 8, right: 16, top: 16, bottom: 8, containLabel: true },
      tooltip: { trigger: 'axis', backgroundColor: cssVar('--page'), borderColor: rule, textStyle: { color: cssVar('--text') } },
      xAxis: { axisLine: { lineStyle: { color: rule } }, axisTick: { show: false }, axisLabel: { color: text } },
      yAxis: { splitLine: { lineStyle: { color: rule } }, axisLabel: { color: text }, minInterval: 1 }
    };
  }
  function lineChart(el, points) {
    var series = (el.getAttribute('data-series') || 'users').split(',');
    var colors = [cssVar('--chart-1'), cssVar('--chart-2'), cssVar('--chart-3')];
    var opt = baseOption();
    opt.xAxis.type = 'time';
    // Show the whole window the page promises (e.g. "last 24 hours") even
    // when only part of it has samples yet.
    var range = parseInt(el.getAttribute('data-range') || '0', 10);
    var now = Math.floor(Date.now() / 1000);
    if (range > 0) {
      opt.xAxis.min = (now - range) * 1000;
      opt.xAxis.max = now * 1000;
    }
    var span = range || (points.length ? (points[points.length - 1].time - points[0].time) : 0);
    var lang = root.getAttribute('lang') || undefined;
    opt.xAxis.axisLabel.hideOverlap = true;
    opt.xAxis.axisLabel.formatter = function (value) {
      var d = new Date(value);
      if (span > 3 * 86400) return d.toLocaleDateString(lang, { day: 'numeric', month: 'short' });
      return d.toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit', hour12: false });
    };
    opt.yAxis.type = 'value';
    opt.legend = series.length > 1 ? { bottom: 0, textStyle: { color: cssVar('--muted') }, icon: 'roundRect' } : undefined;
    if (series.length > 1) opt.grid.bottom = 28;
    opt.series = series.map(function (key, i) {
      var last = points[points.length - 1];
      return {
        name: key.charAt(0).toUpperCase() + key.slice(1),
        type: 'line', showSymbol: false, smooth: 0.25,
        lineStyle: { width: 2, color: colors[i] }, itemStyle: { color: colors[i] },
        areaStyle: i === 0 && series.length === 1 ? { color: colors[0], opacity: 0.08 } : undefined,
        data: points.map(function (p) { return [p.time * 1000, p[key]]; }),
        markPoint: series.length === 1 && last ? {
          symbol: 'circle', symbolSize: 8, label: { show: true, position: 'top', distance: 8, color: cssVar('--text'), fontWeight: 600 },
          data: [{ coord: [last.time * 1000, last[key]], value: last[key] }]
        } : undefined
      };
    });
    return opt;
  }
  function barChart(el, points) {
    var opt = baseOption();
    opt.xAxis.type = 'category';
    opt.xAxis.data = points.map(function (_, h) { return (h < 10 ? '0' : '') + h + 'h'; });
    opt.yAxis.type = 'value';
    opt.series = [{ type: 'bar', data: points, itemStyle: { color: cssVar('--chart-1'), borderRadius: [2, 2, 0, 0] } }];
    return opt;
  }
  function pieChart(el, points) {
    var nameKey = el.getAttribute('data-name') || 'name', valueKey = el.getAttribute('data-value') || 'count';
    var base = cssVar('--chart-1');
    return {
      animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
      textStyle: { fontFamily: cssVar('--font') },
      tooltip: { trigger: 'item', backgroundColor: cssVar('--page'), borderColor: cssVar('--rule'), textStyle: { color: cssVar('--text') } },
      series: [{
        type: 'pie', radius: ['45%', '75%'], center: ['50%', '50%'],
        label: { color: cssVar('--muted'), formatter: '{b}' },
        labelLine: { lineStyle: { color: cssVar('--rule') } },
        itemStyle: { borderColor: cssVar('--page'), borderWidth: 2 },
        data: points.map(function (p, i) {
          var t = points.length > 1 ? i / (points.length - 1) : 0;
          return { name: p[nameKey], value: p[valueKey], itemStyle: { color: base, opacity: 1 - t * 0.75 } };
        })
      }]
    };
  }
  function renderChart(el) {
    if (typeof echarts === 'undefined') return;
    var points;
    try { points = JSON.parse(el.getAttribute('data-points') || '[]'); } catch (e) { return; }
    var kind = el.getAttribute('data-chart');
    var opt = kind === 'pie' ? pieChart(el, points) : kind === 'bars' ? barChart(el, points) : lineChart(el, points);
    if (el._chart) el._chart.dispose();
    el._chart = echarts.init(el, null, { renderer: 'svg' });
    el._chart.setOption(opt);
  }
  function initCharts() {
    document.querySelectorAll('.chart').forEach(renderChart);
    window.addEventListener('resize', function () {
      document.querySelectorAll('.chart').forEach(function (el) { if (el._chart) el._chart.resize(); });
    });
  }
  if (typeof echarts !== 'undefined') initCharts();
  else window.addEventListener('load', initCharts);
})();
