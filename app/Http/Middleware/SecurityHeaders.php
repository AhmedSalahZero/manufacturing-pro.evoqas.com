<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — SecurityHeaders
//  Location: app/Http/Middleware/SecurityHeaders.php
//
//  Adds the standard browser protections to every web response:
//
//    Content-Security-Policy  scripts may load only from ZAVERO itself (plus the few
//                             inline scripts that carry this request's secret "nonce"),
//                             so an injected script from outside cannot run. No other
//                             site may be contacted. Frames are not allowed at all.
//    X-Frame-Options          nobody can show ZAVERO inside their own page (clickjacking).
//    X-Content-Type-Options   the browser must not guess a file's type.
//    Referrer-Policy          other sites learn only that the visit came from ZAVERO.
//    Permissions-Policy       camera, microphone, location, payment, USB ... switched off.
//    Strict-Transport-Security  on https the browser refuses plain http for a year.
//    Cross-Origin-Opener-Policy no other window can reach into ZAVERO's window.
//
//  Settings: config/security.php (.env: SECURITY_CSP, SECURITY_HSTS, SECURITY_HEADERS).
//  SECURITY_CSP = report-only (default: only logs problems) | enforce | off
//
//  While the Vite development server runs ("npm run dev") the policy is skipped,
//  because the development server lives on another address.
// ══════════════════════════════════════════════════════════════════

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.enabled', true)) {
            return $next($request);
        }

        // Must happen BEFORE the page is built: Vite and the Blade views read it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=(), bluetooth=(), accelerometer=(), gyroscope=(), magnetometer=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() && config('security.hsts', true)) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('security.hsts_max_age', 31536000).'; includeSubDomains');
        }

        $mode = (string) config('security.csp', 'report-only');

        if (in_array($mode, ['enforce', 'report-only'], true) && ! Vite::isRunningHot()) {
            $headers->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $this->policy($nonce, $request->isSecure()));
        }

        return $response;
    }

    private function policy(string $nonce, bool $secure): string
    {
        $parts = [
            "default-src 'self'",
            // Scripts: ZAVERO's own files + inline scripts that carry the nonce. No 'unsafe-inline', no 'unsafe-eval'.
            "script-src 'self' 'nonce-{$nonce}'",
            // Styles: inline style="…" is used throughout the screens.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            // Only ZAVERO's own server may be contacted (pages, sync, uploads).
            "connect-src 'self'",
            // The offline helper (service worker) and image shrinking.
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        // Violations (or would-be violations in report-only mode) are sent here and written to storage/logs/csp.log.
        $parts[] = 'report-uri /csp-report';

        if ($secure) {
            $parts[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $parts);
    }
}
