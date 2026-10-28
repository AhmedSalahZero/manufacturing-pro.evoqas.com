<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The people of a company, managed by the company administrator. Run:  php artisan test --filter=CompanyUsersTest
class CompanyUsersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['users_limit' => 3]);
        $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create(['email' => 'boss@x.test']);
    }

    private function form(array $override = []): array
    {
        return array_merge([
            'name' => 'Lina Hassan', 'email' => 'Lina@X.test', 'password' => 'Strong-Pass-2026',
            'job_title' => 'Cost accountant', 'role' => 'user',
        ], $override);
    }

    // ── who may enter ───────────────────────────────────────

    public function test_only_the_company_administrator_opens_the_people(): void
    {
        $this->actingAs($this->admin)->get('/users')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Users/Index')->where('limit', 3)->where('active', 1)->has('users', 1));

        $this->actingAs(User::factory()->forCompany($this->company)->create())->get('/users')->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->get('/users')->assertRedirect(route('admin.companies.index'));
    }

    public function test_an_ordinary_user_cannot_add_or_change_people(): void
    {
        $user = User::factory()->forCompany($this->company)->create();

        $this->actingAs($user)->post('/users', $this->form())->assertForbidden();
        $this->actingAs($user)->put(route('users.update', $this->admin), ['name' => 'X', 'role' => 'user', 'is_active' => false])->assertForbidden();
    }

    public function test_the_list_shows_only_my_company(): void
    {
        User::factory()->create(['name' => 'Stranger']);
        User::factory()->forCompany($this->company)->create(['name' => 'Colleague']);

        $this->actingAs($this->admin)->get('/users')->assertInertia(fn (Assert $p) => $p
            ->has('users', 2)
            ->where('users', fn ($users) => collect($users)->pluck('name')->doesntContain('Stranger')));
    }

    // ── add ─────────────────────────────────────────────────

    public function test_a_person_is_added_to_my_company(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->form())->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $lina = User::where('email', 'lina@x.test')->firstOrFail();
        $this->assertSame($this->company->id, $lina->company_id);
        $this->assertSame(User::ROLE_USER, $lina->role);
        $this->assertSame('Cost accountant', $lina->job_title);
        $this->assertTrue(Hash::check('Strong-Pass-2026', $lina->password));
        $this->assertSame($this->admin->id, (int) $lina->created_by);
    }

    public function test_the_person_cannot_choose_another_company_or_the_super_admin_role(): void
    {
        $other = Company::factory()->create();

        $this->actingAs($this->admin)->post('/users', $this->form(['company_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($this->company->id, User::where('email', 'lina@x.test')->firstOrFail()->company_id);

        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'evil@x.test', 'role' => 'super_admin']))->assertSessionHasErrors('role');
        $this->assertNull(User::where('email', 'evil@x.test')->first());
    }

    public function test_a_second_administrator_can_be_added(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->form(['role' => 'company_admin']))->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'lina@x.test')->firstOrFail()->isCompanyAdmin());
    }

    public function test_the_checks_on_a_new_person(): void
    {
        User::factory()->create(['email' => 'taken@x.test']);

        $this->actingAs($this->admin)->post('/users', $this->form(['name' => '']))->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post('/users', $this->form(['password' => 'weak']))->assertSessionHasErrors('password');
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'taken@x.test']))->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'not-an-email']))->assertSessionHasErrors('email');
    }

    public function test_the_user_limit_is_enforced(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'a@x.test']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'b@x.test']))->assertSessionHasNoErrors();

        // The limit is 3: the administrator and two more.
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'c@x.test']))->assertSessionHasErrors('email');
        $this->assertNull(User::where('email', 'c@x.test')->first());
    }

    public function test_a_suspended_person_does_not_count_toward_the_limit(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'a@x.test']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'b@x.test']))->assertSessionHasNoErrors();
        User::where('email', 'b@x.test')->update(['is_active' => false]);

        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'c@x.test']))->assertSessionHasNoErrors();
        $this->assertNotNull(User::where('email', 'c@x.test')->first());
    }

    // ── change ──────────────────────────────────────────────

    public function test_a_person_can_be_changed_and_given_a_new_password(): void
    {
        $lina = User::factory()->forCompany($this->company)->create(['name' => 'Lina']);

        $this->actingAs($this->admin)->put(route('users.update', $lina), [
            'name' => 'Lina H.', 'job_title' => 'CFO', 'role' => 'company_admin', 'is_active' => true, 'password' => 'Another-Pass-2026',
        ])->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $lina->refresh();
        $this->assertSame('Lina H.', $lina->name);
        $this->assertSame('CFO', $lina->job_title);
        $this->assertTrue($lina->isCompanyAdmin());
        $this->assertTrue(Hash::check('Another-Pass-2026', $lina->password));
    }

    public function test_an_empty_password_keeps_the_old_one(): void
    {
        $lina = User::factory()->forCompany($this->company)->create();

        $this->actingAs($this->admin)->put(route('users.update', $lina), ['name' => 'Lina', 'role' => 'user', 'is_active' => true, 'password' => null])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Test-Pass-2026', $lina->refresh()->password));
    }

    public function test_a_person_can_be_suspended(): void
    {
        $lina = User::factory()->forCompany($this->company)->create(['email' => 'lina@x.test']);

        $this->actingAs($this->admin)->put(route('users.update', $lina), ['name' => 'Lina', 'role' => 'user', 'is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($lina->refresh()->is_active);
    }

    public function test_the_administrator_cannot_suspend_or_demote_himself(): void
    {
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), ['name' => 'Boss', 'role' => 'company_admin', 'is_active' => false])->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), ['name' => 'Boss', 'role' => 'user', 'is_active' => true])->assertSessionHasErrors('role');

        $this->assertTrue($this->admin->refresh()->is_active);
        $this->assertTrue($this->admin->isCompanyAdmin());
    }

    public function test_bringing_a_suspended_person_back_needs_room_under_the_limit(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'a@x.test']));
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'b@x.test']));
        $a = User::where('email', 'a@x.test')->firstOrFail();
        $a->update(['is_active' => false]);
        $this->actingAs($this->admin)->post('/users', $this->form(['email' => 'c@x.test']))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->put(route('users.update', $a), ['name' => 'A', 'role' => 'user', 'is_active' => true])->assertSessionHasErrors('is_active');
        $this->assertFalse($a->refresh()->is_active);
    }

    public function test_a_person_of_another_company_cannot_be_opened_or_changed(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->admin)->get(route('users.edit', $stranger))->assertNotFound();
        $this->actingAs($this->admin)->put(route('users.update', $stranger), ['name' => 'X', 'role' => 'user', 'is_active' => false])->assertNotFound();
        $this->assertTrue($stranger->refresh()->is_active);
    }

    public function test_the_super_admin_cannot_be_opened_from_a_company(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($this->admin)->get(route('users.edit', $super))->assertNotFound();
    }

    public function test_the_edit_form_opens(): void
    {
        $this->actingAs($this->admin)->get(route('users.edit', $this->admin))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Users/Form')->where('person.email', 'boss@x.test')->where('person.is_me', true));
    }

    public function test_a_read_only_company_cannot_add_people(): void
    {
        $this->company->update(['subscription_ends_at' => today()->subDay()]);

        $this->actingAs($this->admin)->post('/users', $this->form())->assertSessionHas('error');
        $this->assertNull(User::where('email', 'lina@x.test')->first());
    }
}
