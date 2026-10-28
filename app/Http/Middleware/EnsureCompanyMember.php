<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Route alias 'company': pages for people who belong to a company (studies, users).
// The super admin has no company, so he is sent to the list of companies instead.
class EnsureCompanyMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return redirect()->route('admin.companies.index');
        }

        abort_unless($user && $user->company_id !== null, 403);

        return $next($request);
    }
}
