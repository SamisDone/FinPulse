/* Sixpence — progressive enhancement. Every page works without this file. */
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const THEME_KEY = 'sixpence-theme';

  /* ---- Theme: system → light → dark ----------------------------------- */
  const themeLabels = { system: 'Theme: system', light: 'Theme: light', dark: 'Theme: dark' };
  const themeIcons = {
    system: '<rect x="3.5" y="4.5" width="17" height="11.5" rx="1.5"/><path d="M9 20h6"/><path d="M12 16v4"/>',
    light: '<circle cx="12" cy="12" r="3.5"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="m5.6 5.6 1.4 1.4"/><path d="m17 17 1.4 1.4"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="m5.6 18.4 1.4-1.4"/><path d="m17 7 1.4-1.4"/>',
    dark: '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10Z"/>',
  };

  const storedTheme = () => {
    try { return localStorage.getItem(THEME_KEY) || 'system'; } catch { return 'system'; }
  };

  function applyTheme(mode) {
    const root = document.documentElement;
    if (mode === 'light' || mode === 'dark') root.dataset.theme = mode;
    else delete root.dataset.theme;
    try {
      if (mode === 'system') localStorage.removeItem(THEME_KEY);
      else localStorage.setItem(THEME_KEY, mode);
    } catch { /* storage unavailable: theme still applies for this page */ }
    syncThemeControls(mode);
    document.dispatchEvent(new CustomEvent('sixpence:themechange'));
  }

  function syncThemeControls(mode) {
    $$('[data-theme-toggle]').forEach((btn) => {
      const label = $('[data-theme-label]', btn);
      if (label) label.textContent = themeLabels[mode] || themeLabels.system;
      const svg = $('svg', btn);
      if (svg) svg.innerHTML = themeIcons[mode] || themeIcons.system;
    });
    $$('[data-theme-set]').forEach((btn) => btn.setAttribute('aria-pressed', String(btn.dataset.themeSet === mode)));
  }

  document.addEventListener('click', (event) => {
    const set = event.target.closest('[data-theme-set]');
    if (set) return applyTheme(set.dataset.themeSet);
    if (!event.target.closest('[data-theme-toggle]')) return;
    const order = ['system', 'light', 'dark'];
    applyTheme(order[(order.indexOf(storedTheme()) + 1) % order.length]);
  });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    document.dispatchEvent(new CustomEvent('sixpence:themechange'));
  });

  /* ---- Mobile navigation drawer ---------------------------------------- */
  function setNav(open) {
    const shell = $('[data-shell]');
    if (!shell) return;
    const opener = $('[data-nav-open]');
    shell.toggleAttribute('data-nav-open', open);
    opener?.setAttribute('aria-expanded', String(open));
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) $('#sidebar a, #sidebar button')?.focus();
    else opener?.focus();
  }

  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-nav-open]')) setNav(true);
    else if (event.target.closest('[data-nav-close]')) setNav(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && $('[data-shell][data-nav-open]')) setNav(false);
  });
  window.matchMedia('(min-width: 1024px)').addEventListener('change', (mq) => {
    if (mq.matches && $('[data-shell][data-nav-open]')) setNav(false);
  });

  /* ---- Toasts ------------------------------------------------------------ */
  function dismiss(toast) {
    if (!toast || toast.classList.contains('leaving')) return;
    toast.classList.add('leaving');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  }

  function scheduleToast(toast) {
    const delay = toast.dataset.type === 'error' ? 9000 : 5000;
    let timer = setTimeout(() => dismiss(toast), delay);
    toast.addEventListener('mouseenter', () => clearTimeout(timer));
    toast.addEventListener('mouseleave', () => { timer = setTimeout(() => dismiss(toast), 2500); });
  }

  window.sixpenceToast = (message, type = 'success') => {
    const host = $('[data-toasts]');
    if (!host) return;
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.dataset.type = type;
    const p = document.createElement('p');
    p.textContent = message;
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.dataset.toastClose = '';
    close.textContent = '×';
    toast.append(p, close);
    host.append(toast);
    scheduleToast(toast);
  };

  document.addEventListener('click', (event) => {
    const close = event.target.closest('[data-toast-close]');
    if (close) dismiss(close.closest('.toast'));
  });

  /* ---- Confirm before destructive submits -------------------------------- */
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('form[data-confirm]') || form.dataset.confirmed === '1') return;
    const dialog = $('#confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
      return;
    }
    event.preventDefault();
    $('[data-confirm-text]', dialog).textContent = form.dataset.confirm;
    $('#confirm-title', dialog).textContent = form.dataset.confirmTitle || 'Are you sure?';
    $('[data-confirm-button]', dialog).textContent = form.dataset.confirmLabel || 'Delete';
    dialog.returnValue = '';
    dialog.showModal();
    dialog.addEventListener('close', () => {
      if (dialog.returnValue !== 'confirm') return;
      form.dataset.confirmed = '1';
      if (typeof form.requestSubmit === 'function') form.requestSubmit(); else form.submit();
    }, { once: true });
  });

  /* ---- Disclosure panels: [data-toggle="#id"] ---------------------------- */
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-toggle]');
    if (!trigger) return;
    const target = $(trigger.dataset.toggle);
    if (!target) return;
    event.preventDefault();
    const opening = target.hidden;
    target.hidden = !opening;
    $$(`[data-toggle="${trigger.dataset.toggle}"]`).forEach((t) => t.setAttribute('aria-expanded', String(opening)));
    if (opening) {
      target.classList.add('reveal');
      target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      $('input:not([type=hidden]), select, textarea', target)?.focus({ preventScroll: true });
    }
  });

  /* ---- Password fields ---------------------------------------------------- */
  document.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-password-toggle]');
    if (!btn) return;
    const input = btn.parentElement.querySelector('input');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });

  function wirePasswordRules() {
    $$('[data-password-rules]').forEach((list) => {
      const input = $(list.dataset.passwordRules);
      if (!input) return;
      const tests = {
        length: (v) => v.length >= 8,
        upper: (v) => /[A-Z]/.test(v),
        number: (v) => /\d/.test(v),
        symbol: (v) => /[^A-Za-z0-9]/.test(v),
      };
      const update = () => $$('[data-rule]', list).forEach((li) => {
        li.classList.toggle('ok', tests[li.dataset.rule](input.value));
      });
      input.addEventListener('input', update);
      update();
    });
  }

  /* ---- Budget period presets: fill end date from start + period ---------- */
  function wireBudgetPeriods() {
    $$('[data-period-form]').forEach((form) => {
      const period = $('[name="period_type"]', form);
      const start = $('[name="start_date"]', form);
      const end = $('[name="end_date"]', form);
      if (!period || !start || !end) return;
      const pad = (n) => String(n).padStart(2, '0');
      const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      const fill = () => {
        if (!start.value || period.value === 'custom') return;
        const [y, m, d] = start.value.split('-').map(Number);
        let last;
        if (period.value === 'weekly') last = new Date(y, m - 1, d + 6);
        else if (period.value === 'monthly') last = new Date(y, m, d - 1);
        else if (period.value === 'yearly') last = new Date(y + 1, m - 1, d - 1);
        if (last) end.value = iso(last);
      };
      period.addEventListener('change', fill);
      start.addEventListener('change', fill);
      end.addEventListener('change', () => { if (period.value !== 'custom') period.value = 'custom'; });
    });
  }

  /* ---- Misc ---------------------------------------------------------------- */
  document.addEventListener('change', (event) => {
    if (event.target.matches('[data-autosubmit]')) event.target.form?.requestSubmit();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== '/' || event.metaKey || event.ctrlKey || event.altKey) return;
    if (event.target.closest('input, textarea, select, [contenteditable]')) return;
    const search = $('[data-search]');
    if (search) { event.preventDefault(); search.focus(); search.select(); }
  });

  function wireHeaderShadow() {
    const header = $('.site-header');
    if (!header) return;
    const onScroll = () => header.toggleAttribute('data-scrolled', window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* ---- Scroll reveal: raise sections into place once, the first time ----- */
  function wireScrollReveal() {
    const targets = $$('[data-reveal], [data-reveal-group]');
    if (!targets.length) return;
    if (!('IntersectionObserver' in window)) {
      targets.forEach((el) => el.classList.add('is-in'));
      return;
    }
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        io.unobserve(entry.target); // once only: scrolling back up doesn't replay it
      });
    }, { rootMargin: '0px 0px -10%' });
    targets.forEach((el) => io.observe(el));
  }

  /* Prevent double submits: disable the clicked button once the form is sent. */
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || form.method.toLowerCase() !== 'post') return;
    const button = event.submitter;
    if (button) setTimeout(() => button.setAttribute('aria-disabled', 'true'), 0);
  });

  document.addEventListener('DOMContentLoaded', () => {
    syncThemeControls(storedTheme());
    $$('.toast').forEach(scheduleToast);
    wirePasswordRules();
    wireBudgetPeriods();
    wireHeaderShadow();
    wireScrollReveal();
  });

  /* Jump straight into the amount field when arriving at #add (after the browser's own fragment scroll). */
  const focusAddForm = () => {
    if (location.hash === '#add') requestAnimationFrame(() => $('#add input:not([type=hidden])')?.focus({ preventScroll: true }));
  };
  window.addEventListener('load', focusAddForm);
  window.addEventListener('hashchange', focusAddForm);

})();
