<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Excel\ModelDataBuilder;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The "Excel Model" download of a study. Run:  php artisan test --filter=ExcelModelTest
class ExcelModelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    private function study(bool $ready = true): Project
    {
        $project = app(ProjectService::class)->create($this->owner, 'Plant A');

        if ($ready) {
            $this->actingAs($this->owner)->put(route('projects.update', $project), [
                'name' => 'Plant A', 'new_company' => true, 'main_functional_currency' => 'egp', 'intro_paragraph' => '', 'business_sector_id' => null,
                'start_date' => '2026-01', 'duration' => 3, 'operation_start_date' => '2026-01', 'tax_rate' => 22.5, 'return_rate' => 15, 'perpetual_growth_rate' => 3,
                'products' => [['name' => 'Valve', 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1]],
                'rawMaterials' => [['name' => 'Brass', 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0]],
            ])->assertSessionHasNoErrors();
        }

        return $project->refresh();
    }

    public function test_the_owner_downloads_a_workbook(): void
    {
        $project = $this->study();

        $response = $this->actingAs($this->owner)->get(route('projects.excel', $project))->assertOk();

        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="ZAVERO-Model-plant-a-en.xlsx"', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $response->getContent());   // an .xlsx is a zip
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_workbook_follows_the_language_of_the_account(): void
    {
        $project = $this->study();
        $this->owner->update(['language' => 'ar']);

        $response = $this->actingAs($this->owner->fresh())->get(route('projects.excel', $project))->assertOk();

        $this->assertStringContainsString('-ar.xlsx', $response->headers->get('Content-Disposition'));
    }

    public function test_a_study_without_a_product_is_not_ready(): void
    {
        $project = $this->study(false);

        $this->actingAs($this->owner)->get(route('projects.excel', $project))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('error');
    }

    public function test_a_stranger_cannot_download(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.excel', $project))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $project = $this->study();
        $this->app['auth']->forgetGuards();

        $this->get(route('projects.excel', $project))->assertRedirect('/login');
    }

    public function test_the_studies_list_says_which_studies_can_be_exported(): void
    {
        $ready = $this->study();
        $empty = app(ProjectService::class)->create($this->owner, 'Empty');

        $page = $this->actingAs($this->owner)->get(route('projects.index'))->assertOk()->viewData('page');
        $props = json_decode(is_string($page) ? $page : json_encode($page), true)['props'];
        $flags = collect($props['studies'] ?? $props['projects'] ?? [])->pluck('can_excel', 'id');

        $this->assertTrue((bool) $flags[$ready->id]);
        $this->assertFalse((bool) $flags[$empty->id]);
    }

    public function test_the_study_dates_are_plain_days_whatever_the_time_zone(): void
    {
        config(['app.timezone' => 'Africa/Cairo']);
        date_default_timezone_set('Africa/Cairo');
        $project = $this->study();

        $row = ModelDataBuilder::fromProject($project)['project'];

        // toArray() alone would give 2025-12-31T22:00:00Z for a Cairo midnight, and the workbook would start a month early
        $this->assertSame('2026-01-01', $row['start_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['end_date']);
    }
}
