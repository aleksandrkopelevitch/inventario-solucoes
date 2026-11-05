// Captures a Digibee web session for `php artisan digibee:logs`.
//
// Opens the Monitor screen in a browser window with a PERSISTENT profile. If
// the profile is still signed in, the screen calls the platform API on its own
// and this script reads the `authorization` and `apikey` headers off that
// request. If it is not, a person signs in through the IdP in that window and
// the same thing happens right after. Nothing here types a credential, fills a
// form or touches a captcha: the login is always a human's.
//
// The headers are read off the wire rather than out of the page's storage on
// purpose — where the SPA keeps its session is not published and changes with
// its builds, while the request it sends is the contract the API enforces.
//
// Invoked by `digibee:monitor:login`, which passes everything as arguments;
// the session is written 0600 and never printed.

import { mkdirSync, writeFileSync, chmodSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';

const args = Object.fromEntries(
    process.argv.slice(2).map((arg) => {
        const [key, ...rest] = arg.replace(/^--/, '').split('=');
        return [key, rest.join('=')];
    }),
);

for (const required of ['playwright', 'profile', 'out', 'url', 'endpoint']) {
    if (!args[required]) {
        console.error(`missing --${required}`);
        process.exit(64);
    }
}

const timeoutMs = Number(args.timeout || 300) * 1000;
const playwright = await import(pathToFileURL(join(args.playwright, 'index.js')).href);
const { chromium } = playwright.default ?? playwright;

// The profile holds the IdP and Digibee cookies — as private as the session file.
mkdirSync(args.profile, { recursive: true, mode: 0o700 });
chmodSync(args.profile, 0o700);
chmodSync(dirname(args.profile), 0o700);

const context = await chromium.launchPersistentContext(args.profile, {
    headless: false,
    viewport: null,
    args: ['--no-sandbox'],
});

// Only a call to the platform API that carries both headers counts — the
// login round trip itself sends `apikey` without `authorization`, and the
// Monitor routes are the ones whose answer proves the session can read logs.
const captured = new Promise((resolve) => {
    context.on('request', (request) => {
        if (!request.url().startsWith(args.endpoint)) {
            return;
        }

        const headers = request.headers();

        if (headers.authorization && headers.apikey && /\/(monitor|runtime)\//.test(request.url())) {
            resolve({ authorization: headers.authorization, apikey: headers.apikey });
        }
    });
});

const deadline = Date.now() + timeoutMs;
const wait = (ms) => new Promise((resolve) => setTimeout(() => resolve(null), Math.max(0, ms)));
const closed = new Promise((resolve) => context.on('close', () => resolve(null)));
const appOrigin = new URL(args.url).origin;

const page = context.pages()[0] ?? (await context.newPage());
await page.goto(args.url, { waitUntil: 'domcontentloaded' }).catch(() => {});

// A profile whose session is still alive gets here without a click.
let session = await Promise.race([captured, closed, wait(15000)]);

// Otherwise sign in through the IdP — this realm has no Digibee passwords, and
// the app's own /login page is the password form, not the way in. Once the
// IdP hands back to the app, the Monitor is opened again to make it call.
// The IdP hands back to /oauth, where the app trades the code for its token:
// that page is still the login, and navigating away from it aborts the trade.
if (!session && args['login-url']) {
    console.log('Not signed in — opening the identity provider. Sign in in the window that opened.');
    await page.goto(args['login-url'], { waitUntil: 'domcontentloaded' }).catch(() => {});

    const landed = page
        .waitForURL((url) => url.origin === appOrigin && !/^\/(login|oauth)\b/.test(url.pathname), { timeout: Math.max(1, deadline - Date.now()) })
        .then(async () => {
            await page.goto(args.url, { waitUntil: 'domcontentloaded' }).catch(() => {});

            return captured;
        })
        .catch(() => null);

    session = await Promise.race([captured, landed, closed, wait(deadline - Date.now())]);
}

await context.close().catch(() => {});

if (!session) {
    console.error('No authenticated Monitor call within the time limit.');
    process.exit(2);
}

mkdirSync(dirname(args.out), { recursive: true });
writeFileSync(args.out, JSON.stringify({ ...session, captured_at: new Date().toISOString() }), { mode: 0o600 });
chmodSync(args.out, 0o600);

console.log('Session captured.');

// The losing waits above are still pending timers; without this the process
// would sit out the whole timeout after the session is already on disk.
process.exit(0);
