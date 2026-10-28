<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SkyHealthySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The Sky Healthy Food eggshell study (7 years, new company). Run:  php artisan test --filter=SkyHealthySeederTest
class SkyHealthySeederTest extends TestCase
{
    use RefreshDatabase;

    private function study(): Project
    {
        return Project::where('name', SkyHealthySeeder::STUDY)->firstOrFail();
    }

    private function owner(Project $project): User
    {
        return User::where('company_id', $project->company_id)->orderBy('id')->firstOrFail();
    }

    public function test_it_makes_the_sky_company_and_its_administrator(): void
    {
        $this->seed(SkyHealthySeeder::class);

        $user = User::where('email', SkyHealthySeeder::EMAIL)->firstOrFail();
        $this->assertSame('admin@sky.com', $user->email);
        $this->assertTrue(Hash::check('Sky@Zavero2026', $user->password));
        $this->assertTrue($user->isCompanyAdmin());
        $this->assertSame('Sky Healthy Food', Company::findOrFail($user->company_id)->name_en);
        $this->assertSame($user->company_id, $this->study()->company_id);

        $this->post('/login', ['email' => 'admin@sky.com', 'password' => 'Sky@Zavero2026'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_study_is_never_put_in_the_demo_company(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(SkyHealthySeeder::class);

        $demo = User::where('email', DemoSeeder::EMAIL)->firstOrFail();
        $sky = User::where('email', SkyHealthySeeder::EMAIL)->firstOrFail();

        $this->assertNotSame($demo->company_id, $sky->company_id);
        $this->assertSame($sky->company_id, $this->study()->company_id);
        $this->assertSame(0, Project::where('company_id', $demo->company_id)->where('name', SkyHealthySeeder::STUDY)->count());
        $this->assertSame(2, Project::where('company_id', $demo->company_id)->count(), 'the demo company keeps only its own two studies');
    }

    public function test_the_study_has_the_agreed_structure(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();

        $this->assertSame(7, (int) $project->duration);
        $this->assertTrue((bool) $project->new_company);
        $this->assertSame('egp', $project->main_functional_currency);
        $this->assertSame('2027-01', $project->start_date->format('Y-m'));
        // Study starts January 2027 (0); operation and first sales July 2027 (6); membrane powder October 2027 (9).
        $this->assertSame(6, $project->operation_start_date);
        $this->assertSame([6, 6, 9], $project->products()->orderBy('id')->pluck('selling_start_date')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['SKY-CAL Bio-Active', 'SKY-CAL Industrial/Feed', 'Eggshell Membrane Powder'], $project->products()->orderBy('id')->pluck('name')->all());
        $this->assertSame(5, $project->rawMaterials()->count());
        $this->assertSame(13, $project->manpowers()->count());
        $this->assertSame(12, $project->expenses()->count());
        $this->assertSame(6, $project->fixedAssets()->count());
        $this->assertSame(0, $project->fixedAssetOpeningBalances()->count());
    }

    public function test_the_capacity_is_the_same_in_every_year_and_the_first_year_is_a_part_year(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();

        $expected = ['SKY-CAL Bio-Active' => [600.0, 12.5], 'SKY-CAL Industrial/Feed' => [900.0, 17.5], 'Eggshell Membrane Powder' => [48.0, 3.75]];
        foreach ($project->products()->get() as $product) {
            [$capacity, $firstYearShare] = $expected[$product->name];

            $this->assertSame([$capacity], array_values(array_unique(array_map('floatval', array_values($product->max_capacity)))), $product->name.' capacity');
            $this->assertEqualsWithDelta($firstYearShare, (float) array_values($product->target_percentages)[0], 0.001, $product->name.' first-year share');
        }
    }

    public function test_the_renewals_are_a_yearly_percentage_of_each_asset(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $assets = $this->study()->fixedAssets()->orderBy('id')->get();

        $this->assertSame([0.5, 2.0, 3.0, 2.0, 10.0, 8.0], $assets->map(fn ($a) => (float) $a->replacement_cost_rate)->all());
        $this->assertSame([1, 1, 1, 1, 1, 1], $assets->map(fn ($a) => (int) $a->replacement_cost_interval)->all());
        $this->assertSame(['Forklifts and vehicles'], $assets->where('counts', 3)->pluck('name')->values()->all());
    }

    public function test_salaries_are_25_percent_above_the_first_estimate(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $salaries = $this->study()->manpowers()->get()->mapWithKeys(fn ($m) => [$m->position => (float) $m->avg_salary]);

        $this->assertSame(10600.0, $salaries['Process operators (washing, peeling, dewatering)']);
        $this->assertSame(62500.0, $salaries['Plant manager']);
        $this->assertSame(20000.0, $salaries['Accounting and administration staff']);
        $this->assertSame(SkyHealthySeeder::salary(8500), 10600);
    }

    public function test_the_helpers_follow_the_applications_rounding(): void
    {
        // Each year = the year before x (1 + growth), rounded to a whole number.
        $this->assertSame([37800.0, 40824.0, 44090.0], array_slice(SkyHealthySeeder::priceChain(37800, 8), 0, 3));
        $this->assertCount(7, SkyHealthySeeder::priceChain(100, 5));
        $this->assertSame([12.5, 50, 70], array_slice(SkyHealthySeeder::targets([25, 50, 70, 80, 85, 85, 85], 6), 0, 3));
    }

    public function test_the_words_of_the_study_are_written(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();

        $this->assertStringContainsString('eggshell', (string) $project->intro_paragraph);
        $this->assertNotEmpty($project->dashboard_comment_1);
        $this->assertNotEmpty($project->dashboard_comment_2);
        $this->assertNotEmpty($project->comment_for_raw_materials);
        foreach ($project->products()->get() as $product) {
            $this->assertNotEmpty($product->comment_for_sales, $product->name);
        }
    }

    public function test_the_statements_balance_and_the_dashboard_has_seven_years(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();
        $user = $this->owner($project);

        $this->actingAs($user)->get(route('projects.statements', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('statements.check.balanced', true));

        $page = $this->actingAs($user)->get(route('projects.dashboard', $project))->assertOk()->viewData('page');
        $props = json_decode(is_string($page) ? $page : json_encode($page), true)['props'];

        // The result may be positive or negative (that is what the study is for); it only has to exist.
        $this->assertNotNull($props['dashboard']['kpi']['npv']);
        $this->assertCount(7, $props['dashboard']['revenue']['value']);
    }

    public function test_every_screen_of_the_study_opens(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();
        $user = $this->owner($project);

        foreach (['projects.edit', 'projects.manpower.edit', 'projects.expenses.edit', 'projects.assets.edit', 'projects.raw-materials.edit', 'projects.statements', 'projects.dashboard', 'projects.sensitivity', 'projects.report', 'projects.links'] as $route) {
            $this->actingAs($user)->get(route($route, $project))->assertOk();
        }

        foreach ($project->products()->get() as $product) {
            $this->actingAs($user)->get(route('projects.products.edit', [$project, $product]))->assertOk();
        }
    }

    public function test_running_it_again_makes_no_copies(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $this->seed(SkyHealthySeeder::class);

        $this->assertSame(1, Project::where('name', SkyHealthySeeder::STUDY)->count());
        $this->assertSame(1, User::where('email', SkyHealthySeeder::EMAIL)->count());
        $this->assertSame(1, Company::where('name_en', SkyHealthySeeder::COMPANY)->count());
    }
}
