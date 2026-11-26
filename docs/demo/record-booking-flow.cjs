/*
 * Screen recording of the booking flow (ISSUE-27), made for the LinkedIn post
 * in docs/demo/linkedin-post.md. Not part of the app or the test suite.
 *
 * Two customers book side by side on the demo salon. Both pick the same time
 * and press "Afspraak boeken": B gets it, A gets the "net bezet" answer (HTTP
 * 409), sees the free times reload without that slot and books the next one.
 * Captions in the video explain what happens, including the time zones.
 *
 * Run it against a freshly seeded demo (php artisan migrate:fresh --seed) and
 * a running server. Playwright and a full ffmpeg build are not dependencies of
 * the project, so pass their locations:
 *
 *   node docs/demo/record-booking-flow.cjs \
 *     --base-url http://127.0.0.1:8027 \
 *     --playwright path/to/node_modules/playwright \
 *     --ffmpeg path/to/ffmpeg.exe \
 *     --out docs/demo/booking-flow.mp4
 *
 * The two windows are recorded as WebM by Playwright and put next to each
 * other in one H.264 MP4 (the format LinkedIn accepts) by ffmpeg.
 */

const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

function arg(name, fallback) {
    const i = process.argv.indexOf(`--${name}`);
    if (i !== -1 && process.argv[i + 1]) return process.argv[i + 1];
    if (fallback !== undefined) return fallback;
    throw new Error(`Missing --${name}`);
}

const BASE_URL = arg('base-url', 'http://127.0.0.1:8027');
const { chromium } = require(path.resolve(arg('playwright', 'playwright')));
const FFMPEG = arg('ffmpeg', 'ffmpeg');
const OUT = path.resolve(arg('out', 'docs/demo/booking-flow.mp4'));

const SIZE = { width: 640, height: 820 };
const SERVICE = 'Knippen dames';
const EMPLOYEE = 'Noor';
const PASSWORD = 'password';
const ZONE = 'Europe/Amsterdam';

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/*
 * Injected into every page: a name tag at the top, a caption bar at the
 * bottom and a ring where the mouse clicks. The caption is kept in
 * sessionStorage, so it survives the redirects of the flow.
 */
