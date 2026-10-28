<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Middleware;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — HandleInertiaRequests
//  Location: app/Http/Middleware/HandleInertiaRequests.php
//
//  What every screen receives as shared props (usePage().props in Vue):
//
//    auth.user  → the signed-in person: id, name, initials, email, language, theme, role, company
//                 (company = name, read_only, days_left, ends — drives the subscription notices)
//                 (null on the sign-in page)
//    theme      → 'light' | 'dark' for this request (account, else session, else light)
//    flash      → success / error / warning / info messages
//    study      → on every page of a study: its id, name, kind (new / existing company), whether it has raw materials and its
//                 products (id, name) for the second top bar; null everywhere else
//    locale     → 'en' | 'ar' for this request
//    timezone   → the application's time zone
//    app        → name and environment
//
//  Everything is a closure, so it is only worked out when a page is
//  actually drawn, never for redirects.
// ══════════════════════════════════════════════════════════════════

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => fn () => [
                'user' => ($user = $request->user()) ? [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'initials' => $user->initials(),
                    'email'    => $user->email,
                    'language' => $user->preferredLanguage(),
                    'theme'    => $user->preferredTheme(),
                    'role'     => $user->role,
                    'company'  => self::companyFor($user),
                ] : null,
            ],

            'theme' => fn () => self::themeFor($request),

            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info'    => $request->session()->get('info'),
            ],

            'study'    => fn () => self::studyFor($request),

            'locale'   => fn () => app()->getLocale(),
            'timezone' => config('app.timezone'),

            'app' => [
                'name' => config('app.name'),
                'env'  => config('app.env'),
            ],
        ];
    }

    /** What the study bar needs: only on the pages of a study the person was already allowed to open. */
    private static function studyFor(Request $request): ?array
    {
        $project = $request->route('project');

        if (! $project instanceof Project || ! $request->user()) {
            return null;
        }

        return [
            'id'          => $project->id,
            'name'        => $project->name,
            'new_company' => (bool) $project->new_company,
            'has_raw'     => $project->rawMaterials()->exists(),
            'products'    => $project->products()->orderBy('id')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
        ];
    }

    /** The person's company for the top bar and the subscription notices (null for the super admin). */
    private static function companyFor($user): ?array
    {
        $company = $user->isSuperAdmin() ? null : $user->company;

        return $company ? [
            'name'      => $company->displayName(),
            'read_only' => $company->isReadOnly(),
            'days_left' => $company->daysLeft(),
            'ends'      => $company->subscription_ends_at?->format('Y-m-d'),
        ] : null;
    }

    /** The theme for this request: the account's choice, else what a guest picked, else light. */
    public static function themeFor(Request $request): string
    {
        if ($user = $request->user()) {
            return $user->preferredTheme();
        }

        $session = $request->hasSession() ? $request->session()->get('theme') : null;

        return $session === 'dark' ? 'dark' : 'light';
    }
}
