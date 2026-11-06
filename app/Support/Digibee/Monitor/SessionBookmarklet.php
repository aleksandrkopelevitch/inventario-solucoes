<?php

namespace App\Support\Digibee\Monitor;

/**
 * The "Enviar sessão ao iSol" bookmarklet: run on www.godigibee.io after a
 * normal sign-in, it hands the Digibee session to iSol's import page.
 *
 * Measured 2026-11-06 on the live app: the web app keeps its session in the
 * script-readable `userData` cookie, a JSON whose `token` carries the JWT, and
 * the `apikey` it sends is the public `window.runtimeConfig.API_KEY`. Its CSP
 * allows inline script (so a bookmarklet runs) but not a `fetch` to us
 * (`connect-src`), and it sends no Cross-Origin-Opener-Policy — so the hand
 * over is a window it opens plus `postMessage`:
 *
 *   1. it opens the import page, which answers `isol-digibee-ready` to its
 *      opener, addressed to the Digibee origin only;
 *   2. it answers `isol-digibee-session` to THAT window, addressed to iSol's
 *      origin only — never in a URL, so nothing lands in a log or the history.
 *
 * Only the JWT and the apikey leave the page; the cookie's e-mail and dates do
 * not. Written without `%` and `#`, which a `javascript:` URL would decode or
 * cut.
 */
final class SessionBookmarklet
{
    public static function href(string $importUrl): string
    {
        $isolOrigin = self::origin($importUrl);
        $digibeeOrigin = self::origin((string) config('services.digibee.monitor.app_url'));

        $code = <<<JS
            (()=>{const D=DIGIBEE,U=IMPORT,O=ISOL;
            if(location.origin!==D){alert('Abra a Digibee ('+D+') já com o login feito e clique de novo neste favorito.');return}
            let t='';try{t=(JSON.parse(decodeURIComponent((document.cookie.split('; ').find(c=>c.indexOf('userData=')===0)||'').slice(9))).token||'').match(/eyJ[\w-]+\.[\w-]+\.[\w-]+/)[0]}catch(e){}
            const k=(window.runtimeConfig||{}).API_KEY;
            if(!t||!k){alert('Não encontrei a sessão da Digibee nesta aba. Entre na Digibee e clique de novo.');return}
            const w=window.open(U,'isol-digibee-session');
            if(!w){alert('O navegador bloqueou a janela do iSol. Permita pop-ups para a Digibee e clique de novo.');return}
            const h=e=>{if(e.origin!==O||e.source!==w||!e.data||e.data.type!=='isol-digibee-ready')return;w.postMessage({type:'isol-digibee-session',token:t,apikey:k},O)};
            window.addEventListener('message',h);setTimeout(()=>window.removeEventListener('message',h),600000)})()
            JS;

        $code = strtr($code, [
            'DIGIBEE' => json_encode($digibeeOrigin, JSON_UNESCAPED_SLASHES),
            'IMPORT'  => json_encode($importUrl, JSON_UNESCAPED_SLASHES),
            'ISOL'    => json_encode($isolOrigin, JSON_UNESCAPED_SLASHES),
        ]);

        return 'javascript:' . str_replace("\n", '', $code);
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
