<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// ──────────────────────────────────────────────────────────────────
//  ZAVERO Manufacturing Pro — PublicLink (the door of a public sharing link)
//  Location: app/Http/Middleware/PublicLink.php
//
//  Every page of a study opened from /shared/{code}/... (a "view" link: looking only) or /fill/{code}/... (a "fill" link: the customer fills
//  in the input pages) passes here first (alias 'public-link', or 'public-link:fill'):
//    · an unknown code, a code of the other kind, or a code whose study was deleted is a 404;
//    · a link that is paused or past its expiry date shows the "closed" page (410) and logs nothing (a fill link also closes when the
//      company's subscription has ended);
//    · the study in the address must be the study of the link (a visitor can never reach another study);
//    · the visit is logged (the address of the visitor, once an hour);
//    · the link is remembered for this request: the policy lets a visitor LOOK at that one study (view link) or fill in its input pages
//      (fill link), never delete, copy or share it.
//  Only the pages listed in routes/web.php exist under /shared and /fill. After a save on a fill link the customer is kept inside his pages:
//  the next input page, or the thank-you page when the next place is not one of his pages (statements, dashboard, the list of studies ...).
// ──────────────────────────────────────────────────────────────────

class PublicLink
{
    public function handle(Request $request, Closure $next, string $kind = SharingLink::VIEW): Response
    {
        $code = (string) $request->route('code');

        $link = SharingLink::with('project.company')->where('link_code', $code)->first();   // lazy loading is off: ask for everything needed together
        abort_if($link === null || $link->project === null || $link->type !== $kind, 404);

        if (! $link->isActive() || ($link->isFill() && $link->project->company?->isReadOnly())) {
            return Inertia::render('Shared/Closed', ['reason' => $link->unavailableReason()])->toResponse($request)->setStatusCode(410);
        }

        $project = $request->route('project');
        if ($project !== null) {
            $id = $project instanceof Project ? $project->id : (int) $project;
            abort_if($id !== (int) $link->project_id, 404);
        }

        // The controllers know nothing about the code: take it out of the route parameters (they are passed on by position).
        $request->route()->forgetParameter('code');
        $request->attributes->set('public_link', $link);

        $this->logVisit($request, $code);

        $response = $next($request);

        return $link->isFill() ? $this->keepInsideTheForm($response, $code) : $response;
    }

    /** After a save the controllers send the person to the next page of the app: for a customer that is his own next page, or the thank-you page. */
    private function keepInsideTheForm(Response $response, string $code): Response
    {
        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $path = (string) parse_url($response->getTargetUrl(), PHP_URL_PATH);

        if (str_starts_with($path, '/fill/'.$code.'/')) {
            return $response;   // already one of his pages (for example "back" to the page with the mistakes)
        }

        if (preg_match('~^/projects/\d+/(edit|raw-materials|manpower|expenses|fixed-assets|opening-balances)$~', $path)
            || preg_match('~^/projects/\d+/products/\d+/edit$~', $path)) {
            return $response->setTargetUrl(url('/fill/'.$code.$path));
        }

        return $response->setTargetUrl(route('fill.thanks', ['code' => $code]));
    }

    private function logVisit(Request $request, string $code): void
    {
        $ip = (string) $request->ip();

        $recent = SharingLinkVisitor::where('link_code', $code)->where('ip', $ip)->where('created_at', '>=', now()->subHour())->exists();
        if (! $recent) {
            SharingLinkVisitor::create(['link_code' => $code, 'ip' => $ip]);
        }
    }
}