function overlay({ who, colour }) {
    const css = `
        #demo-tag { position: fixed; top: 60px; right: 12px; z-index: 99999; padding: 4px 10px;
            border-radius: 999px; background: ${colour}; color: #fff; font: 600 13px system-ui, sans-serif; }
        #demo-caption { position: fixed; left: 0; right: 0; bottom: 0; z-index: 99999; padding: 14px 18px;
            background: rgba(17, 24, 39, .92); color: #fff; font: 500 16px/1.4 system-ui, sans-serif; }
        #demo-caption:empty { display: none; }
        #demo-caption code { font: 600 14px ui-monospace, monospace; color: #fde68a; }
        .demo-ring { position: fixed; z-index: 99998; width: 36px; height: 36px; margin: -18px 0 0 -18px;
            border: 3px solid ${colour}; border-radius: 50%; pointer-events: none; animation: demo-ring .6s ease-out forwards; }
        @keyframes demo-ring { from { transform: scale(.3); opacity: 1; } to { transform: scale(1.4); opacity: 0; } }
        body { padding-bottom: 120px; }`;

    const mount = () => {
        const style = document.createElement('style');
        style.textContent = css;
        document.head.append(style);
        const tag = document.createElement('div');
        tag.id = 'demo-tag';
        tag.textContent = who;
        const caption = document.createElement('div');
        caption.id = 'demo-caption';
        caption.innerHTML = sessionStorage.getItem('demo-caption') ?? '';
        document.body.append(tag, caption);
        document.addEventListener('mousedown', (event) => {
            const ring = document.createElement('div');
            ring.className = 'demo-ring';
            ring.style.left = `${event.clientX}px`;
            ring.style.top = `${event.clientY}px`;
            document.body.append(ring);
            setTimeout(() => ring.remove(), 700);
        }, true);
    };

    window.demoCaption = (html) => {
        sessionStorage.setItem('demo-caption', html);
        const caption = document.getElementById('demo-caption');
        if (caption) caption.innerHTML = html;
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
    else mount();
}

async function customer(browser, videoDir, { who, colour, email }) {
    const context = await browser.newContext({
        viewport: SIZE,
        recordVideo: { dir: videoDir, size: SIZE },
        locale: 'nl-NL',
        timezoneId: ZONE,
    });
    await context.addInitScript(overlay, { who, colour });
    const page = await context.newPage();
    return { context, page, email };
}

const caption = (page, html) => page.evaluate((h) => window.demoCaption(h), html);

async function logIn({ page, email }) {
    await page.goto(`${BASE_URL}/login`);
    await page.locator('#email').pressSequentially(email, { delay: 25 });
    await page.locator('#password').pressSequentially(PASSWORD, { delay: 25 });
    await page.getByRole('button', { name: 'Inloggen' }).click();
    await page.waitForLoadState('networkidle');
    await page.goto(`${BASE_URL}/bookings/create`);
}

/** A day a few days ahead on which Noor has several free morning slots. */
async function pickDay(page) {
    const serviceId = await page.getByLabel(SERVICE).first().getAttribute('value');
    const catalogue = JSON.parse(await page.locator('[data-booking-catalogue]').textContent());
    const employeeId = String(catalogue.find((s) => String(s.id) === serviceId)
        .employees.find((e) => e.name === EMPLOYEE).id);

    for (let offset = 5; offset < 30; offset++) {
        const day = new Date(Date.now() + offset * 86_400_000).toLocaleDateString('sv-SE', { timeZone: ZONE });
        const slots = await page.evaluate(async (query) => {
            const response = await fetch(`/bookings/slots?${new URLSearchParams(query)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            return response.ok ? (await response.json()).slots : [];
        }, { service_id: serviceId, employee_id: employeeId, date: day });

        // A free morning slot to fight over, with room after it to fall back to.
        const morning = slots.filter((s) => s.label >= '09:30' && s.label <= '11:30');
        if (morning.length >= 6) return { day, contested: morning[0] };
    }
    throw new Error('No day with free morning slots found; reseed the demo.');
}

async function choose(page, day) {
    await page.getByText(SERVICE, { exact: true }).click();
    await pause(500);
    await page.getByText(EMPLOYEE, { exact: true }).click();
    await pause(500);
    await page.locator('#date').fill(day);
    await page.locator('[data-booking-slots] label').first().waitFor();
}

const slot = (page, label) => page.locator('[data-booking-slots] label').filter({ hasText: new RegExp(`^${label}$`) });

function utc(value) {
    return new Date(value).toISOString().slice(11, 16);
}

function longDate(day) {
    return new Date(`${day}T12:00:00Z`).toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });
}

(async () => {
    const videoDir = fs.mkdtempSync(path.join(os.tmpdir(), 'booking-demo-'));
    const browser = await chromium.launch();

    // Both windows start recording at the same moment, so the videos line up.
    const [a, b] = await Promise.all([
        customer(browser, videoDir, { who: 'Klant A · Emma', colour: '#2563eb', email: 'klant@example.com' }),
        customer(browser, videoDir, { who: 'Klant B · Lucas', colour: '#db2777', email: 'lucas.peters@example.com' }),
    ]);

    await Promise.all([a, b].map(logIn));
    const { day, contested } = await pickDay(a.page);
    const when = `${longDate(day)} ${contested.label}`;

    await Promise.all([
        caption(a.page, 'Twee klanten boeken op hetzelfde moment bij dezelfde kapper.'),
        caption(b.page, 'Dienst → medewerker → datum → vrije tijden, live geladen.'),
    ]);
    await pause(1500);

    await Promise.all([choose(a.page, day), pause(300).then(() => choose(b.page, day))]);
    await pause(800);

    await Promise.all([slot(a.page, contested.label).click(), slot(b.page, contested.label).click()]);
    await Promise.all([
        caption(a.page, `Allebei kiezen ze <b>${when}</b>…`),
        caption(b.page, '…en drukken vrijwel tegelijk op "Afspraak boeken".'),
    ]);
    await pause(2500);

    // B is a moment earlier. A's request waits for B's lock and then sees B's booking.
    const bDone = b.page.waitForURL(/\/bookings\/\d+$/);
    const aRefused = a.page.waitForResponse((r) => r.url().endsWith('/bookings') && r.request().method() === 'POST');
    await b.page.locator('[data-booking-submit]').click();
    await pause(150);
    await a.page.locator('[data-booking-submit]').click();

    await bDone;
    const refusal = await aRefused;
    if (refusal.status() !== 409) throw new Error(`Expected 409 for A, got ${refusal.status()}`);
    await a.page.locator('[data-booking-error]').waitFor();
    await a.page.locator('[data-booking-slots] label').first().waitFor();

    // B's booking and its buffer are gone from A's list now; take the next free time.
    const fallback = await a.page.locator('[data-booking-slots] input').evaluateAll((inputs, after) => inputs
        .map((input) => ({ starts_at: input.value, label: input.nextElementSibling.textContent.trim() }))
        .find((s) => s.label > after), contested.label);
    if (!fallback) throw new Error(`No free time after ${contested.label} on ${day}`);

    await Promise.all([
        caption(b.page, 'Lucas was een fractie eerder: geboekt.'),
        caption(a.page, 'Emma krijgt <code>409 Conflict</code>: net bezet. De vrije tijden laden opnieuw: '
            + `${contested.label} is weg, en ook de tijd erna (45 min knippen + 15 min buffer).`),
    ]);
    await pause(4500);

    await caption(b.page, 'Onder water: één transactie, eerst <code>SELECT … FOR UPDATE</code> op de medewerker, dan pas controleren en opslaan.');
    await slot(a.page, fallback.label).click();
    await caption(a.page, `Ze kiest ${fallback.label}. Het formulier stuurt <code>${fallback.starts_at}</code>: lokale tijd mét offset.`);
    await pause(4500);

    await caption(a.page, `${fallback.label} in Amsterdam = <code>${utc(fallback.starts_at)} UTC</code> in de database. `
        + 'Werkroosters blijven lokale tijd, dus 09:00 blijft 09:00.');
    await pause(4000);

    const aDone = a.page.waitForURL(/\/bookings\/\d+$/);
    await a.page.locator('[data-booking-submit]').click();
    await aDone;
    await Promise.all([
        caption(a.page, `Geboekt om ${fallback.label} Nederlandse tijd. Ook rond de wintertijd klopt elk tijdslot.`),
        caption(b.page, 'Precies één boeking per tijdslot, getest met echte gelijktijdige requests op MySQL.'),
    ]);
    await pause(4500);

    const videos = [await a.page.video().path(), await b.page.video().path()];
    await Promise.all([a.context.close(), b.context.close()]);
    await browser.close();

    fs.mkdirSync(path.dirname(OUT), { recursive: true });
    execFileSync(FFMPEG, [
        '-y', '-loglevel', 'error',
        '-i', videos[0], '-i', videos[1],
        '-filter_complex', '[0:v][1:v]hstack=inputs=2,format=yuv420p[v]',
        '-map', '[v]', '-c:v', 'libx264', '-preset', 'slow', '-crf', '20', '-r', '25',
        '-movflags', '+faststart', OUT,
    ], { stdio: 'inherit' });

    fs.rmSync(videoDir, { recursive: true, force: true });
    console.log(`Saved ${OUT} (${day}, contested ${contested.label}, fallback ${fallback.label})`);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
