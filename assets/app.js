// JBWizerd Panel — small UI helpers

// === Theme system (auto / dark / light) ===
(function () {
  var THEME_KEY = 'jb_theme';   // 'auto' | 'dark' | 'light'
  function getStoredMode() {
    try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; }
  }
  function systemTheme() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  function resolvedTheme(mode) {
    return mode === 'dark' || mode === 'light' ? mode : systemTheme();
  }
  function applyTheme() {
    var mode = getStoredMode() || 'auto';
    var theme = resolvedTheme(mode);
    document.documentElement.setAttribute('data-theme', theme);
    // segmented control state
    document.querySelectorAll('[data-theme-select]').forEach(function (sel) {
      sel.querySelectorAll('[data-mode]').forEach(function (btn) {
        btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
      });
    });
    // legacy toggle buttons (login/setup floating button)
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.textContent = theme === 'dark' ? '☀' : '☾';
      btn.setAttribute('title', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    });
  }
  applyTheme();

  // Live-follow the OS when in auto mode
  var mq = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)');
  if (mq && mq.addEventListener) {
    mq.addEventListener('change', function () {
      if ((getStoredMode() || 'auto') === 'auto') applyTheme();
    });
  }

  document.addEventListener('click', function (e) {
    var toggle = e.target.closest('[data-theme-toggle]');
    if (toggle) {
      var mode = getStoredMode() || 'auto';
      var next = mode === 'dark' ? 'light' : 'dark';   // toggle picks explicit mode
      try { localStorage.setItem(THEME_KEY, next); } catch (err) {}
      applyTheme();
      return;
    }
    var modeBtn = e.target.closest('[data-mode]');
    if (modeBtn) {
      try { localStorage.setItem(THEME_KEY, modeBtn.getAttribute('data-mode')); } catch (err) {}
      applyTheme();
    }
  });
})();

// Custom confirmation modal (replaces the browser window.confirm popup)
(function () {
  var modal = document.createElement('div');
  modal.className = 'modal confirm-modal';
  modal.id = 'confirm-modal';
  modal.hidden = true;
  modal.innerHTML =
    '<div class="modal-box confirm-box">' +
      '<div class="confirm-icon">' +
        '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>' +
      '</div>' +
      '<h2 id="confirm-title">Are you sure?</h2>' +
      '<p id="confirm-message" class="muted"></p>' +
      '<div class="modal-actions confirm-actions">' +
        '<button type="button" class="btn" id="confirm-cancel">Cancel</button>' +
        '<button type="button" class="btn btn-danger" id="confirm-ok">Yes, Continue</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(modal);

  var pendingForm = null;
  var titleEl = document.getElementById('confirm-title');
  var msgEl = document.getElementById('confirm-message');
  var okBtn = document.getElementById('confirm-ok');
  var cancelBtn = document.getElementById('confirm-cancel');

  function showConfirm(form) {
    var msg = form.getAttribute('data-confirm') || 'Are you sure?';
    var actionInput = form.querySelector('input[name="action"]');
    var isDelete = actionInput && actionInput.value.indexOf('delete') !== -1;
    titleEl.textContent = form.getAttribute('data-confirm-title') || (isDelete ? 'Confirm Deletion' : 'Are you sure?');
    msgEl.textContent = msg;
    okBtn.textContent = form.getAttribute('data-confirm-ok') || (isDelete ? 'Delete' : 'Yes, Continue');
    okBtn.className = 'btn ' + (isDelete ? 'btn-danger' : 'btn-primary');
    pendingForm = form;
    modal.hidden = false;
  }
  function hideConfirm() {
    modal.hidden = true;
    pendingForm = null;
  }

  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      showConfirm(form);
    });
  });

  okBtn.addEventListener('click', function () {
    var form = pendingForm;
    hideConfirm();
    if (form) {
      form.removeAttribute('data-confirm');
      form.submit();
    }
  });
  cancelBtn.addEventListener('click', hideConfirm);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) hideConfirm();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) hideConfirm();
  });
})();

