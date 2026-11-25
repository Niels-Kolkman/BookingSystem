/*
 * Booking page (ISSUE-15, resources/views/bookings/create.blade.php).
 *
 * The page works without this script: every step goes back to the server.
 * With it, the steps open one after the other on the page:
 * - picking a service shows its employees (from the embedded catalogue);
 * - picking an employee or a date fetches the free times live from
 *   GET /bookings/slots; an older request still running is cancelled;
 * - the booking is posted as JSON. When the time was just taken (409) the
 *   message is shown and the free times are fetched again, so the customer
 *   can pick another one straight away.
 */

const MESSAGES = {
    loading: 'Vrije tijden laden…',
    none: 'Er zijn geen vrije tijden op deze dag. Kies een andere datum.',
    loadFailed: 'De vrije tijden konden niet worden geladen. Probeer het opnieuw.',
    noTime: 'Kies een tijdstip.',
    bookFailed: 'Het boeken is niet gelukt. Controleer je verbinding en probeer het opnieuw.',
    sessionExpired: 'Je sessie is verlopen. Ververs de pagina en probeer het opnieuw.',
};

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

export function initBookingFlow(root) {
    const choose = root.querySelector('[data-booking-choose]');
    const book = root.querySelector('[data-booking-book]');
    const employeeStep = root.querySelector('[data-booking-step="employee"]');
    const employeeList = root.querySelector('[data-booking-employees]');
    const dateStep = root.querySelector('[data-booking-step="date"]');
    const dateInput = choose.elements.namedItem('date');
    const slotList = root.querySelector('[data-booking-slots]');
    const status = root.querySelector('[data-booking-status]');
    const errorBox = root.querySelector('[data-booking-error]');
    const submit = root.querySelector('[data-booking-submit]');
    const catalogue = JSON.parse(root.querySelector('[data-booking-catalogue]').textContent);

    let pending = null;

    root.querySelector('[data-booking-next]').hidden = true;
    choose.addEventListener('submit', (event) => event.preventDefault());

    const checked = (name) => choose.querySelector(`input[name="${name}"]:checked`)?.value ?? '';

    function showError(messages) {
        errorBox.replaceChildren(...messages.map((text) => {
            const p = document.createElement('p');
            p.textContent = text;
            return p;
        }));
        errorBox.hidden = messages.length === 0;
    }

    function option(className, name, value, text, inputClass = '') {
        const label = document.createElement('label');
        label.className = className;
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = name;
        input.value = value;
        input.className = inputClass;
        const span = document.createElement('span');
        span.textContent = text;
        label.append(input, span);
        return label;
    }

    function renderEmployees(serviceId) {
        const service = catalogue.find((s) => String(s.id) === serviceId);
        const employees = service ? service.employees : [];
        employeeList.replaceChildren(...employees.map((e) =>
            option(employeeList.dataset.optionClass, 'employee_id', String(e.id), e.name)));
        // Only one employee: nothing to choose, go on to the date.
        if (employees.length === 1) employeeList.querySelector('input').checked = true;
    }

    function renderSlots(slots) {
        slotList.replaceChildren(...slots.map((slot) =>
            option(slotList.dataset.optionClass, 'starts_at', slot.starts_at, slot.label, 'sr-only')));
        status.textContent = slots.length === 0 ? MESSAGES.none : '';
    }

    async function loadSlots() {
        pending?.abort();
        const serviceId = checked('service_id');
        const employeeId = checked('employee_id');
        const date = dateInput.value;

        book.elements.namedItem('service_id').value = serviceId;
        book.elements.namedItem('employee_id').value = employeeId;

        if (!serviceId || !employeeId || !date) {
            book.hidden = true;
            return;
        }

        book.hidden = false;
        slotList.replaceChildren();
        status.textContent = MESSAGES.loading;
        submit.disabled = true;

        const controller = new AbortController();
        pending = controller;
        const url = new URL(root.dataset.slotsUrl, window.location.href);
        url.search = new URLSearchParams({ service_id: serviceId, employee_id: employeeId, date }).toString();

        try {
            const response = await fetch(url, { headers: JSON_HEADERS, signal: controller.signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            renderSlots((await response.json()).slots);
        } catch (error) {
            if (error.name === 'AbortError') return;
            status.textContent = MESSAGES.loadFailed;
        } finally {
            if (pending === controller) {
                pending = null;
                submit.disabled = false;
            }
        }
    }

    choose.addEventListener('change', (event) => {
        showError([]);
        if (event.target.name === 'service_id') renderEmployees(checked('service_id'));
        employeeStep.hidden = !checked('service_id');
        dateStep.hidden = employeeStep.hidden || !checked('employee_id');
        loadSlots();
    });

    book.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!book.querySelector('input[name="starts_at"]:checked')) {
            showError([MESSAGES.noTime]);
            return;
        }

        submit.disabled = true;
        showError([]);

        try {
            const response = await fetch(book.action, { method: 'POST', headers: JSON_HEADERS, body: new FormData(book) });

            if (response.status === 201) {
                window.location.assign((await response.json()).redirect);
                return;
            }

            if (response.status === 419) {
                showError([MESSAGES.sessionExpired]);
            } else {
                const data = await response.json().catch(() => ({}));
                showError(data.errors ? Object.values(data.errors).flat() : [data.message || MESSAGES.bookFailed]);

                // Just taken by someone else: offer the times that are still free.
                if (response.status === 409) await loadSlots();
            }
        } catch {
            showError([MESSAGES.bookFailed]);
        }

        if (pending === null) submit.disabled = false;
        if (!errorBox.hidden) errorBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
}

document.querySelectorAll('[data-booking-flow]').forEach(initBookingFlow);
