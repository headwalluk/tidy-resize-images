/**
 * Tidy Resize Images — admin behaviour.
 *
 * Modules:
 *   - Tab navigation for the .tri-settings page (hash-based)
 *   - Bulk runner for the .tri-bulk page (AJAX loop)
 *
 * Each module is scoped to its wrap selector so it only activates on the
 * intended page.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    initTabs(document.querySelector('.tri-settings'));
    initBulk(document.querySelector('.tri-bulk'));
  });

  // ===== Settings tabs ===================================================

  function initTabs(wrap) {
    if (!wrap) {
      return;
    }

    const tabs = wrap.querySelectorAll('.nav-tab');
    const panels = wrap.querySelectorAll('.tri-tab-panel');

    if (0 === tabs.length || 0 === panels.length) {
      return;
    }

    const defaultTab = tabs[0].dataset.tab;

    function activateTab(tabName) {
      tabs.forEach(function (tab) {
        tab.classList.remove('nav-tab-active');
      });
      const navTab = wrap.querySelector('[data-tab="' + tabName + '"]');
      if (navTab) {
        navTab.classList.add('nav-tab-active');
      }

      panels.forEach(function (panel) {
        panel.style.display = 'none';
        panel.classList.remove('active');
      });
      const panel = wrap.querySelector('#' + tabName + '-panel');
      if (panel) {
        panel.style.display = 'block';
        panel.classList.add('active');
      }
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        const tabName = tab.dataset.tab;
        window.location.hash = tabName;
        activateTab(tabName);
      });
    });

    window.addEventListener('hashchange', function () {
      const tabName = window.location.hash.substring(1) || defaultTab;
      activateTab(tabName);
    });

    const initial = window.location.hash.substring(1) || defaultTab;
    activateTab(initial);
  }

  // ===== Bulk runner =====================================================

  function initBulk(wrap) {
    if (!wrap || 'undefined' === typeof window.triBulk) {
      return;
    }

    const startButtons = wrap.querySelectorAll('.tri-bulk-start');
    const stopBtn = wrap.querySelector('.tri-bulk-stop');
    const statusLine = wrap.querySelector('.tri-bulk-status-line');
    const bar = wrap.querySelector('.tri-bulk-bar');
    const candidateCountEl = wrap.querySelector('.tri-bulk-candidate-count');
    const examinedEl = wrap.querySelector('.tri-bulk-examined');
    const changedEl = wrap.querySelector('.tri-bulk-changed');
    const skippedEl = wrap.querySelector('.tri-bulk-skipped');
    const erroredEl = wrap.querySelector('.tri-bulk-errored');
    const savedEl = wrap.querySelector('.tri-bulk-saved');
    const logBody = wrap.querySelector('.tri-bulk-log tbody');

    const totalCandidates = parseInt(candidateCountEl ? candidateCountEl.textContent : '0', 10) || 0;

    const totals = { examined: 0, changed: 0, skipped: 0, errored: 0, bytes: 0 };
    let stopped = false;
    let running = false;

    function setStatus(text) {
      if (statusLine) {
        statusLine.textContent = text;
      }
    }

    function setRunning(on) {
      running = on;
      startButtons.forEach(function (btn) {
        btn.disabled = on || 0 === totalCandidates;
      });
      if (stopBtn) {
        stopBtn.disabled = !on;
      }
    }

    function renderTotals() {
      if (examinedEl) examinedEl.textContent = String(totals.examined);
      if (changedEl) changedEl.textContent = String(totals.changed);
      if (skippedEl) skippedEl.textContent = String(totals.skipped);
      if (erroredEl) erroredEl.textContent = String(totals.errored);
      if (savedEl) savedEl.textContent = formatBytes(totals.bytes);
    }

    function updateBar() {
      if (!bar || 0 === totalCandidates) {
        return;
      }
      const pct = Math.min(100, Math.round((totals.examined / totalCandidates) * 100));
      bar.style.width = pct + '%';
    }

    function appendLog(entries) {
      if (!logBody || !entries || !entries.length) {
        return;
      }
      const fragment = document.createDocumentFragment();
      entries.forEach(function (entry) {
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td>' +
          escapeHtml(String(entry.id)) +
          '</td>' +
          '<td>' +
          escapeHtml(entry.title || '') +
          '</td>' +
          '<td>' +
          actionBadge(entry.action) +
          '</td>' +
          '<td><code>' +
          escapeHtml(entry.reason || '') +
          '</code></td>' +
          '<td>' +
          (entry.savings_bytes ? formatBytes(entry.savings_bytes) : '') +
          '</td>';
        fragment.appendChild(tr);
      });
      logBody.appendChild(fragment);
    }

    function actionBadge(action) {
      const colors = {
        committed: '#00a32a',
        planned: '#2271b1',
        skipped: '#646970',
        discarded: '#996800',
        errored: '#d63638',
      };
      const color = colors[action] || '#1d2327';
      return '<strong style="color:' + color + ';">' + escapeHtml(action || '') + '</strong>';
    }

    function escapeHtml(str) {
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function formatBytes(n) {
      if (n < 1024) return n + ' B';
      if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
      return (n / 1024 / 1024).toFixed(2) + ' MB';
    }

    function step(cursor, dry) {
      const formData = new FormData();
      formData.append('action', 'tri_bulk_step');
      formData.append('nonce', window.triBulk.nonce);
      formData.append('cursor', String(cursor));
      formData.append('limit', '5');
      if (dry) {
        formData.append('dry_run', '1');
      }

      return fetch(window.triBulk.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function (res) {
          return res.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            setStatus(window.triBulk.i18n.errored + ' ' + (json && json.data && json.data.message ? json.data.message : ''));
            setRunning(false);
            return;
          }

          const data = json.data;
          totals.examined += data.attachments_examined;
          totals.changed += data.attachments_changed;
          totals.skipped += data.attachments_skipped;
          totals.errored += data.attachments_errored;
          totals.bytes += data.bytes_saved;

          renderTotals();
          updateBar();
          appendLog(data.log);

          if (stopped) {
            setStatus(window.triBulk.i18n.stopped);
            setRunning(false);
            return;
          }

          if (data.done) {
            setStatus(window.triBulk.i18n.done);
            if (bar) bar.style.width = '100%';
            setRunning(false);
            return;
          }

          return step(data.last_cursor, dry);
        })
        .catch(function (err) {
          setStatus(window.triBulk.i18n.errored + ' ' + (err && err.message ? err.message : ''));
          setRunning(false);
        });
    }

    startButtons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (running) {
          return;
        }
        const mode = btn.dataset.mode;
        if ('live' === mode && !window.confirm(window.triBulk.i18n.confirmLive)) {
          return;
        }

        totals.examined = 0;
        totals.changed = 0;
        totals.skipped = 0;
        totals.errored = 0;
        totals.bytes = 0;
        renderTotals();
        if (bar) bar.style.width = '0%';
        if (logBody) logBody.innerHTML = '';
        stopped = false;
        setRunning(true);
        setStatus(window.triBulk.i18n.starting);

        step(0, 'dry' === mode);
      });
    });

    if (stopBtn) {
      stopBtn.addEventListener('click', function () {
        if (!running) return;
        stopped = true;
        setStatus(window.triBulk.i18n.processing + ' (' + window.triBulk.i18n.stopped.replace(/\.$/, '') + '…)');
      });
    }
  }
})();
