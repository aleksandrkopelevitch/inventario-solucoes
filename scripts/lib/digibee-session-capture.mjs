// What both Digibee sign-in scripts agree on: which request proves a session
// can read logs, and when the IdP round trip has really handed back to the app.
// One definition, so the workstation login (`digibee-monitor-login.mjs`) and
// the server's remote one (`digibee-remote-sign-in.mjs`) cannot drift.

/**
 * Only a call to the platform API that carries both headers counts — the login
 * round trip itself sends `apikey` without `authorization`, and the Monitor and
 * runtime routes are the ones whose answer proves the session can read logs.
 */
export function sessionProof(request, endpoint) {
    if (!request.url().startsWith(endpoint)) {
        return null;
    }

    const headers = request.headers();

    if (headers.authorization && headers.apikey && /\/(monitor|runtime)\//.test(request.url())) {
        return { authorization: headers.authorization, apikey: headers.apikey };
    }

    return null;
}

/** Resolves with the first session proof any page of the context sends. */
export function watchForSession(context, endpoint) {
    return new Promise((resolve) => {
        context.on('request', (request) => {
            const session = sessionProof(request, endpoint);

            if (session) {
                resolve(session);
            }
        });
    });
}

/**
 * The IdP hands back to /oauth, where the app trades the code for its token:
 * that page is still the login, and navigating away from it aborts the trade —
 * which is what made every sign-in time out while the window looked signed in
 * (fixed 2026-11-05). /login is the app's password form, also not "back".
 */
export function isBackInApp(url, appOrigin) {
    return url.origin === appOrigin && !/^\/(login|oauth)\b/.test(url.pathname);
}
