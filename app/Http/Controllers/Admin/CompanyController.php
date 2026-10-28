<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Admin\CompanyController (the super admin's companies)
//  Location: app/Http/Controllers/Admin/CompanyController.php
//
//  index    all companies                       /admin/companies
//  create   new company + its administrator     /admin/companies/create
//  store    save it
//  edit     company details + its people        /admin/companies/{company}/edit
//  update   save changes (status, limit, subscription dates ...)
//  password set a new password for one of the company's people (when e-mail is not available)
//  Companies are never deleted: suspend them instead.
// ══════════════════════════════════════════════════════════════════

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies)
    {
    }

    public function index(): Response
    {
        $companies = Company::query()
            ->withCount([
                'users as active_users' => fn ($q) => $q->where('is_active', true),
                'projects as studies',
            ])
            ->orderBy('name_en')
            ->get()
            ->map(fn (Company $c) => [
                'id'           => $c->id,
                'name_en'      => $c->name_en,
                'name_ar'      => $c->name_ar,
                'status'       => $c->status,
                'users_limit'  => $c->users_limit,
                'active_users' => $c->active_users,
                'studies'      => $c->studies,
                'ends'         => $c->subscription_ends_at?->format('Y-m-d'),
                'read_only'    => $c->isReadOnly(),
            ]);

        return Inertia::render('Admin/Companies/Index', ['companies' => $companies]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Companies/Form', [
            'company'  => null,
            'users'    => [],
            'statuses' => Company::STATUSES,
            'defaults' => [
                'users_limit'            => 5,
                'subscription_starts_at' => today()->format('Y-m-d'),
                'subscription_ends_at'   => today()->addYear()->format('Y-m-d'),
            ],
        ]);
    }

    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = $this->companies->createWithAdmin($request->validated(), $request->user());

        return redirect()->route('admin.companies.edit', $company)->with('success', __('companies.created'));
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('Admin/Companies/Form', [
            'company' => [
                'id'                     => $company->id,
                'name_en'                => $company->name_en,
                'name_ar'                => $company->name_ar,
                'status'                 => $company->status,
                'users_limit'            => $company->users_limit,
                'subscription_starts_at' => $company->subscription_starts_at?->format('Y-m-d'),
                'subscription_ends_at'   => $company->subscription_ends_at?->format('Y-m-d'),
                'contact_phone'          => $company->contact_phone,
                'contact_email'          => $company->contact_email,
                'notes'                  => $company->notes,
                'read_only'              => $company->isReadOnly(),
            ],
            'users' => $company->users()->orderBy('role')->orderBy('name')->get()->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role,
                'active'     => $u->is_active,
                'last_login' => $u->last_login_at?->format('Y-m-d'),
            ])->values(),
            'statuses' => Company::STATUSES,
            'defaults' => null,
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        return redirect()->route('admin.companies.edit', $company)->with('success', __('companies.saved'));
    }

    public function password(Request $request, Company $company, User $user): RedirectResponse
    {
        abort_unless((int) $user->company_id === (int) $company->id, 404);

        $data = $request->validate(
            ['password' => ['required', 'string', Password::defaults()]],
            [],
            ['password' => __('companies.fields.admin_password')],
        );

        $user->forceFill(['password' => $data['password']])->save();

        return back()->with('success', __('companies.passwordSet', ['name' => $user->name]));
    }
}
