(() => {
  'use strict';

  const root = document.documentElement;
  const savedTheme = localStorage.getItem('sakuku-theme');
  if (savedTheme === 'light' || savedTheme === 'dark') {
    root.dataset.theme = savedTheme;
  }

  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.theme = next;
      localStorage.setItem('sakuku-theme', next);
      window.dispatchEvent(new CustomEvent('sakuku:themechange', { detail: { theme: next } }));
    });
  });

  const sidebar = document.getElementById('sidebar');
  const backdrop = document.querySelector('[data-menu-close]');
  const menuButton = document.querySelector('[data-menu-toggle]');

  const setMenu = (open) => {
    if (!sidebar) return;
    sidebar.classList.toggle('is-open', open);
    if (backdrop) backdrop.hidden = !open;
    if (menuButton) menuButton.setAttribute('aria-expanded', String(open));
  };

  menuButton?.addEventListener('click', () => setMenu(!sidebar?.classList.contains('is-open')));
  backdrop?.addEventListener('click', () => setMenu(false));

  const focusableSelector = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const initialFocusSelector = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';
  let lastModalTrigger = null;

  const openModal = (modal, trigger) => {
    if (!modal) return;
    lastModalTrigger = trigger || lastModalTrigger;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    (modal.querySelector('[autofocus]') || modal.querySelector(initialFocusSelector) || modal.querySelector(focusableSelector))?.focus();
  };

  const closeModal = (modal) => {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (lastModalTrigger && document.contains(lastModalTrigger)) lastModalTrigger.focus();
    lastModalTrigger = null;
  };

  document.querySelectorAll('[data-modal-open]').forEach((button) => {
    button.addEventListener('click', () => openModal(document.getElementById(button.dataset.modalOpen), button));
  });

  document.querySelectorAll('[data-modal-close]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.closest('.modal')));
  });

  document.querySelectorAll('.modal-backdrop').forEach((backdropEl) => {
    backdropEl.addEventListener('click', () => closeModal(backdropEl.closest('.modal')));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const openModalEl = document.querySelector('.modal.is-open');
      if (openModalEl) closeModal(openModalEl);
      else setMenu(false);
      return;
    }

    if (event.key !== 'Tab') return;
    const openModalEl = document.querySelector('.modal.is-open');
    if (!openModalEl) return;
    const focusables = Array.from(openModalEl.querySelectorAll(focusableSelector))
      .filter((element) => element.offsetParent !== null);
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  document.querySelectorAll('[data-flash-close]').forEach((button) => {
    button.addEventListener('click', () => button.closest('.flash')?.remove());
  });

  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.dataset.confirm || 'Yakin lanjutkan aksi ini?';
      if (!window.confirm(message)) event.preventDefault();
    });
  });

  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      const value = button.dataset.copy || '';
      try {
        await navigator.clipboard.writeText(value);
        const original = button.textContent;
        button.textContent = 'Tersalin';
        window.setTimeout(() => { button.textContent = original; }, 1400);
      } catch (error) {
        window.prompt('Salin kode undangan:', value);
      }
    });
  });
})();
