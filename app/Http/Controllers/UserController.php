<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyUserRequest;
use App\Http\Requests\UpdateCompanyUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — UserController (the people of my company)
//  Location: app/Http/Controllers/UserController.php
//  Company administrator only. People are suspended, never deleted (their studies stay).
// ══════════════════════════════════════════════════════════════════

class UserController extends Controller
{
    public function index(): Response
    {
        $company = auth()->user()->company;

        return Inertia::render('Users/Index', [
            'users' => $company->users()->orderByDesc('is_active')->orderBy('name')->get()->map(fn (User $u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'job_title'  => $u->job_title,
                'role'       => $u->role,
                'active'     => $u->is_active,
                'last_login' => $u->last_login_at?->format('Y-m-d'),
            ])->values(),
            'limit'     => $company->users_limit,
            'active'    => $company->activeUsersCount(),
            'read_only' => $company->isReadOnly(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', ['person' => null]);
    }

    public function store(StoreCompanyUserRequest $request): RedirectResponse
    {
        $me = $request->user();
        $data = $request->validated();

        User::query()->forceCreate([
            'company_id'        => $me->company_id,
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => $data['password'],
            'job_title'         => $data['job_title'] ?? null,
            'role'              => $data['role'],
            'email_verified_at' => now(),
            'language'          => $me->language,
            'created_by'        => $me->id,
        ]);

        return redirect()->route('users.index')->with('success', __('users.created', ['name' => $data['name']]));
    }

    public function edit(User $user): Response
    {
        abort_unless((int) $user->company_id === (int) auth()->user()->company_id && ! $user->isSuperAdmin(), 404);

        return Inertia::render('Users/Form', [
            'person' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'job_title' => $user->job_title,
                'role'      => $user->role,
                'active'    => $user->is_active,
                'is_me'     => (int) $user->id === (int) auth()->id(),
            ],
        ]);
    }

    public function update(UpdateCompanyUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->forceFill([
            'name'      => $data['name'],
            'job_title' => $data['job_title'] ?? null,
            'role'      => $data['role'],
            'is_active' => $data['is_active'],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return redirect()->route('users.index')->with('success', __('users.saved', ['name' => $user->name]));
    }
}
