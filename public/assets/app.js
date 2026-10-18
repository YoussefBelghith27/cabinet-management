// No inline scripts anywhere (strict Content-Security-Policy): all behaviour is wired here.
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  // Mobile sidebar
  document.querySelector('[data-toggle="sidebar"]')?.addEventListener('click', () => {
    document.getElementById('sidebar')?.classList.toggle('open');
  });

  // Confirmation before destructive actions
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  // Auto-submit filters
  document.querySelectorAll("select[data-autosubmit]").forEach((s) => s.addEventListener("change", () => s.form.submit()));

  // Print button
  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

  // Agenda blocks & chart bars: geometry passed via data-* attributes (CSSOM is allowed by CSP).
  document.querySelectorAll('[data-top]').forEach((el) => {
    el.style.top = el.dataset.top + 'px';
    if (el.dataset.height) el.style.height = el.dataset.height + 'px';
  });
  // Simultaneous appointments share the column side by side.
  document.querySelectorAll('[data-lanes]').forEach((el) => {
    const lanes = Math.max(1, Number(el.dataset.lanes));
    if (lanes === 1) return;
    const lane = Number(el.dataset.lane);
    el.style.left = `calc(${(lane / lanes) * 100}% + 2px)`;
    el.style.width = `calc(${100 / lanes}% - 4px)`;
    el.style.right = 'auto';
  });
  document.querySelectorAll('[data-col-height]').forEach((el) => { el.style.height = el.dataset.colHeight + 'px'; });
  document.querySelectorAll('[data-pct]').forEach((el) => { el.style.height = Math.max(1, Number(el.dataset.pct)) + '%'; });

  // ---------------------------------------------------------------- motion
  const motionOK = window.matchMedia('(prefers-reduced-motion: no-preference)').matches;
  const stagger = (nodes, max = 40) => nodes.forEach((el, i) => el.style.setProperty('--i', String(Math.min(i, max))));

  // Agenda: appointments arrive day by day, in time order (DOM order is already chronological).
  stagger([...document.querySelectorAll('.agenda-col .appt')]);
  stagger([...document.querySelectorAll('.demo-appt')].sort((a, b) => a.dataset.seq - b.dataset.seq));
  stagger([...document.querySelectorAll('.bar-fill')]);
  stagger([...document.querySelectorAll('.reveal')], 12);
  stagger([...document.querySelectorAll('.rdv-table tbody tr')], 20);
  document.querySelectorAll('.side-list, .slot-grid').forEach((list) => stagger([...list.children], 12));

  // Progress bars (width via CSSOM: allowed by the CSP).
  document.querySelectorAll('[data-progress]').forEach((el) => {
    el.style.width = Math.max(0, Math.min(100, Number(el.dataset.progress))) + '%';
  });

  // Key figures count up once, so the eye lands on them (the final value is already in the HTML).
  if (motionOK) {
    document.querySelectorAll('[data-count]').forEach((el, i) => {
      const target = Number(el.dataset.count);
      if (!Number.isFinite(target) || target <= 0) return;
      const delay = 150 + i * 80;
      const duration = 700;
      el.textContent = '0';
      const start = performance.now() + delay;
      const tick = (now) => {
        const t = Math.min(1, Math.max(0, (now - start) / duration));
        el.textContent = String(Math.round(target * (1 - Math.pow(1 - t, 3))));
        if (t < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }

  // Dashboard: filter today's appointments by practitioner.
  document.querySelectorAll('[data-filter-group]').forEach((group) => {
    const table = group.closest('section')?.querySelector('tbody');
    group.addEventListener('click', (e) => {
      const chip = e.target.closest('[data-filter]');
      if (!chip || !table) return;
      group.querySelectorAll('[data-filter]').forEach((c) => {
        c.classList.toggle('is-on', c === chip);
        c.setAttribute('aria-pressed', c === chip ? 'true' : 'false');
      });
      table.querySelectorAll('tr').forEach((row) => {
        row.hidden = chip.dataset.filter !== 'all' && row.dataset.rowFilter !== chip.dataset.filter;
      });
    });
  });

  // Agenda: red line at the current time in today's column, kept up to date every minute.
  const agenda = document.querySelector('[data-agenda]');
  const todayCol = agenda?.querySelector('.agenda-col.is-today');
  if (agenda && todayCol) {
    const startHour = Number(agenda.dataset.startHour);
    const endHour = Number(agenda.dataset.endHour);
    const pxPerMinute = Number(agenda.dataset.pxPerMinute);
    const line = document.createElement('div');
    line.className = 'now-line';
    line.setAttribute('aria-hidden', 'true');
    const place = () => {
      const now = new Date();
      const minutes = (now.getHours() - startHour) * 60 + now.getMinutes();
      line.hidden = minutes < 0 || minutes > (endHour - startHour) * 60;
      line.style.top = minutes * pxPerMinute + 'px';
    };
    place();
    todayCol.appendChild(line);
    window.setInterval(place, 60000);
  }

  // Success messages close themselves after the countdown bar runs out (hover pauses it).
  if (motionOK) {
    document.querySelectorAll('.flash-success').forEach((flash) => {
      flash.dataset.autodismiss = '';
      flash.addEventListener('animationend', (e) => {
        if (e.animationName === 'countdown') flash.classList.add('is-leaving');
        else if (e.animationName === 'flash-out') flash.remove();
      });
    });
  }

  // Submitting a form: the clicked button shows it is working, and a double click cannot submit twice.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || form.method.toLowerCase() !== 'post') return;
    if (form.dataset.submitting) {
      e.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    const button = e.submitter || form.querySelector('button[type="submit"], button:not([type])');
    button?.setAttribute('aria-busy', 'true');
  });
  // Back/forward cache: restore buttons if the page is shown again.
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('[aria-busy="true"]').forEach((b) => b.removeAttribute('aria-busy'));
    document.querySelectorAll('form[data-submitting]').forEach((f) => delete f.dataset.submitting);
  });

  // Appointment form: picking a treatment pre-fills the duration
  const treatmentSelect = document.querySelector('select[name="treatment_id"][data-durations]');
  if (treatmentSelect) {
    const durations = JSON.parse(treatmentSelect.dataset.durations || '{}');
    treatmentSelect.addEventListener('change', () => {
      const d = durations[treatmentSelect.value];
      const input = document.querySelector('input[name="duration"]');
      if (d && input) input.value = d;
    });
  }

  // Invoice form: dynamic lines + live total (the server recomputes everything anyway)
  const invoiceForm = document.querySelector('[data-invoice-form]');
  if (invoiceForm) {
    const body = invoiceForm.querySelector('tbody');
    const template = invoiceForm.querySelector('template');
    const totalEl = invoiceForm.querySelector('[data-total]');
    const decimals = Number(invoiceForm.dataset.decimals || 2);
    const catalog = JSON.parse(invoiceForm.dataset.catalog || '{}');

    const recompute = () => {
      let total = 0;
      body.querySelectorAll('tr').forEach((row) => {
        const q = parseFloat(row.querySelector('[name="item_quantity[]"]').value) || 0;
        const p = parseFloat(String(row.querySelector('[name="item_price[]"]').value).replace(',', '.')) || 0;
        const line = q * p;
        row.querySelector('[data-line-total]').textContent = line.toFixed(decimals);
        total += line;
      });
      const text = total.toFixed(decimals);
      if (totalEl.textContent !== text) {
        totalEl.textContent = text;
        totalEl.classList.remove('bump');
        void totalEl.offsetWidth; // restart the animation
        totalEl.classList.add('bump');
      }
    };
    const addRow = (animate = true) => {
      const row = template.content.firstElementChild.cloneNode(true);
      if (animate) row.classList.add('row-in');
      body.appendChild(row);
      recompute();
      if (animate) row.querySelector('select, input')?.focus();
    };
    invoiceForm.querySelector('[data-add-line]').addEventListener('click', () => addRow());
    body.addEventListener('input', recompute);
    body.addEventListener('click', (e) => {
      if (e.target.matches('[data-remove-line]')) {
        const row = e.target.closest('tr');
        const remove = () => { row.remove(); recompute(); };
        if (!motionOK) return remove();
        row.classList.add('row-out');
        row.addEventListener('animationend', remove, { once: true });
      }
    });
    body.addEventListener('change', (e) => {
      if (e.target.matches('[data-catalog]')) {
        const item = catalog[e.target.value];
        const row = e.target.closest('tr');
        if (item) {
          row.querySelector('[name="item_description[]"]').value = item.name;
          row.querySelector('[name="item_price[]"]').value = item.price;
          recompute();
        }
      }
    });
    if (!body.querySelector('tr')) addRow(false);
  }

  // Patient portal: load free slots for the selected day
  const booking = document.querySelector('[data-booking]');
  if (booking) {
    const dateInput = booking.querySelector('input[name="date"]');
    const timeInput = booking.querySelector('input[name="time"]');
    const list = booking.querySelector('[data-slots]');
    const submit = booking.querySelector('button[type="submit"]');
    const load = async () => {
      timeInput.value = '';
      submit.disabled = true;
      list.textContent = 'Chargement…';
      try {
        const res = await fetch(booking.dataset.slotsUrl + '?date=' + encodeURIComponent(dateInput.value), {
          headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
          credentials: 'same-origin',
        });
        const json = await res.json();
        list.textContent = '';
        if (!res.ok) throw new Error(json.error || 'Erreur');
        if (!json.slots.length) {
          list.textContent = 'Aucun créneau disponible ce jour-là.';
          return;
        }
        json.slots.forEach((slot, i) => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = 'slot';
          b.style.setProperty('--i', String(Math.min(i, 30)));
          b.textContent = slot; // textContent: never innerHTML with server data
          b.setAttribute('aria-pressed', 'false');
          b.addEventListener('click', () => {
            list.querySelectorAll('.slot').forEach((s) => s.setAttribute('aria-pressed', 'false'));
            b.setAttribute('aria-pressed', 'true');
            timeInput.value = slot;
            submit.disabled = false;
          });
          list.appendChild(b);
        });
      } catch (err) {
        list.textContent = 'Impossible de charger les créneaux : ' + err.message;
      }
    };
    dateInput.addEventListener('change', load);
    if (dateInput.value) load();
  }
})();
