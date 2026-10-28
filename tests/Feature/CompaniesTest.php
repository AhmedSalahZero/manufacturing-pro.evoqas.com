<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The super admin's companies, and what a company's status and subscription do to its people.
// Run:  php artisan test --filter=CompaniesTest
class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $override = []): array
    {
        return array_merge([
            'name_en'                => 'Delta Plastics',
            'name_ar'                => 'دلتا للبلاستيك',
            'status'                 => 'trial',
            'users_limit'            => 3,
            'subscription_starts_at' => '2026-10-01',
            'subscription_ends_at'   => '2027-09-30',
            'contact_phone'          => '0100',
            'contact_email'          => 'info@delta.test',
            'notes'                  => null,
            'admin_name'             => 'Sara Admin',
            'admin_email'            => 'Sara@Delta.test',
            'admin_password'         => 'Strong-Pass-2026',
        ], $override);
    }

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    // ── who may enter ───────────────────────────────────────

    public function test_only_the_super_admin_opens_the_companies(): void
    {
        $this->actingAs($this->superAdmin())->get('/admin/companies')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Companies/Index'));

        $this->actingAs(User::factory()->companyAdmin()->create())->get('/admin/companies')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/companies')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/admin/companies')->assertRedirect('/login');
    }

    public function test_nobody_but_the_super_admin_can_create_or_change_a_company(): void
    {
        $admin = User::factory()->companyAdmin()->create();

        $this->actingAs($admin)->post('/admin/companies', $this->form())->assertForbidden();
        $this->actingAs($admin)->put(route('admin.companies.update', $admin->company), ['name_en' => 'Hacked'])->assertForbidden();
        $this->assertSame(1, Company::count());
    }

    public function test_the_administrator_made_by_the_seeder_is_the_super_admin(): void
    {
        config(['zavero.admin' => ['name' => 'Owner', 'email' => 'owner@zavero.test', 'password' => 'Owner-Pass-2026']]);

        $this->seed(\Database\Seeders\AdminUserSeeder::class);

        $owner = User::where('email', 'owner@zavero.test')->firstOrFail();
        $this->assertTrue($owner->isSuperAdmin());
        $this->assertNull($owner->company_id);
    }

    public function test_the_super_admin_lands_on_the_companies_after_signing_in(): void
    {
        $this->actingAs($this->superAdmin())->get('/home')->assertRedirect(route('admin.companies.index'));
    }

    public function test_the_super_admin_does_not_work_with_studies(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->get('/projects')->assertRedirect(route('admin.companies.index'));
        $this->actingAs($super)->post('/projects', ['name' => 'X'])->assertRedirect(route('admin.companies.index'));
    }

    // ── create and edit ─────────────────────────────────────

    public function test_a_company_is_created_together_with_its_administrator(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->post('/admin/companies', $this->form())->assertSessionHasNoErrors();

        $company = Company::firstOrFail();
        $this->assertSame('Delta Plastics', $company->name_en);
        $this->assertSame(3, $company->users_limit);
        $this->assertSame($super->id, (int) $company->created_by);

        $admin = User::where('email', 'sara@delta.test')->firstOrFail();
        $this->assertSame(User::ROLE_COMPANY_ADMIN, $admin->role);
        $this->assertSame($company->id, $admin->company_id);
        $this->assertTrue(Hash::check('Strong-Pass-2026', $admin->password));
        $this->assertTrue($admin->is_active);
    }

    public function test_the_new_administrator_can_sign_in_and_reach_the_studies(): void
    {
        app(\App\Services\CompanyService::class)->createWithAdmin($this->form(), $this->superAdmin());

        $this->post('/login', ['email' => 'sara@delta.test', 'password' => 'Strong-Pass-2026'])->assertRedirect();
        $this->get('/projects')->assertOk();
    }

    public function test_a_company_needs_its_names_a_good_password_and_a_new_email(): void
    {
        $super = $this->superAdmin();
        User::factory()->create(['email' => 'taken@x.test']);

        $this->actingAs($super)->post('/admin/companies', $this->form(['name_en' => '', 'name_ar' => '']))->assertSessionHasErrors(['name_en', 'name_ar']);
        $this->actingAs($super)->post('/admin/companies', $this->form(['admin_password' => 'weak']))->assertSessionHasErrors('admin_password');
        $this->actingAs($super)->post('/admin/companies', $this->form(['admin_email' => 'taken@x.test']))->assertSessionHasErrors('admin_email');
        $this->actingAs($super)->post('/admin/companies', $this->form(['status' => 'gone']))->assertSessionHasErrors('status');
        $this->actingAs($super)->post('/admin/companies', $this->form(['subscription_ends_at' => '2026-01-01']))->assertSessionHasErrors('subscription_ends_at');

        $this->assertSame(0, Company::where('name_en', 'Delta Plastics')->count());
    }

    public function test_a_company_is_saved_with_nothing_changed_when_its_administrator_is_refused(): void
    {
        $super = $this->superAdmin();

        $this->actingAs($super)->post('/admin/companies', $this->form(['admin_password' => 'weak']))->assertSessionHasErrors();

        $this->assertSame(0, Company::where('name_en', 'Delta Plastics')->count());
        $this->assertNull(User::where('email', 'sara@delta.test')->first());
    }

    public function test_the_edit_screen_shows_the_company_and_its_people(): void
    {
        $company = Company::factory()->create(['name_en' => 'Delta']);
        User::factory()->companyAdmin()->forCompany($company)->create(['name' => 'Sara']);

        $this->actingAs($this->superAdmin())->get(route('admin.companies.edit', $company))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Companies/Form')
            ->where('company.name_en', 'Delta')
            ->has('users', 1)
            ->where('users.0.name', 'Sara'));
    }

    public function test_a_company_can_be_changed_and_suspended(): void
    {
        $company = Company::factory()->create();

        $this->actingAs($this->superAdmin())->put(route('admin.companies.update', $company), [
            'name_en' => 'New name', 'name_ar' => 'اسم جديد', 'status' => 'suspended', 'users_limit' => 9,
            'subscription_starts_at' => '2026-01-01', 'subscription_ends_at' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertSame('suspended', $company->status);
        $this->assertSame(9, $company->users_limit);
        $this->assertSame('2026-12-31', $company->subscription_ends_at->format('Y-m-d'));
    }

    public function test_the_user_limit_cannot_go_below_the_people_already_active(): void
    {
        $company = Company::factory()->create(['users_limit' => 5]);
        User::factory()->count(3)->forCompany($company)->create();

        $this->actingAs($this->superAdmin())->put(route('admin.companies.update', $company), [
            'name_en' => $company->name_en, 'name_ar' => $company->name_ar, 'status' => 'active', 'users_limit' => 2,
        ])->assertSessionHasErrors('users_limit');

        $this->assertSame(5, $company->refresh()->users_limit);
    }

    public function test_the_super_admin_can_set_a_new_password_for_a_person_of_the_company(): void
    {
        $company = Company::factory()->create();
        $person = User::factory()->forCompany($company)->create();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.companies.password', [$company, $person]), ['password' => 'Another-Pass-2026'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Another-Pass-2026', $person->refresh()->password));
    }

    public function test_a_password_cannot_be_set_for_a_person_of_another_company(): void
    {
        $company = Company::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.companies.password', [$company, $stranger]), ['password' => 'Another-Pass-2026'])
            ->assertNotFound();
    }

    public function test_a_weak_password_is_refused(): void
    {
        $company = Company::factory()->create();
        $person = User::factory()->forCompany($company)->create();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.companies.password', [$company, $person]), ['password' => 'weak'])
            ->assertSessionHasErrors('password');
    }

    // ── status and subscription ─────────────────────────────

    public function test_a_suspended_company_cannot_sign_in(): void
    {
        $company = Company::factory()->suspended()->create();
        User::factory()->forCompany($company)->create(['email' => 'p@x.test']);

        $this->post('/login', ['email' => 'p@x.test', 'password' => 'Test-Pass-2026'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_suspending_a_company_signs_its_people_out_at_once(): void
    {
        $company = Company::factory()->create();
        $person = User::factory()->forCompany($company)->create();

        $this->actingAs($person)->get('/projects')->assertOk();

        $company->update(['status' => 'suspended']);

        $this->actingAs($person->fresh())->get('/projects')->assertRedirect('/login');
    }

    public function test_an_ended_subscription_lets_people_look_but_not_change(): void
    {
        $company = Company::factory()->expired()->create();
        $person = User::factory()->forCompany($company)->create();

        $this->actingAs($person)->get('/projects')->assertOk();
        $this->actingAs($person)->post('/projects', ['name' => 'New'])->assertSessionHas('error');

        $this->assertSame(0, $company->projects()->count());
    }

    public function test_a_subscription_ending_today_still_works(): void
    {
        $company = Company::factory()->create(['subscription_ends_at' => today()]);
        $person = User::factory()->forCompany($company)->create();

        $this->actingAs($person)->post('/projects', ['name' => 'New'])->assertSessionHasNoErrors();

        $this->assertSame(1, $company->projects()->count());
    }

    public function test_a_company_without_an_end_date_never_becomes_read_only(): void
    {
        $company = Company::factory()->create(['subscription_ends_at' => null]);

        $this->assertFalse($company->isReadOnly());
        $this->assertNull($company->daysLeft());
    }

    public function test_the_screens_know_the_role_and_the_subscription(): void
    {
        $company = Company::factory()->create(['subscription_ends_at' => today()->addDays(10)]);
        $person = User::factory()->companyAdmin()->forCompany($company)->create();

        $this->actingAs($person)->get('/home')->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.role', 'company_admin')
            ->where('auth.user.company.days_left', 10)
            ->where('auth.user.company.read_only', false));
    }

    public function test_the_company_name_follows_the_language(): void
    {
        $company = Company::factory()->create(['name_en' => 'Delta', 'name_ar' => 'دلتا']);

        $this->assertSame('Delta', $company->displayName('en'));
        $this->assertSame('دلتا', $company->displayName('ar'));
    }
}
