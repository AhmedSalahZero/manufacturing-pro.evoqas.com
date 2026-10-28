<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO — CspReportController ( POST /csp-report )
//  Location: app/Http/Controllers/CspReportController.php
//
//  The browser posts here, by itself and without the person noticing,
//  whenever the security policy (App\Http\Middleware\SecurityHeaders)
//  blocks something — or WOULD block it while the policy is in
//  "report-only" mode. Each report becomes one line in
//  storage/logs/csp.log. So nobody has to open the browser's developer
//  tools: after a few days of normal use, an EMPTY csp.log means the
//  policy is safe to switch on (.env: SECURITY_CSP=enforce).
//
//  Open to everyone (a browser sends it without signing in), so it is
//  throttled, reads at most 8 KB, stores only a few short fields, and
//  ignores reports caused by browser extensions (not ZAVERO's fault).
// ══════════════════════════════════════════════════════════════════

class CspReportController extends Controller
{
    /** Browser extensions inject their own scripts and styles; those reports say nothing about ZAVERO. */
    private const IGNORED = ['chrome-extension', 'moz-extension', 'safari-extension', 'safari-web-extension', 'webkit-masked-url', 'ms-browser-extension'];

    public function __invoke(Request $request): Response
    {
        $body = json_decode(mb_substr((string) $request->getContent(), 0, 8192), true);
        $report = is_array($body) ? ($body['csp-report'] ?? $body) : [];

        $blocked = (string) ($report['blocked-uri'] ?? $report['blockedURL'] ?? '');

        if (! is_array($report) || $report === [] || $this->isExtension($blocked, (string) ($report['source-file'] ?? ''))) {
            return response()->noContent();
        }

        Log::channel('csp')->warning('Security policy: '.($report['effective-directive'] ?? $report['violated-directive'] ?? $report['effectiveDirective'] ?? '?'), [
            'blocked'   => mb_substr($blocked, 0, 200),
            'page'      => mb_substr((string) ($report['document-uri'] ?? $report['documentURL'] ?? ''), 0, 200),
            'file'      => mb_substr((string) ($report['source-file'] ?? $report['sourceFile'] ?? ''), 0, 200),
            'line'      => $report['line-number'] ?? $report['lineNumber'] ?? null,
            'sample'    => mb_substr((string) ($report['script-sample'] ?? $report['sample'] ?? ''), 0, 80),
            'disposition' => $report['disposition'] ?? null,
        ]);

        return response()->noContent();
    }

    private function isExtension(string ...$values): bool
    {
        foreach ($values as $value) {
            foreach (self::IGNORED as $scheme) {
                if (str_starts_with($value, $scheme)) {
                    return true;
                }
            }
        }

        return false;
    }
}
