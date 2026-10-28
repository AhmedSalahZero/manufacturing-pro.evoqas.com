<?php

namespace Tests\Feature;

use App\Models\IncomeStatement;
use App\Models\Sensitivity;
use App\Models\User;
use App\Services\SensitivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsStudy;
use Tests\TestCase;

// The what-if screen: three settings per product, saved apart, calculated on a rolled-back copy of the study.
// Run:  php artisan test --filter=SensitivityTest
class SensitivityTest extends TestCase
{
    use BuildsStudy;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    private function save($project, array $valve, array $pump = ['target' => 0, 'rm' => 0, 'days' => 0])
    {
        $ids = $project->products()->orderBy('id')->pluck('id');

        return $this->actingAs($this->owner)->put(route('projects.sensitivity.update', $project), ['rows' => [
            ['id' => $ids[0]] + $valve,
            ['id' => $ids[1]] + $pump,
        ]]);
    }

    public function test_a_guest_is_sent_to_sign_in_and_a_stranger_is_refused(): void
    {
        $project = $this->study();
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get(route('projects.sensitivity', $project))->assertForbidden();
        $this->actingAs($stranger)->put(route('projects.sensitivity.update', $project), ['rows' => []])->assertForbidden();
        $this->asGuest();
        $this->get(route('projects.sensitivity', $project))->assertRedirect('/login');
    }

