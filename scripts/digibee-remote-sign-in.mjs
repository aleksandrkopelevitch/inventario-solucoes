// A Digibee sign-in run on the SERVER, for a person who is looking at it
// through the iSol screen "Sessão Digibee" (RunRemoteDigibeeSignIn starts it).
//
// Opens the realm's SSO link in a headless Chromium with a persistent profile
// and serves a tiny bridge on 127.0.0.1 only, so the PHP side can show the page
// and pass the person's clicks and keys into it:
//
//   GET  /frame    the active page as a JPEG
//   GET  /state    {status: waiting|captured|failed, message, host}
//   POST /input    {type: click|type|press|scroll, ...}
//   GET  /session  the captured headers — once, then they are forgotten here
//   POST /quit     close the browser and exit
//
// Every request must carry `x-bridge-secret`, which arrives in the environment
// (DIGIBEE_BRIDGE_SECRET) rather than in argv, since argv is readable by every
// account on the box through `ps`. Nothing typed is logged or written to disk:
// keys go from the request body straight into the page.
//
// The first line on stdout is `{"port": N}`; nothing else is printed there. A
// hard deadline closes everything even if the PHP side vanished, so a worker
// killed mid-run never leaves a browser behind for long.

import { createServer } from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { mkdirSync, chmodSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { isBackInApp, watchForSession } from './lib/digibee-session-capture.mjs';

const args = Object.fromEntries(
    process.argv.slice(2).map((arg) => {
        const [key, ...rest] = arg.replace(/^--/, '').split('=');
        return [key, rest.join('=')];
    }),
);

for (const required of ['playwright', 'profile', 'endpoint', 'app-url', 'login-url', 'monitor-url']) {
    if (!args[required]) {
        console.error(`missing --${required}`);
        process.exit(64);
    }
}

const secret = Buffer.from(process.env.DIGIBEE_BRIDGE_SECRET || '');

if (secret.length < 32) {
    console.error('DIGIBEE_BRIDGE_SECRET missing or too short');
    process.exit(64);
}

const VIEWPORT = { width: 1024, height: 720 };
const timeoutMs = Number(args.timeout || 300) * 1000;
const appOrigin = new URL(args['app-url']).origin;

// Keys a person needs to get through a sign-in form; anything else is typed as
// text. Closed on purpose: no shortcut that opens DevTools or a new window.
const KEYS = new Set(['Enter', 'Tab', 'Backspace', 'Delete', 'Escape', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'Shift+Tab']);

const pw = await import(pathToFileURL(join(args.playwright, 'index.js')).href);
const { chromium } = pw.default ?? pw;

// The profile holds the IdP and Entra cookies — what lets the next sign-in pass
// without anybody — so it is as private as the token itself.
mkdirSync(args.profile, { recursive: true, mode: 0o700 });
chmodSync(args.profile, 0o700);
chmodSync(dirname(args.profile), 0o700);

const context = await chromium.launchPersistentContext(args.profile, {
    headless: true,
    viewport: VIEWPORT,
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    // The headless build announces itself as "HeadlessChrome", which sign-in
    // pages are free to treat as a bot. The person driving it is real.
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
    args: ['--no-sandbox'],
});

let state = { status: 'waiting', message: null };
let session = null;
let active = context.pages()[0] ?? (await context.newPage());

// A sign-in that opens a popup moves the person into it; closing it returns
// them to whatever page is still open.
context.on('page', (page) => {
    active = page;
    page.on('close', () => {
        active = context.pages().at(-1) ?? active;
    });
});

async function finish(code) {
    await context.close().catch(() => {});
    process.exit(code);
}

// Belt and braces: whatever the PHP side does, this process ends.
setTimeout(() => finish(3), timeoutMs + 60_000).unref();

watchForSession(context, args.endpoint).then((captured) => {
    session = captured;
    state = { status: 'captured', message: null };
});

let lastFrame = { at: 0, bytes: null };

async function frame() {
    // Several viewers polling at once share one screenshot.
    if (Date.now() - lastFrame.at < 250 && lastFrame.bytes) {
        return lastFrame.bytes;
    }

    const bytes = await active.screenshot({ type: 'jpeg', quality: 70, timeout: 5000 });
    lastFrame = { at: Date.now(), bytes };

    return bytes;
}

async function input(event) {
    lastFrame.at = 0;

    switch (event.type) {
        case 'click': {
            const x = Number(event.x);
            const y = Number(event.y);

            if (x >= 0 && y >= 0 && x <= VIEWPORT.width && y <= VIEWPORT.height) {
                await active.mouse.click(x, y);
            }
            return;
        }
        case 'type':
            await active.keyboard.type(String(event.text ?? '').slice(0, 500));
            return;
        case 'press':
            if (KEYS.has(event.key)) {
                await active.keyboard.press(event.key);
            }
            return;
        case 'scroll':
            await active.mouse.wheel(0, Math.max(-2000, Math.min(2000, Number(event.dy) || 0)));
            return;
    }
}

function authorized(request) {
    const given = Buffer.from(String(request.headers['x-bridge-secret'] || ''));

    return given.length === secret.length && timingSafeEqual(given, secret);
}

function readBody(request) {
    return new Promise((resolve) => {
        let body = '';
        request.on('data', (chunk) => {
            body += chunk;
            if (body.length > 10_000) {
                request.destroy();
            }
        });
        request.on('end', () => {
            try {
                resolve(JSON.parse(body || '{}'));
            } catch {
                resolve({});
            }
        });
    });
}

function json(response, status, payload) {
    response.writeHead(status, { 'content-type': 'application/json', 'cache-control': 'no-store' });
    response.end(JSON.stringify(payload));
}

const server = createServer(async (request, response) => {
    if (!authorized(request)) {
        return json(response, 403, { error: 'forbidden' });
    }

    const route = `${request.method} ${new URL(request.url, 'http://127.0.0.1').pathname}`;

    try {
        switch (route) {
            case 'GET /frame': {
                const bytes = await frame();
                response.writeHead(200, { 'content-type': 'image/jpeg', 'cache-control': 'no-store' });
                return response.end(bytes);
            }
            case 'GET /state': {
                let host = null;
                try {
                    host = new URL(active.url()).host;
                } catch {}
                return json(response, 200, { ...state, host });
            }
            case 'POST /input':
                await input(await readBody(request));
                return json(response, 200, { ok: true });
            case 'GET /session': {
                if (!session) {
                    return json(response, 409, { error: 'not captured' });
                }
                const handed = session;
                session = null;
                return json(response, 200, handed);
            }
            case 'POST /quit':
                json(response, 200, { ok: true });
                return setTimeout(() => finish(0), 50);
            default:
                return json(response, 404, { error: 'unknown route' });
        }
    } catch (error) {
        return json(response, 500, { error: error.message });
    }
});

server.listen(0, '127.0.0.1', () => {
    process.stdout.write(JSON.stringify({ port: server.address().port }) + '\n');
});

await active.goto(args['login-url'], { waitUntil: 'domcontentloaded' }).catch(() => {});

// Back in the app means the code was traded for a token; opening the Monitor
// makes it call the routes that prove the session can read logs (it usually
// already has, from the landing page).
const deadline = Date.now() + timeoutMs;

active
    .waitForURL((url) => isBackInApp(url, appOrigin), { timeout: timeoutMs })
    .then(async () => {
        if (state.status === 'waiting') {
            await active.goto(args['monitor-url'], { waitUntil: 'domcontentloaded' }).catch(() => {});
        }
    })
    .catch(() => {});

setTimeout(() => {
    if (state.status === 'waiting') {
        state = { status: 'failed', message: 'timeout' };
    }
}, Math.max(0, deadline - Date.now())).unref();