// Copy-to-clipboard buttons (data-copy="#selector")
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var selector = btn.getAttribute('data-copy');
    var el = document.querySelector(selector);
    if (!el) return;
    var text = el.textContent.trim();
    navigator.clipboard.writeText(text).then(function () {
      var old = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = old; }, 1500);
    }).catch(function () {});
  });
});

// Expandable error rows on the daily report
document.querySelectorAll('.btn-view').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-id');
    var row = document.getElementById('err-' + id);
    if (row) {
      row.hidden = !row.hidden;
      btn.textContent = row.hidden ? 'View Log' : 'Hide Error';
    }
  });
});

// Modals
function openModal(id) {
  var m = document.getElementById(id);
  if (m) m.hidden = false;
}
var addServerBtn = document.getElementById('btn-add-server');
if (addServerBtn) addServerBtn.addEventListener('click', function () { openModal('add-server-modal'); });
var addWebhookBtn = document.getElementById('btn-add-webhook');
if (addWebhookBtn) addWebhookBtn.addEventListener('click', function () { openModal('add-webhook-modal'); });
var addUserBtn = document.getElementById('btn-add-user');
if (addUserBtn) addUserBtn.addEventListener('click', function () { openModal('add-user-modal'); });

// Edit group modal
document.querySelectorAll('.btn-group').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var current = btn.getAttribute('data-group-name') || '';
    document.getElementById('group-id').value = btn.getAttribute('data-group-id') || '';
    var sel = document.getElementById('group-select');
    // Select current group if it exists in the list, otherwise default to "No group"
    var found = false;
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === current) { sel.selectedIndex = i; found = true; break; }
    }
    if (!found) sel.value = '';
    // Prefill new-group input with the current value so it's visible if they pick "New group"
    document.getElementById('group-new-input').value = current;
    toggleNewGroup(sel);
    openModal('group-modal');
  });
});

// Installation guide toggle
(function () {
  var btn = document.getElementById('btn-toggle-guide');
  var guide = document.getElementById('install-guide');
  if (!btn || !guide) return;
  btn.addEventListener('click', function () {
    guide.hidden = !guide.hidden;
    btn.textContent = guide.hidden ? 'Show Guide' : 'Hide Guide';
  });
})();

function toggleNewGroup(sel) {
  var showNew = sel.value === '__new';
  document.getElementById('new-group-label').hidden = !showNew;
  if (showNew) document.getElementById('group-new-input').focus();
}

// Reset password modal (users page)
document.querySelectorAll('.btn-reset').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.getElementById('reset-pwd-id').value = btn.getAttribute('data-id') || '';
    var userEl = document.getElementById('reset-pwd-user');
    if (userEl) userEl.textContent = btn.getAttribute('data-user') || '';
    openModal('reset-pwd-modal');
  });
});

document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var m = btn.closest('.modal');
    if (m) m.hidden = true;
  });
});
document.querySelectorAll('.modal').forEach(function (m) {
  m.addEventListener('click', function (e) {
    if (e.target === m) m.hidden = true;
  });
});

// Mobile hamburger menu toggle
(function () {
  var btn = document.getElementById('btn-menu');
  var menu = document.getElementById('mobile-menu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function () {
    menu.hidden = !menu.hidden;
    btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
  });
  // Close the menu after tapping a link
  menu.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      menu.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    });
  });
  // Close the menu when a tabbar item is tapped (it would be open on top)
  var tabbar = document.getElementById('tabbar');
  if (tabbar) {
    tabbar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (!menu.hidden) {
          menu.hidden = true;
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    });
  }
})();

// Auto-dismiss flash notifications
(function () {
  var FLASH_MS = 4000;      // how long the message stays before fading
  var FADE_MS = 450;        // fade-out duration
  document.querySelectorAll('.flash').forEach(function (el) {
    if (el.classList.contains('flash-persist')) return;   // important messages stay
    var ms = parseInt(el.getAttribute('data-autohide') || FLASH_MS, 10);
    setTimeout(function () {
      el.classList.add('flash-hide');
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, FADE_MS);
    }, ms);
  });
})();
