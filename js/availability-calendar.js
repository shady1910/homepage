(() => {
  'use strict';
  const form = document.getElementById('contactForm');
  const section = document.getElementById('availabilityCalendar');
  if (!form || !section) return;
  const input = document.getElementById('travelRange');
  const arrival = document.getElementById('arrival');
  const departure = document.getElementById('departure');
  const status = document.getElementById('calendarStatus');
  const reset = document.getElementById('resetTravelRange');
  const retry = document.getElementById('retryAvailability');
  let picker;
  let bookings = [];
  let ready = false;
  let pendingStart = null;
  let today;
  const iso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const parseISO = (value) => {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const date = new Date(`${value}T12:00:00`);
    return !Number.isNaN(date.getTime()) && iso(date) === value ? date : null;
  };
  const overlaps = (from, to, row) => from < row.to && to > row.from;
  const occupied = (day) => bookings.some((row) => row.from <= day && day < row.to);
  const validRange = (from, to) => from >= today && from < to && !bookings.some((row) => overlaps(from, to, row));
  const disabled = (date) => {
    const day = iso(date);
    if (!ready || day < today) return true;
    // A booking's first night can be the preceding guest's departure day.
    if (pendingStart) return day !== pendingStart && !validRange(pendingStart, day);
    return occupied(day);
  };
  const clearValues = () => {
    arrival.value = '';
    departure.value = '';
    pendingStart = null;
  };
  const monthCount = () => section.clientWidth >= 640 ? 2 : 1;
  const describeDay = (_dates, _text, instance, day) => {
    const value = iso(day.dateObj);
    const blocked = occupied(value);
    const departureOnly = blocked && pendingStart && validRange(pendingStart, value);
    const selectedDeparture = departure.value === value;
    day.classList.toggle('occupied-night', blocked);
    day.classList.toggle('departure-only', Boolean(departureOnly));
    day.classList.toggle('selected-departure', selectedDeparture);
    const label = selectedDeparture ? 'ausgewählte Abreise' + (blocked ? '; Nacht belegt' : '') : value < today ? 'vergangen' : departureOnly ? 'nur als Abreise verfügbar; Nacht belegt' : blocked ? 'belegt' : 'verfügbar';
    day.setAttribute('aria-label', `${instance.formatDate(day.dateObj, 'd. F Y')}, ${label}`);
    day.setAttribute('aria-disabled', String(day.classList.contains('flatpickr-disabled')));
    day.title = label;
  };
  const calendarNavigation = (_dates, _text, instance) => {
    [[instance.prevMonthNav, 'Vorheriger Monat'], [instance.nextMonthNav, 'Nächster Monat']].forEach(([button, label]) => {
      button.setAttribute('role', 'button');
      button.setAttribute('aria-label', label);
      button.tabIndex = 0;
      button.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); button.click(); }
      });
    });
    instance.calendarContainer.setAttribute('aria-label', 'Belegungskalender');
  };
  const load = async () => {
    ready = false;
    clearValues();
    if (picker) { picker.destroy(); picker = null; }
    input.value = '';
    input.disabled = true;
    reset.disabled = true;
    retry.hidden = true;
    status.textContent = 'Verfügbarkeit wird geladen …';
    section.setAttribute('aria-busy', 'true');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      if (typeof window.flatpickr !== 'function' || !window.flatpickr.l10ns.de) throw new Error('Calendar unavailable');
      const response = await fetch('/api/availability.php', { cache: 'no-store', headers: { Accept: 'application/json' }, signal: controller.signal });
      const data = await response.json();
      today = response.headers.get('X-Availability-Today');
      if (!response.ok || data.success !== true || !parseISO(today) || !Array.isArray(data.bookings)
        || data.bookings.some((row) => !row || !parseISO(row.from) || !parseISO(row.to) || row.from >= row.to)) throw new Error('Invalid availability');
      bookings = data.bookings;
      ready = true;
      input.disabled = false;
      reset.disabled = false;
      picker = window.flatpickr(input, {
        mode: 'range', inline: true, disableMobile: true, allowInput: false,
        locale: { ...window.flatpickr.l10ns.de, rangeSeparator: ' – ' },
        dateFormat: 'd.m.Y', ariaDateFormat: 'd. F Y', minDate: parseISO(today),
        showMonths: monthCount(), disable: [disabled],
        onDayCreate: describeDay, onReady: calendarNavigation,
        onChange(dates, text, instance) {
          clearValues();
          if (dates.length === 1) {
            pendingStart = iso(dates[0]);
            status.textContent = `Anreise: ${instance.formatDate(dates[0], 'd.m.Y')}. Bitte wählen Sie jetzt Ihre Abreise.`;
          } else if (dates.length === 2 && validRange(iso(dates[0]), iso(dates[1]))) {
            arrival.value = iso(dates[0]);
            departure.value = iso(dates[1]);
            status.textContent = `Ihr Reisezeitraum: ${text}. Ihre Anfrage bleibt unverbindlich.`;
          } else {
            instance.clear(false);
            status.textContent = 'Bitte wählen Sie zuerst Ihre Anreise und dann Ihre Abreise.';
          }
          instance.redraw();
        }
      });
      status.textContent = 'Bitte wählen Sie zuerst Ihre Anreise und dann Ihre Abreise.';
    } catch (_) {
      ready = false;
      clearValues();
      if (picker) { picker.destroy(); picker = null; }
      input.disabled = true;
      reset.disabled = true;
      retry.hidden = false;
      status.textContent = 'Die Verfügbarkeit kann derzeit nicht geladen werden. Bitte versuchen Sie es später erneut.';
    } finally {
      clearTimeout(timeout);
      section.removeAttribute('aria-busy');
    }
  };
  form.availabilityCalendar = {
    validate() {
      if (ready && arrival.value && departure.value && validRange(arrival.value, departure.value)) return true;
      status.textContent = ready ? 'Bitte wählen Sie einen vollständigen verfügbaren Reisezeitraum.' : 'Die Verfügbarkeit kann derzeit nicht geladen werden. Bitte versuchen Sie es später erneut.';
      status.focus();
      return false;
    },
    reload: load
  };
  reset.addEventListener('click', () => picker?.clear());
  retry.addEventListener('click', load);
  form.addEventListener('reset', () => picker?.clear());
  if (window.ResizeObserver) new ResizeObserver(() => {
    if (picker && picker.config.showMonths !== monthCount()) picker.set('showMonths', monthCount());
  }).observe(section);
  load();
})();
