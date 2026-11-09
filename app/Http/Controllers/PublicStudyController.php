<?php

namespace App\Http\Controllers;

use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

// ──────────────────────────────────────────────────────────────────
//  ZAVERO Manufacturing Pro — PublicStudyController (the addresses of the public sharing links)
//  Location: app/Http/Controllers/PublicStudyController.php
//
//  show    GET /shared/{code}   a "view" link: opens the first page of the study; the visitor walks through every page, looking only.
//  fill    GET /fill/{code}     a "fill" link: opens the first page of the study; the customer fills in the input pages (study form,
//          products, raw materials, manpower, expenses, fixed assets and, for an existing company, opening balances) and saves them
//          straight into the study. He never sees the statements, the dashboard, what-if, the report or the links.
//  thanks  GET /fill/{code}/thanks   the last page of a customer.
//  An unknown code is a 404; a link that is paused or past its expiry date shows a "closed" page (410). Each visit is logged (the address
//  of the visitor, once an hour per address) so the owner can see who looked. The pages themselves are in routes/web.php under
//  /shared/{code}/projects/... and /fill/{code}/projects/..., guarded by App\Http\Middleware\PublicLink.
// ──────────────────────────────────────────────────────────────────

class PublicStudyController extends Controller
{
    public function show(Request $request, string $code): Response
    {
        return $this->enter($request, $code, SharingLink::VIEW, 'shared.projects.edit');
    }

    public function fill(Request $request, string $code): Response
    {
        return $this->enter($request, $code, SharingLink::FILL, 'fill.projects.edit');
    }

    public function thanks(Request $request): Response
    {
        $link = $request->attributes->get('public_link');

        return Inertia::render('Shared/Thanks', [
            'project' => ['id' => $link->project->id, 'name' => $link->project->name],
        ])->toResponse($request);
    }

    private function enter(Request $request, string $code, string $kind, string $firstPage): Response
    {
        $link = SharingLink::with('project.company')->where('link_code', $code)->first();   // lazy loading is off: ask for everything needed together
        abort_if($link === null || $link->project === null || $link->type !== $kind, 404);

        if (! $link->isActive() || ($link->isFill() && $link->project->company?->isReadOnly())) {
            return Inertia::render('Shared/Closed', ['reason' => $link->unavailableReason()])->toResponse($request)->setStatusCode(410);
        }

        $this->logVisit($request, $code);

        return redirect()->route($firstPage, ['code' => $code, 'project' => $link->project->id]);
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
