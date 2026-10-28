<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicStudyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SensitivityController;
use App\Http\Controllers\SharingLinkController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ExcelModelController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\ManpowerController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectShareController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\RawMaterialPaymentController;
use App\Http\Controllers\StatementsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Web routes
//  Location: routes/web.php
//
//  Sign-in routes are in routes/auth.php. Each planning module adds its own
//  routes inside the signed-in group below, in the step that builds it.
// ══════════════════════════════════════════════════════════════════

// The address of the site itself: signed in → home, otherwise → sign-in page.
Route::get('/', fn () => auth()->check() ? redirect()->route('home') : redirect()->route('login'));

// The offline helper and the app manifest (see App\Http\Controllers\PwaController).
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.sw');
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// Browser security reports (see App\Http\Middleware\SecurityHeaders).
Route::post('/csp-report', CspReportController::class)->name('csp.report');

// Language and theme: also used by guests on the sign-in page (kept in the session).
Route::post('/preferences', [PreferenceController::class, 'update'])->name('preferences.update');

// A study shared by a public link: no sign-in, read-only (see App\Http\Controllers\PublicStudyController).
Route::get('/shared/{code}', [PublicStudyController::class, 'show'])->middleware('throttle:60,1')->name('shared.show');

// ── Signed-in area ────────────────────────────────────────────────
// A company whose subscription ended is read-only: 'read-only' refuses every change (looking is always allowed).
Route::middleware(['auth', 'active', 'no-duplicate', 'read-only'])->group(function () {
    Route::get('/home', HomeController::class)->name('home');

    // Platform (super admin only): the companies and their administrators.
    Route::middleware('super')->prefix('admin')->group(function () {
        Route::get('/companies', [CompanyController::class, 'index'])->name('admin.companies.index');
        Route::get('/companies/create', [CompanyController::class, 'create'])->name('admin.companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('admin.companies.store');
        Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('admin.companies.edit');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('admin.companies.update');
        Route::put('/companies/{company}/users/{user}/password', [CompanyController::class, 'password'])->name('admin.companies.password');
    });

    // People of a company.
    Route::middleware('company')->group(function () {
        // Studies (projects): list, create, study form, copy, delete, share. Who may do what: App\Policies\ProjectPolicy.
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
        Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::post('/projects/{project}/copy', [ProjectController::class, 'copy'])->name('projects.copy');
        Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::put('/projects/{project}/shares', [ProjectShareController::class, 'update'])->name('projects.shares');

        // Sales, inventory and collections of one product; how the raw materials are paid for.
        Route::get('/projects/{project}/products/{product}/edit', [ProductController::class, 'edit'])->scopeBindings()->name('projects.products.edit');
        Route::put('/projects/{project}/products/{product}', [ProductController::class, 'update'])->scopeBindings()->name('projects.products.update');
        Route::get('/projects/{project}/raw-materials', [RawMaterialPaymentController::class, 'edit'])->name('projects.raw-materials.edit');
        Route::put('/projects/{project}/raw-materials', [RawMaterialPaymentController::class, 'update'])->name('projects.raw-materials.update');

        // Staff, expenses and fixed assets of the study.
        Route::get('/projects/{project}/manpower', [ManpowerController::class, 'edit'])->name('projects.manpower.edit');
        Route::put('/projects/{project}/manpower', [ManpowerController::class, 'update'])->name('projects.manpower.update');
        Route::get('/projects/{project}/expenses', [ExpenseController::class, 'edit'])->name('projects.expenses.edit');
        Route::put('/projects/{project}/expenses', [ExpenseController::class, 'update'])->name('projects.expenses.update');
        Route::get('/projects/{project}/fixed-assets', [FixedAssetController::class, 'edit'])->name('projects.assets.edit');
        Route::put('/projects/{project}/fixed-assets', [FixedAssetController::class, 'update'])->name('projects.assets.update');
        Route::get('/projects/{project}/opening-balances', [OpeningBalanceController::class, 'edit'])->name('projects.opening.edit');
        Route::put('/projects/{project}/opening-balances', [OpeningBalanceController::class, 'update'])->name('projects.opening.update');

        // The result: income statement, cash flow and balance sheet (calculated, read-only).
        Route::get('/projects/{project}/statements', [StatementsController::class, 'show'])->name('projects.statements');

        // Dashboard, what-if (sensitivity), the printable report, and the public links of the study.
        Route::get('/projects/{project}/dashboard', [DashboardController::class, 'show'])->name('projects.dashboard');
        Route::put('/projects/{project}/dashboard/comments', [DashboardController::class, 'comments'])->name('projects.dashboard.comments');
        Route::get('/projects/{project}/sensitivity', [SensitivityController::class, 'show'])->name('projects.sensitivity');
        Route::put('/projects/{project}/sensitivity', [SensitivityController::class, 'update'])->name('projects.sensitivity.update');
        Route::get('/projects/{project}/report', [ReportController::class, 'show'])->name('projects.report');
        Route::get('/projects/{project}/excel-model', [ExcelModelController::class, 'download'])->name('projects.excel');
        Route::get('/projects/{project}/links', [SharingLinkController::class, 'index'])->name('projects.links');
        Route::post('/projects/{project}/links', [SharingLinkController::class, 'store'])->name('projects.links.store');
        Route::put('/projects/{project}/links/{link}/toggle', [SharingLinkController::class, 'toggle'])->name('projects.links.toggle');
        Route::delete('/projects/{project}/links/{link}', [SharingLinkController::class, 'destroy'])->name('projects.links.destroy');

        // The people of my company (company administrator only).
        Route::middleware('company-admin')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        });
    });
});

require __DIR__.'/auth.php';
