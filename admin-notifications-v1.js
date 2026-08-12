(function () {
  'use strict';

  if (window.__pass50AdminNotificationsV1) return;
  window.__pass50AdminNotificationsV1 = true;

  const NOTIFY_KEY = 'pass50_admin_todo_notify_v1';
  const TODO_KEY = 'pass50_admin_todo_v1';
  const POLL_MS = 5 * 60 * 1000;
  let pollTimer = null;

  function isAdminUser() {
    const u = typeof currentUser === 'function' ? currentUser() : null;
    return Boolean(u && ['owner', 'admin'].includes(u.role));
  }

  function loadNotifyState() {
    try {
      return JSON.parse(localStorage.getItem(NOTIFY_KEY) || '{}') || {};
    } catch {
      return {};
    }
  }

  function saveNotifyState(state) {
    try {
      localStorage.setItem(NOTIFY_KEY, JSON.stringify(state || {}));
    } catch (_) {}
  }

  function loadDoneMap() {
    try {
      return JSON.parse(localStorage.getItem(TODO_KEY) || '{}').done || {};
    } catch {
      return {};
    }
  }

  async function syncAdminNotifyPrefs(u) {
    if (!u || u.notificationMode === 'instant') return;
    u.notificationMode = 'instant';
    if (typeof save === 'function') save();
    if (typeof CLOUD !== 'undefined' && CLOUD.token && typeof apiFetch === 'function') {
      try {
        await apiFetch('preferences.php', {
          method: 'POST',
          body: {
            favorites: u.favorites || [],
            following: u.following || [],
            followAlerts: u.followAlerts || {},
            notificationMode: 'instant'
          }
        });
      } catch (_) {}
    }
  }

  function ensureAdminNotifyPrefs() {
    if (!isAdminUser()) return;
    const u = typeof userPrefs === 'function' ? userPrefs() : null;
    if (!u) return;
    if (u.notificationMode !== 'instant') syncAdminNotifyPrefs(u);
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default') return;
    if (sessionStorage.getItem('pass50_admin_notify_asked') === '1') return;
    sessionStorage.setItem('pass50_admin_notify_asked', '1');
    if (typeof askNotifications === 'function') askNotifications();
  }

  function injectBadgeStyles() {
    if (document.getElementById('pass50AdminNotifyStyles')) return;
    const style = document.createElement('style');
    style.id = 'pass50AdminNotifyStyles';
    style.textContent = `
      .admin-menu button{position:relative}
      .de-admin-todo-badge{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#ff6b00;color:#050705;font-size:10px;font-weight:1000;line-height:18px;text-align:center;pointer-events:none}
      .notice.admin-todo .notice-action{margin-top:8px}
    `;
    document.head.appendChild(style);
  }

  function updateTodoBadge(count) {
    injectBadgeStyles();
    document.querySelectorAll('[data-admin-tab="todo"]').forEach((btn) => {
      let badge = btn.querySelector('.de-admin-todo-badge');
      if (count > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'de-admin-todo-badge';
          badge.setAttribute('aria-hidden', 'true');
          btn.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : String(count);
      } else if (badge) {
        badge.remove();
      }
    });
  }

  function notifyNewTasks(tasks, doneMap) {
    if (!isAdminUser()) return;
    const u = currentUser();
    if (!u) return;

    const state = loadNotifyState();
    state.notified = state.notified || {};

    const actionable = (tasks || []).filter(
      (t) => !doneMap?.[t.id] && ['urgent', 'must'].includes(String(t.priority || ''))
    );
    updateTodoBadge(actionable.length);

    for (const task of actionable) {
      const sig = `${task.id}|${task.meta || ''}|${task.priority || ''}`;
      if (state.notified[task.id] === sig) continue;

      const title =
        String(task.priority) === 'urgent' ? 'Urgent · A faire !' : 'A faire · Administration';
      const body = `${task.title || 'Tâche admin'}${task.meta ? ` · ${task.meta}` : ''}`;

      if (typeof addNotification === 'function') {
        addNotification(u.id, title, body, {
          kind: 'admin_todo',
          adminTab: task.openTab || 'todo',
          todoId: task.id
        });
      }
      state.notified[task.id] = sig;
    }

    const activeIds = new Set(actionable.map((t) => t.id));
    Object.keys(state.notified).forEach((id) => {
      if (!activeIds.has(id)) delete state.notified[id];
    });

    saveNotifyState(state);
  }

  async function pollAdminTodo() {
    if (!isAdminUser()) return;
    if (typeof window.p50CollectAdminTodoTasks !== 'function') return;
    try {
      const tasks = await window.p50CollectAdminTodoTasks();
      notifyNewTasks(tasks, loadDoneMap());
    } catch (err) {
      console.warn('PASS50 admin todo notify', err);
    }
  }

  function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
    if (!isAdminUser()) return;
    ensureAdminNotifyPrefs();
    pollAdminTodo();
    pollTimer = setInterval(pollAdminTodo, POLL_MS);
  }

  window.p50AdminNotifyAfterTodoLoad = notifyNewTasks;
  window.p50AdminNotificationsStart = startPolling;

  function boot() {
    injectBadgeStyles();
    const tick = () => {
      if (typeof currentUser !== 'function') return;
      if (isAdminUser()) startPolling();
      else if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
        updateTodoBadge(0);
      }
    };
    tick();
    const readyTimer = setInterval(() => {
      if (!window.__pass50CloudReady && typeof currentUser !== 'function') return;
      tick();
      if (isAdminUser()) clearInterval(readyTimer);
    }, 1000);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && isAdminUser()) pollAdminTodo();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
