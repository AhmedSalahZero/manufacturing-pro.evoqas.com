<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Route alias 'company-admin': only the administrator of a company (to manage its users).
class EnsureCompanyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isCompanyAdmin() && $request->user()->company_id !== null, 403);

        return $next($request);
    }
}
