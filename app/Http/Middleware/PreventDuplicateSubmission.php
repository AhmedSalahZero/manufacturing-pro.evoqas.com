<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — PreventDuplicateSubmission
//  Location: app/Http/Middleware/PreventDuplicateSubmission.php
//
//  Stops the same form submission being recorded twice.
//  Used on every signed-in area (route alias "no-duplicate").
//
//  Nothing prevented it before. A double-click on "Record sale", a
//  phone that resends on a flaky connection, or an impatient second
//  tap while the first request was still in flight each produced two
//  invoices AND two payments — real money counted twice, in a
//  system whose whole job is counting money accurately. The user
//  sees two identical rows and has to work out which one is the
//  mistake, and deleting the wrong one takes its payment with it.
//
//  How it works: a fingerprint of (user + route + body) is held in
//  the cache for a few seconds. A second request carrying the same
//  fingerprint inside that window is sent back untouched, with a
//  message explaining why, rather than being written.
//
//  Why a time window rather than a token per form: a token means
//  every form in the app has to remember to send one, and the one
//  that forgets is silently unprotected — the same shape of problem
//  as the missing delete checks. A window protects every write route
//  the moment it exists, including ones not written yet.
//
//  The window is deliberately short. Entering the same figure twice
//  on purpose is a real thing people do — two identical 50 EGP cash
//  sales in a row — and after WINDOW_SECONDS that goes through
//  normally. What it cannot survive is the same body arriving twice
//  within a few seconds, which is not something a human does by
//  hand.
// ══════════════════════════════════════════════════════════════════
class PreventDuplicateSubmission
{
    /** How long one fingerprint blocks its own repeat. */
    private const WINDOW_SECONDS = 8;

    /**
     * Fields that differ between two otherwise identical submissions
     * without changing what gets recorded, and so must not go into
     * the fingerprint.
     */
    private const IGNORED_FIELDS = ['_token', '_method'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldGuard($request)) {
            return $next($request);
        }

        $key = $this->fingerprint($request);

        // add() is atomic — it writes only if the key is absent and
        // reports whether it did. Two requests arriving together
        // therefore cannot both believe they were first, which a
        // has()-then-put() pair would allow.
        if (! $this->firstTime($key)) {
            // Flashed rather than thrown as a validation error: there
            // is no field on any of these forms this belongs to, and
            // every page already renders flashed errors as a toast
            // (see AppLayout). The user gets told what happened
            // wherever they were, without a stray error hanging off
            // an unrelated input.
            return back()->with('error', __('errors.duplicate_submission'));
        }

        return $next($request);
    }

    /**
     * True when this fingerprint was not seen in the last few seconds.
     *
     * Uses the durable store (config cache.durable), so a cache set to
     * "array"/"null" cannot silently switch the guard off. If the store
     * itself breaks (database down), the request is let through rather than
     * blocking every save in the office — and the fault is written to the
     * log so it is noticed, not hidden.
     */
    private function firstTime(string $key): bool
    {
        try {
            return Cache::store(config('cache.durable'))->add($key, true, self::WINDOW_SECONDS);
        } catch (Throwable $e) {
            report($e);

            return true;
        }
    }

    /**
     * Only writes, only from a signed-in user, and never DELETE.
     *
     * DELETE is left out on purpose: deleting the same record twice
     * is already harmless (the second attempt 404s once the row is
     * gone), and blocking it would stop somebody legitimately
     * clearing several rows in quick succession.
     */
    private function shouldGuard(Request $request): bool
    {
        return $this->account($request) !== null
            && in_array($request->method(), ['POST', 'PUT', 'PATCH'], true);
    }

    /**
     * Identical body + same user + same endpoint = same submission.
     *
     * The body is sorted before hashing so that two requests whose
     * fields arrive in a different order still fingerprint the same.
     */
    private function fingerprint(Request $request): string
    {
        $payload = $request->except(self::IGNORED_FIELDS);

        $this->sortRecursive($payload);

        return 'dup:'.hash('xxh128', implode('|', [
            $this->account($request),
            $request->method(),
            $request->path(),
            json_encode($payload),
        ]));
    }

    /** "web:12" — who is submitting. */
    private function account(Request $request): ?string
    {
        if ($id = $request->user()?->getAuthIdentifier()) {
            return 'web:'.$id;
        }

        return null;
    }

    private function sortRecursive(array &$data): void
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }

        unset($value);

        ksort($data);
    }
}