    public function test_with_no_changes_there_is_no_comparison(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->get(route('projects.sensitivity', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Sensitivity/Show')->where('ready', true)->where('compared', false)->has('rows', 2)->missing('scenario'));
    }

    public function test_the_settings_are_saved_per_product_in_the_order_of_the_products(): void
    {
        $project = $this->study();

        $this->save($project, ['target' => -10, 'rm' => 5.5, 'days' => 30], ['target' => 20, 'rm' => 0, 'days' => -15])->assertSessionHasNoErrors();

        $row = Sensitivity::where('project_id', $project->id)->first();
        $this->assertEqualsWithDelta(-10.0, (float) $row->target_product_first, 0.001);
        $this->assertEqualsWithDelta(5.5, (float) $row->rm_product_first, 0.001);
        $this->assertSame(30, (int) $row->collections_product_first);
        $this->assertEqualsWithDelta(20.0, (float) $row->target_product_second, 0.001);
        $this->assertSame(-15, (int) $row->collections_product_second);
        $this->assertEqualsWithDelta(0.0, (float) $row->target_product_third, 0.001);
    }

    public function test_saving_again_replaces_the_settings(): void
    {
        $project = $this->study();

        $this->save($project, ['target' => -10, 'rm' => 0, 'days' => 0])->assertSessionHasNoErrors();
        $this->save($project, ['target' => 4, 'rm' => 0, 'days' => 0])->assertSessionHasNoErrors();

        $this->assertSame(1, Sensitivity::where('project_id', $project->id)->count());
        $this->assertEqualsWithDelta(4.0, (float) Sensitivity::where('project_id', $project->id)->first()->target_product_first, 0.001);
    }

    public function test_a_sales_target_change_moves_the_revenue_in_the_copy_and_never_in_the_study(): void
    {
        $project = $this->study();
        $before = IncomeStatement::where('project_id', $project->id)->first()->total_sales_revenues;
        $beforeProduct = $project->products()->orderBy('id')->first()->monthly_sales_target_values;
        $beforeQuantities = $project->products()->orderBy('id')->first()->local_target_quantities;

        $this->save($project, ['target' => 10, 'rm' => 0, 'days' => 0])->assertSessionHasNoErrors();
        $props = $this->props($this->actingAs($this->owner)->get(route('projects.sensitivity', $project))->assertOk());

        $this->assertTrue($props['compared']);
        $this->assertEqualsWithDelta(36000.0, array_sum($props['base']['revenue']['value']), 0.5);
        $this->assertEqualsWithDelta(39600.0, array_sum($props['scenario']['revenue']['value']), 0.5, '10 % more units');

        // The study itself is exactly as it was.
        $this->assertEquals($before, IncomeStatement::where('project_id', $project->id)->first()->total_sales_revenues);
        $this->assertEquals($beforeProduct, $project->products()->orderBy('id')->first()->monthly_sales_target_values);
        $this->assertEquals($beforeQuantities, $project->products()->orderBy('id')->first()->local_target_quantities);
    }

    public function test_later_collection_changes_the_cash_but_not_the_revenue(): void
    {
        $project = $this->study();

        $this->save($project, ['target' => 0, 'rm' => 0, 'days' => 60])->assertSessionHasNoErrors();
        $props = $this->props($this->actingAs($this->owner)->get(route('projects.sensitivity', $project))->assertOk());

        $this->assertEqualsWithDelta(array_sum($props['base']['revenue']['value']), array_sum($props['scenario']['revenue']['value']), 0.01);
        $this->assertNotEquals(
            round(array_sum($props['base']['ratios']['dso']), 2),
            round(array_sum($props['scenario']['ratios']['dso']), 2),
            'customers pay later, so the days of sales outstanding grow'
        );
        $this->assertGreaterThan(array_sum($props['base']['ratios']['dso']), array_sum($props['scenario']['ratios']['dso']));
    }

    public function test_the_study_is_unchanged_after_the_comparison_even_for_collection_terms(): void
    {
        $project = $this->study();
        $terms = $project->products()->orderBy('id')->first()->collection_policy_value;

        $this->save($project, ['target' => 0, 'rm' => 0, 'days' => 45])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->get(route('projects.sensitivity', $project))->assertOk();

        $this->assertEquals($terms, $project->products()->orderBy('id')->first()->collection_policy_value);
    }

    public function test_values_out_of_range_or_for_another_study_are_refused(): void
    {
        $project = $this->study();
        $mine = $this->owner;
        $this->owner = User::factory()->create();
        $other = $this->study();
        $this->owner = $mine;
        $foreign = $other->products()->orderBy('id')->first()->id;
        $own = $project->products()->orderBy('id')->first()->id;

        $this->actingAs($this->owner)->put(route('projects.sensitivity.update', $project), ['rows' => [['id' => $own, 'target' => -150, 'rm' => 0, 'days' => 0]]])
            ->assertSessionHasErrors('rows.0.target');
        $this->actingAs($this->owner)->put(route('projects.sensitivity.update', $project), ['rows' => [['id' => $own, 'target' => 0, 'rm' => 0, 'days' => 400]]])
            ->assertSessionHasErrors('rows.0.days');
        $this->actingAs($this->owner)->put(route('projects.sensitivity.update', $project), ['rows' => [['id' => $foreign, 'target' => 1, 'rm' => 0, 'days' => 0]]])
            ->assertSessionHasErrors('rows.0.id');
    }

    // ── The small rules (no database) ───────────────────────────────────────

    public function test_scale_multiplies_every_number_even_inside_lists(): void
    {
        $scaled = SensitivityService::scale([0 => 100, 1 => '200', 'x' => ['a' => 5]], 1.1);

        $this->assertEqualsWithDelta(110.0, $scaled[0], 0.000001);
        $this->assertEqualsWithDelta(220.0, $scaled[1], 0.000001);
        $this->assertEqualsWithDelta(5.5, $scaled['x']['a'], 0.000001);
    }

    public function test_delay_adds_days_to_every_due_date_but_never_below_zero(): void
    {
        $policy = ['local' => [['cash_payment' => 20, 'rate' => [80, 0], 'due_in_days' => [30, null]], ['cash_payment' => 100, 'rate' => [0, 0], 'due_in_days' => [60, 90]]], 'export' => [['due_in_days' => [30, 60]]]];

        $later = SensitivityService::delay($policy, 30);
        $this->assertSame([60, null], $later['local'][0]['due_in_days']);
        $this->assertSame([90, 120], $later['local'][1]['due_in_days']);
        $this->assertSame([60, 90], $later['export'][0]['due_in_days']);

        $sooner = SensitivityService::delay($policy, -45);
        $this->assertSame([0, null], $sooner['local'][0]['due_in_days']);
        $this->assertSame([15, 45], $sooner['local'][1]['due_in_days']);
    }
}
