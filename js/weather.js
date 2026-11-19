(function () {
    'use strict';

    const section = document.querySelector('[data-weather]');
    if (!section) {
        return;
    }

    const fields = Object.fromEntries(
        Array.from(section.querySelectorAll('[data-weather-field]'), (element) => [
            element.dataset.weatherField,
            element
        ])
    );
    const localTime = new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Lisbon',
        hour: '2-digit',
        minute: '2-digit'
    });
    const localDate = new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Lisbon',
        day: 'numeric',
        month: 'short'
    });
    const forecastDate = new Intl.DateTimeFormat('de-DE', {
        timeZone: 'Europe/Lisbon',
        weekday: 'short',
        day: 'numeric',
        month: 'short'
    });
    const temperature = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 });
    const conditions = {
        clearsky: ['Klar', 'sun'],
        fair: ['Leicht bewölkt', 'cloud-sun'],
        partlycloudy: ['Teilweise bewölkt', 'cloud-sun'],
        cloudy: ['Bewölkt', 'cloud'],
        fog: ['Nebel', 'cloud-fog'],
        rain: ['Regen', 'cloud-rain'],
        lightrain: ['Leichter Regen', 'cloud-drizzle'],
        heavyrain: ['Starker Regen', 'cloud-rain'],
        rainshowers: ['Regenschauer', 'cloud-rain'],
        lightrainshowers: ['Leichte Regenschauer', 'cloud-drizzle'],
        heavyrainshowers: ['Starke Regenschauer', 'cloud-rain']
    };

    function describe(symbol) {
        if (typeof symbol !== 'string') {
            return ['Wetterlage nicht verfügbar', null];
        }

        const [code, light] = symbol.split('_');
        if (code.endsWith('andthunder')) {
            return ['Gewitter', 'cloud-lightning-rain'];
        }
        if (code.includes('sleet')) {
            return ['Schneeregen', 'cloud-sleet'];
        }
        if (code.includes('snow')) {
            return ['Schnee', 'cloud-snow'];
        }

        const [label, icon] = Object.hasOwn(conditions, code)
            ? conditions[code]
            : ['Wetterlage nicht verfügbar', null];
        if (light === 'night' || light === 'polartwilight') {
            if (icon === 'sun') {
                return [label, 'moon-stars'];
            }
            if (icon === 'cloud-sun') {
                return [label, 'cloud-moon'];
            }
        }
        return [label, icon];
    }

    function formatTime(value, formatter) {
        if (typeof value !== 'string') {
            return null;
        }
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? null : formatter.format(date);
    }

    function degrees(value) {
        return Number.isFinite(value) ? temperature.format(value) + '\u00a0°C' : null;
    }

    function rounded(value, unit) {
        return Number.isFinite(value) ? Math.round(value) + '\u00a0' + unit : null;
    }

    function updateClock() {
        const now = new Date();
        fields.clock.dateTime = now.toISOString();
        fields.clock.textContent = localTime.format(now) + ' Uhr';
        fields.clock.closest('.weather-clock').hidden = false;
    }

    function dayCard(day) {
        const date = typeof day?.date === 'string' ? day.date : '';
        const label = formatTime(date + 'T12:00:00Z', forecastDate);
        const min = degrees(day?.min);
        const max = degrees(day?.max);
        if (!label || min === null || max === null) {
            return null;
        }

        const [description, icon] = describe(day.symbol);
        const item = document.createElement('li');
        const heading = document.createElement('time');
        heading.dateTime = date;
        heading.textContent = label;
        const range = document.createElement('span');
        range.className = 'weather-day-temperature';
        range.textContent = min + ' bis ' + max;
        const condition = document.createElement('span');
        condition.className = 'weather-day-condition';
        if (icon) {
            const symbol = document.createElement('i');
            symbol.className = 'bi bi-' + icon;
            symbol.setAttribute('aria-hidden', 'true');
            condition.append(symbol);
        }
        condition.append(description);
        item.append(heading, range, condition);
        return item;
    }

    function render({ current, forecast, sun }) {
        const air = degrees(current?.temperature);
        const [condition, icon] = describe(current?.symbol);
        fields.current.hidden = air === null && !icon;
        fields.temperature.hidden = air === null;
        fields.temperature.textContent = air;
        fields.condition.textContent = condition;
        fields.icon.hidden = !icon;
        fields.icon.className = 'weather-current-icon' + (icon ? ' bi bi-' + icon : '');
        const date = formatTime(current?.time, localDate);
        const time = formatTime(current?.time, localTime);
        fields.valid.textContent = date && time ? 'Vorhersage für ' + date + ', ' + time + ' Uhr' : '';

        const metrics = {
            wind: rounded(current?.wind, 'km/h'),
            humidity: rounded(current?.humidity, '%'),
            rise: formatTime(sun?.rise, localTime),
            set: formatTime(sun?.set, localTime)
        };

        for (const [field, value] of Object.entries(metrics)) {
            fields[field].textContent = value ?? 'Nicht verfügbar';
        }
        fields.overview.hidden = fields.current.hidden && Object.values(metrics).every((value) => value === null);

        const days = Array.isArray(forecast) ? forecast.slice(0, 3).map(dayCard).filter(Boolean) : [];
        fields.days.replaceChildren(...days);
        fields.forecast.hidden = days.length === 0;

        if (fields.overview.hidden && fields.forecast.hidden) {
            throw new Error('No weather data available');
        }
        const incomplete =
            fields.current.hidden || air === null || !icon || days.length < 3 ||
            Object.values(metrics).some((value) => value === null);
        fields.status.textContent = incomplete ? 'Einige Wetterdaten sind derzeit nicht verfügbar.' : '';
        fields.retry.hidden = !incomplete;
    }

    async function loadWeather(retry = false) {
        fields.retry.hidden = true;
        fields.status.textContent = 'Wetterdaten werden geladen …';
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch('api/weather.php', {
                signal: controller.signal,
                cache: retry ? 'reload' : 'default'
            });
            if (!response.ok) {
                throw new Error('Weather request failed');
            }
            render(await response.json());
        } catch {
            fields.status.textContent =
                fields.overview.hidden && fields.forecast.hidden
                    ? 'Wetterdaten sind derzeit nicht verfügbar.'
                    : 'Die vorhandenen Wetterdaten konnten nicht aktualisiert werden.';
            fields.retry.hidden = false;
        } finally {
            window.clearTimeout(timeout);
        }
    }

    updateClock();
    window.setInterval(updateClock, 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            updateClock();
        }
    });
    fields.retry.addEventListener('click', () => loadWeather(true));

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                observer.disconnect();
                loadWeather();
            }
        }, { rootMargin: '250px' });
        observer.observe(section);
    } else {
        loadWeather();
    }
})();
