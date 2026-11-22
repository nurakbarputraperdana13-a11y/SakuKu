(() => {
  'use strict';

  const forms = document.querySelectorAll('[data-split-form]');
  if (!forms.length) return;

  const toInt = (value) => {
    const text = String(value ?? '').trim();
    if (text === '' || /[^0-9.,\s]/.test(text)) return 0;
    const digits = text.replace(/\D/g, '');
    return digits === '' ? 0 : Number.parseInt(digits, 10);
  };

  const formatRupiah = (amount) => `Rp${Math.max(0, amount).toLocaleString('id-ID')}`;

  const readInitialAmounts = (form) => {
    const values = new Map();
    const raw = form.dataset.initialAmounts || '';
    if (!raw) return values;
    try {
      const parsed = JSON.parse(raw);
      Object.keys(parsed).forEach((key) => values.set(key, toInt(parsed[key])));
    } catch (error) {
      return new Map();
    }
    return values;
  };

  forms.forEach((form) => {
    const totalInput = form.querySelector('[data-split-total]');
    const preview = form.querySelector('[data-split-preview]');
    const warning = form.querySelector('[data-split-warning]');
    const submitButton = form.querySelector('[data-split-submit]');
    const participants = Array.from(form.querySelectorAll('[data-participant]'));

    if (!totalInput || !preview || !participants.length) return;

    const amountValues = readInitialAmounts(form);

    const applyValidation = (selected, total, mode, exactSum) => {
      const issues = [];
      if (selected.length === 0) issues.push('Pilih minimal satu peserta.');
      if (total <= 0) issues.push('Total harus lebih dari Rp0.');
      if (mode === 'exact' && selected.length > 0 && total > 0) {
        if (exactSum !== total) {
          issues.push(
            `Jumlah nominal peserta (${formatRupiah(exactSum)}) harus sama dengan total (${formatRupiah(total)}).`
          );
        }
        const invalidAmounts = Array.from(preview.querySelectorAll('[data-exact-amount]'))
          .filter((input) => toInt(input.value) <= 0).length;
        if (invalidAmounts > 0) issues.push('Setiap peserta harus memiliki nominal lebih dari Rp0.');
      }

      if (warning) {
        warning.style.display = issues.length === 0 ? 'none' : '';
        warning.textContent = issues.join(' ');
      }
      if (submitButton) submitButton.disabled = issues.length > 0;
    };

    const currentMode = () => {
      const modeInput = form.querySelector('input[name="mode"]:checked');
      return modeInput ? modeInput.value : 'equal';
    };

    const previewState = () => {
      const selected = participants.filter((box) => box.checked);
      let exactSum = 0;
      preview.querySelectorAll('[data-exact-amount]').forEach((input) => {
        exactSum += toInt(input.value);
      });
      return { selected, total: toInt(totalInput.value), mode: currentMode(), exactSum };
    };

    const render = () => {
      preview.querySelectorAll('[data-exact-amount]').forEach((input) => {
        amountValues.set(input.dataset.exactAmount, input.value);
      });

      const selected = participants.filter((box) => box.checked);
      const total = toInt(totalInput.value);
      const mode = currentMode();
      const base = selected.length > 0 ? Math.floor(total / selected.length) : 0;
      const remainder = selected.length > 0 ? total % selected.length : 0;
      let exactSum = 0;

      preview.textContent = '';

      selected.forEach((box, index) => {
        const uid = String(box.value);
        const name = box.dataset.name || uid;
        const evenAmount = base + (index < remainder ? 1 : 0);

        const row = document.createElement('div');
        row.className = 'split-row';

        const person = document.createElement('span');
        person.className = 'split-person';
        const avatar = document.createElement('span');
        avatar.className = 'avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = (name.trim().charAt(0) || '?').toUpperCase();
        const label = document.createElement('strong');
        label.textContent = name;
        person.append(avatar, label);
        row.appendChild(person);

        let shownAmount = evenAmount;

        if (mode === 'exact') {
          const field = document.createElement('label');
          field.className = 'field';

          const input = document.createElement('input');
          input.type = 'text';
          input.inputMode = 'numeric';
          input.name = `amounts[${uid}]`;
          input.placeholder = '0';
          input.dataset.exactAmount = uid;
          input.value = amountValues.has(uid) ? String(amountValues.get(uid)) : String(evenAmount);
          shownAmount = toInt(input.value);

          field.appendChild(input);
          row.appendChild(field);
        } else {
          const hint = document.createElement('span');
          hint.className = 'field-hint';
          hint.textContent = index < remainder ? 'Termasuk sisa Rp1' : '';
          row.appendChild(hint);
        }

        const amount = document.createElement('span');
        amount.className = 'split-amount';
        amount.textContent = formatRupiah(shownAmount);
        row.appendChild(amount);

        preview.appendChild(row);
        exactSum += shownAmount;
      });

      applyValidation(selected, total, mode, exactSum);
    };

    form.addEventListener('change', (event) => {
      if (event.target && event.target.dataset.exactAmount !== undefined) return;
      render();
    });

    form.addEventListener('input', (event) => {
      const target = event.target;
      if (!target) return;

      if (target === totalInput) {
        render();
        return;
      }

      if (target.dataset.exactAmount !== undefined) {
        amountValues.set(target.dataset.exactAmount, target.value);
        const row = target.closest('.split-row');
        const amount = row ? row.querySelector('.split-amount') : null;
        if (amount) amount.textContent = formatRupiah(toInt(target.value));
        const state = previewState();
        applyValidation(state.selected, state.total, state.mode, state.exactSum);
      }
    });

    render();
  });
})();
