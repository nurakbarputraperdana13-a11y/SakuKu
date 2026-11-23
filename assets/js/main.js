(() => {
  'use strict';

  const focusableSelector = 'a[href], button:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const initialFocusSelector = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])';
  let lastModalTrigger = null;

  const openModal = (modal, trigger) => {
    if (!modal) return;
    lastModalTrigger = trigger || lastModalTrigger;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    (modal.querySelector('[autofocus]') || modal.querySelector(initialFocusSelector) || modal.querySelector(focusableSelector))?.focus();
  };

  const closeModal = (modal) => {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastModalTrigger && document.contains(lastModalTrigger)) lastModalTrigger.focus();
    lastModalTrigger = null;
  };

  document.querySelectorAll('[data-modal-open]').forEach((button) => {
    button.addEventListener('click', () => openModal(document.getElementById(button.dataset.modalOpen), button));
  });

  document.querySelectorAll('[data-modal-close]').forEach((button) => {
    button.addEventListener('click', () => closeModal(button.closest('.modal')));
  });

  document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
    backdrop.addEventListener('click', () => closeModal(backdrop.closest('.modal')));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const openModalEl = document.querySelector('.modal.is-open');
      if (openModalEl) closeModal(openModalEl);
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
