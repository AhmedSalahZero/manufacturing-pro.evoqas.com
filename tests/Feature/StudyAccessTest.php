<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// Who sees and may change which study inside a company, and sharing. Run:  php artisan test --filter=StudyAccessTest
class StudyAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $colleague;
    private User $admin;
    private Project $study;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->owner = User::factory()->forCompany($this->company)->create(['name' => 'Owner']);
        $this->colleague = User::factory()->forCompany($this->company)->create(['name' => 'Colleague']);
        $this->admin = User::factory()->companyAdmin()->forCompany($this->company)->create(['name' => 'Boss']);
        $this->study = app(ProjectService::class)->create($this->owner, 'Plant A');
    }

    private function share(User $by, array $shares)
    {
        return $this->actingAs($by)->put(route('projects.shares', $this->study), ['shares' => $shares]);
    }

    // ── who sees what ───────────────────────────────────────

    public function test_a_colleague_does_not_see_a_study_that_is_not_shared_with_him(): void
    {
        $this->actingAs($this->colleague)->get('/projects')->assertInertia(fn (Assert $p) => $p->has('studies', 0));
        $this->actingAs($this->colleague)->get(route('projects.edit', $this->study))->assertForbidden();
        $this->actingAs($this->colleague)->put(route('projects.update', $this->study), [])->assertForbidden();
        $this->actingAs($this->colleague)->post(route('projects.copy', $this->study), ['name' => 'Mine now'])->assertForbidden();
        $this->actingAs($this->colleague)->delete(route('projects.destroy', $this->study))->assertForbidden();
    }

    public function test_the_company_administrator_sees_every_study_of_the_company(): void
    {
        $this->actingAs($this->admin)->get('/projects')->assertInertia(fn (Assert $p) => $p
            ->has('studies', 1)->where('studies.0.name', 'Plant A')->where('studies.0.owner', 'Owner')
            ->where('studies.0.access', 'admin')->where('studies.0.can_share', true)->where('studies.0.can_delete', true));

        $this->actingAs($this->admin)->get(route('projects.edit', $this->study))->assertOk()->assertInertia(fn (Assert $p) => $p->where('readOnly', false));
    }

    public function test_the_owner_sees_his_study_as_owner(): void
    {
        $this->actingAs($this->owner)->get('/projects')->assertInertia(fn (Assert $p) => $p
            ->where('studies.0.access', 'owner')->where('studies.0.can_share', true)->has('colleagues', 2));
    }

    public function test_nobody_of_another_company_sees_anything(): void
    {
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get('/projects')->assertInertia(fn (Assert $p) => $p->has('studies', 0));
        $this->actingAs($stranger)->get(route('projects.edit', $this->study))->assertForbidden();
        $this->actingAs($stranger)->delete(route('projects.destroy', $this->study))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.shares', $this->study), ['shares' => []])->assertForbidden();
    }

    // ── sharing ─────────────────────────────────────────────

    public function test_a_study_shared_for_viewing_can_be_opened_and_copied_but_not_changed(): void
    {
        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => false]])->assertSessionHasNoErrors();

        $this->actingAs($this->colleague)->get('/projects')->assertInertia(fn (Assert $p) => $p
            ->has('studies', 1)->where('studies.0.access', 'view')->where('studies.0.can_share', false)->where('studies.0.can_delete', false));

        $this->actingAs($this->colleague)->get(route('projects.edit', $this->study))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('readOnly', true)->where('project.owner', 'Owner'));

        $this->actingAs($this->colleague)->put(route('projects.update', $this->study), [])->assertForbidden();
        $this->actingAs($this->colleague)->delete(route('projects.destroy', $this->study))->assertForbidden();
        $this->actingAs($this->colleague)->put(route('projects.shares', $this->study), ['shares' => []])->assertForbidden();

        $this->actingAs($this->colleague)->post(route('projects.copy', $this->study), ['name' => 'My copy'])->assertSessionHasNoErrors();
        $copy = Project::where('name', 'My copy')->firstOrFail();
        $this->assertSame($this->colleague->id, $copy->user_id);
        $this->assertSame($this->company->id, $copy->company_id);
    }

    public function test_a_study_shared_for_editing_can_be_changed(): void
    {
        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => true]])->assertSessionHasNoErrors();

        $this->actingAs($this->colleague)->get(route('projects.edit', $this->study))->assertInertia(fn (Assert $p) => $p->where('readOnly', false));

        $this->actingAs($this->colleague)->put(route('projects.update', $this->study), [
            'name' => 'Plant A v2', 'new_company' => true, 'main_functional_currency' => 'egp', 'start_date' => '2026-01', 'duration' => 1,
            'operation_start_date' => '2026-01', 'tax_rate' => 22.5,
            'products' => [['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-02', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
            'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 30, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Plant A v2', $this->study->refresh()->name);
        // Still the owner's study.
        $this->assertSame($this->owner->id, $this->study->user_id);

        // Editing never allows deleting or re-sharing.
        $this->actingAs($this->colleague)->delete(route('projects.destroy', $this->study))->assertForbidden();
    }

    public function test_the_company_administrator_can_share_and_the_list_remembers_it(): void
    {
        $this->share($this->admin, [['user_id' => $this->colleague->id, 'can_edit' => true]])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get('/projects')->assertInertia(fn (Assert $p) => $p
            ->where('studies.0.shared_with', 1)
            ->where('studies.0.shares.0.user_id', $this->colleague->id)
            ->where('studies.0.shares.0.can_edit', true));
    }

    public function test_sharing_again_replaces_the_list(): void
    {
        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => false]]);
        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => true]]);
        $this->assertSame(1, $this->study->shares()->count());
        $this->assertTrue((bool) $this->study->shares()->first()->can_edit);

        $this->share($this->owner, [])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->study->shares()->count());
        $this->actingAs($this->colleague)->get(route('projects.edit', $this->study))->assertForbidden();
    }

    public function test_a_study_cannot_be_shared_outside_the_company_or_with_the_owner(): void
    {
        $stranger = User::factory()->create();
        $suspended = User::factory()->forCompany($this->company)->suspended()->create();

        $this->share($this->owner, [['user_id' => $stranger->id, 'can_edit' => false]])->assertSessionHasErrors('shares.0.user_id');
        $this->share($this->owner, [['user_id' => $this->owner->id, 'can_edit' => false]])->assertSessionHasErrors('shares.0.user_id');
        $this->share($this->owner, [['user_id' => $suspended->id, 'can_edit' => false]])->assertSessionHasErrors('shares.0.user_id');
        $this->share($this->owner, [['user_id' => 999999, 'can_edit' => false]])->assertSessionHasErrors('shares.0.user_id');
        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => false], ['user_id' => $this->colleague->id, 'can_edit' => true]])->assertSessionHasErrors();

        $this->assertSame(0, $this->study->shares()->count());
    }

    public function test_the_company_administrator_can_delete_any_study(): void
    {
        $this->actingAs($this->admin)->delete(route('projects.destroy', $this->study))->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted($this->study);
    }

    public function test_an_administrators_copy_of_a_study_belongs_to_him(): void
    {
        $this->actingAs($this->admin)->post(route('projects.copy', $this->study), ['name' => 'Boss copy'])->assertSessionHasNoErrors();

        $copy = Project::where('name', 'Boss copy')->firstOrFail();
        $this->assertSame($this->admin->id, $copy->user_id);
        $this->assertSame($this->owner->id, $this->study->refresh()->user_id);
        $this->assertSame(0, $copy->shares()->count());
    }

    public function test_deleting_a_study_removes_its_sharing(): void
    {
        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => false]]);

        $this->study->forceDelete();

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('project_shares')->count());
    }

    public function test_a_read_only_company_cannot_share(): void
    {
        $this->company->update(['subscription_ends_at' => today()->subDay()]);

        $this->share($this->owner, [['user_id' => $this->colleague->id, 'can_edit' => false]])->assertSessionHas('error');
        $this->assertSame(0, $this->study->shares()->count());
    }
}
