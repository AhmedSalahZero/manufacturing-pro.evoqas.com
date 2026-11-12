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

// The Sky Healthy Food eggshell study, version 2: 7 products, 7 years, new company, export from 2029.
// Run:  php artisan test --filter=SkyHealthySeederTest
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
        // Study starts January 2027 (0); operation starts January 2028 (12). Selling starts: food 2028-04 (15), supplement 2028-07 (18), oral care 2028-01 (12),
        // cosmetic 2028-07 (18), membrane powder 2028-04 (15), nutraceutical 2028-07 (18), collagen 2028-07 (18).
        $this->assertSame(12, $project->operation_start_date);
        $this->assertSame([15, 18, 12, 18, 15, 18, 18], $project->products()->orderBy('id')->pluck('selling_start_date')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([
            'SKY-CAL Food Ingredient Grade', 'SKY-CAL Supplement Active Grade', 'SKY-CAL Oral Care Abrasive Grade', 'SKY-CAL Cosmetic Grade',
            'SKY-CAL Eggshell Membrane Powder', 'SKY-CAL Nutraceutical Raw Material', 'SKY-CAL Collagen Hydrolysate (Eggshell Membrane)',
        ], $project->products()->orderBy('id')->pluck('name')->all());
        $this->assertSame(10, $project->rawMaterials()->count());
        $this->assertSame(20, $project->manpowers()->count());
        $this->assertSame(18, $project->expenses()->count());
        $this->assertSame(9, $project->fixedAssets()->count());
        $this->assertSame(0, $project->fixedAssetOpeningBalances()->count());
    }

    public function test_the_capacity_is_the_same_in_every_year_and_the_share_sold_follows_the_agreed_ramp(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();

        $capacity = [
            'SKY-CAL Food Ingredient Grade' => 400.0, 'SKY-CAL Supplement Active Grade' => 300.0, 'SKY-CAL Oral Care Abrasive Grade' => 400.0,
            'SKY-CAL Cosmetic Grade' => 100.0, 'SKY-CAL Eggshell Membrane Powder' => 35.0, 'SKY-CAL Nutraceutical Raw Material' => 300.0,
            'SKY-CAL Collagen Hydrolysate (Eggshell Membrane)' => 13.0,
        ];
        foreach ($project->products()->get() as $product) {
            // The application keeps the yearly lists of a product from its first selling year (2028), under the study-year keys: 2028 = 1, 2029 = 2 ...
            $shares = array_map('floatval', $product->target_percentages);

            $this->assertSame([$capacity[$product->name]], array_values(array_unique(array_map('floatval', array_values($product->max_capacity)))), $product->name.' capacity');
            $this->assertArrayNotHasKey(0, $shares, $product->name.' has no 2027 sales');
            // The same share for every product: 25% in 2028 (a part year), then 50, 60, 70, 80 and 85% in 2033.
            // Key 7 is the extra year the application adds for the terminal value; it repeats the last year.
            $this->assertEquals([1 => 25.0, 2 => 50.0, 3 => 60.0, 4 => 70.0, 5 => 80.0, 6 => 85.0, 7 => 85.0], $shares, $product->name.' share of capacity sold');
        }
    }

    public function test_prices_grow_15_percent_local_and_export_from_2029_and_are_one_price_for_all_markets(): void
    {
        $this->seed(SkyHealthySeeder::class);

        foreach ($this->study()->products()->get() as $product) {
            $local = array_map('floatval', $product->local_price_per_unit);
            // Study-year keys: 1 = 2028 (the base price), 2 = 2029 ... 6 = 2033, 7 = the extra year.
            $this->assertEqualsWithDelta(round($local[1] * 1.15), $local[2], 0.5, $product->name.' local 2029 = 2028 + 15%');
            $this->assertEqualsWithDelta(round($local[5] * 1.15), $local[6], 0.5, $product->name.' local 2033 = 2032 + 15%');
            $growth = array_map('floatval', (array) $product->local_growth_rates);
            $this->assertEquals([1 => 0.0, 2 => 15.0, 3 => 15.0, 4 => 15.0, 5 => 15.0, 6 => 15.0], array_intersect_key($growth, array_flip(range(1, 6))), $product->name.' local growth (2028 is the base)');

            $markets = $product->segments()->where('kind', 'export')->orderBy('id')->get();
            $this->assertCount(3, $markets);
            $inPounds = $markets->map(fn ($m) => (float) $m->first_foreign_price);   // the same NUMBER in USD and EUR (USD 450 = EUR 450)
            foreach ($inPounds as $pounds) {
                $this->assertEqualsWithDelta($inPounds[0], $pounds, 1.0, $product->name.' one export price (the same number) in every market');
            }
            foreach ($markets as $m) {
                $rates = array_map('floatval', (array) $m->foreign_growth_rates);
                $this->assertEquals([1 => 0.0, 2 => 15.0, 3 => 15.0, 4 => 15.0, 5 => 15.0, 6 => 15.0], array_intersect_key($rates, array_flip(range(1, 6))), $product->name.' export growth 15% from 2029');
            }
        }
    }

    public function test_export_starts_in_2029_so_2028_is_all_local(): void
    {
        $this->seed(SkyHealthySeeder::class);

        foreach ($this->study()->products()->get() as $product) {
            // Study-year keys: 1 = 2028, 2 = 2029.
            $localShare = array_map('floatval', $product->local_target_quantity_percentages);

            $this->assertEqualsWithDelta(100.0, $localShare[1], 0.001, $product->name.' 2028 is all local');
            $this->assertLessThan(100.0, $localShare[2], $product->name.' 2029 has export');
        }
    }

    public function test_the_fixed_assets_have_kinds_milestones_and_yearly_renewals(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $assets = $this->study()->fixedAssets()->orderBy('id')->get();

        $this->assertSame(['construction', 'machine', 'machine', 'machine', 'machine', 'machine', 'other', 'other', 'other'], $assets->pluck('asset_type')->all());
        $this->assertSame([0.5, 2.0, 3.0, 2.0, 2.0, 2.0, 8.0, 10.0, 10.0], $assets->map(fn ($a) => (float) $a->replacement_cost_rate)->all());
        $this->assertSame(array_fill(0, 9, 1), $assets->map(fn ($a) => (int) $a->replacement_cost_interval)->all());
        $this->assertEqualsWithDelta(80113981.0, (float) $assets->sum(fn ($a) => (float) $a->amount * (int) $a->counts), 1.0, 'total capex EGP 80.1 million');
        $this->assertSame('s_curve', $assets->first()->execution_curve);

        // Every machine has a delivery month between its purchase and its ready month, and is ready before the operation starts (January 2028 = 12).
        foreach ($assets->where('asset_type', 'machine') as $machine) {
            $this->assertNotNull($machine->delivery_date, $machine->name);
            $this->assertLessThanOrEqual((int) $machine->end_date, (int) $machine->delivery_date, $machine->name);
            $this->assertLessThanOrEqual(11, (int) $machine->end_date, $machine->name.' is ready in 2027');
        }
    }

    public function test_salaries_are_the_net_figures_of_the_workbook(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();
        $salaries = $project->manpowers()->get()->mapWithKeys(fn ($m) => [$m->position => (float) $m->avg_salary]);

        $this->assertSame(39000.0, $salaries['Plant / Operations Manager']);
        $this->assertSame(14300.0, $salaries['Accountant']);
        $this->assertSame(29250.0, $salaries['Export & key-account sales manager']);
        $this->assertEqualsWithDelta(20.0, (float) $project->salaries_tax_rate, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $project->social_insurance_rate, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $project->salaries_annual_increase, 0.001);
    }

    public function test_the_helpers_follow_the_applications_rounding(): void
    {
        // Each year = the year before x (1 + growth), rounded to a whole number; with the growth starting in 2029 (index 2), 2028 keeps the base price.
        $this->assertSame([25200.0, 28980.0, 33327.0, 38326.0], array_slice(SkyHealthySeeder::priceChain(450 * 56, 15, 2), 1, 4));
        $this->assertSame([24615.0, 26584.0, 28711.0], array_slice(SkyHealthySeeder::priceChain(450 * 54.7, 8), 0, 3));
        $this->assertCount(7, SkyHealthySeeder::priceChain(100, 5));
        $this->assertEquals([0, 0, 15, 15, 15, 15, 15], SkyHealthySeeder::growthRates(15));
        // The share of the capacity sold is the same for every product: 25, 50, 60, 70, 80, 85% for 2028-2033.
        $this->assertEquals([0, 25, 50, 60, 70, 80, 85], SkyHealthySeeder::targets());
    }

    public function test_the_exchange_rates_are_fixed_at_56_and_60(): void
    {
        $rates = SkyHealthySeeder::rates();
        $this->assertSame(array_fill(0, 7, 56.0), $rates['usd']);
        $this->assertSame(array_fill(0, 7, 60.0), $rates['euro']);
        // The euro price is the same number as the dollar price (USD 450 = EUR 450), as in the Excel workbook.
        $this->assertSame(450.0, SkyHealthySeeder::exportPrice(450, 'euro'));
        $this->assertSame(450.0, SkyHealthySeeder::exportPrice(450, 'usd'));
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
        $this->assertCount(2, $props['dashboard']['sales_breakdown']['local'], 'two sales channels');
        $this->assertCount(3, $props['dashboard']['sales_breakdown']['export'], 'three export markets');
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

    public function test_version_2_of_the_study_is_removed_so_only_one_sky_case_is_left(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $this->study()->forceFill(['name' => SkyHealthySeeder::VERSION_2_STUDY])->save();   // pretend this is the version 2 study already in the database

        $this->seed(SkyHealthySeeder::class);

        $this->assertSame(1, Project::where('name', SkyHealthySeeder::STUDY)->count());
        $this->assertSame(0, Project::withTrashed()->where('name', SkyHealthySeeder::VERSION_2_STUDY)->count(), 'version 2 is gone');
    }

    public function test_running_it_again_makes_no_copies_and_rebuilds_the_study_with_the_current_figures(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $product = $this->study()->products()->first();
        $product->forceFill(['target_percentages' => [1 => 40]])->save();   // pretend an older run left other figures behind

        $this->seed(SkyHealthySeeder::class);

        $this->assertEquals(25.0, (float) $this->study()->products()->first()->target_percentages[1], 'the study is rebuilt with 25% in 2028');

        $this->assertSame(1, Project::where('name', SkyHealthySeeder::STUDY)->count());
        $this->assertSame(1, User::where('email', SkyHealthySeeder::EMAIL)->count());
        $this->assertSame(1, Company::where('name_en', SkyHealthySeeder::COMPANY)->count());
    }

    public function test_the_first_three_product_study_is_removed_and_replaced(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $first = $this->study();
        $first->forceFill(['name' => SkyHealthySeeder::LEGACY_STUDY])->save();   // pretend this is the old study of the first version

        $this->seed(SkyHealthySeeder::class);

        $this->assertSame(1, Project::where('name', SkyHealthySeeder::STUDY)->count(), 'the new study is made');
        $this->assertSame(0, Project::withTrashed()->where('name', 'like', '%'.SkyHealthySeeder::LEGACY_STUDY.'%')->count(), 'the old study is gone');
        $this->assertSame(1, Project::where('company_id', $first->company_id)->count(), 'only one Sky study is left');
    }

    public function test_the_study_sells_through_channels_and_exports_in_dollars_and_euros(): void
    {
        $this->seed(SkyHealthySeeder::class);
        $project = $this->study();

        $this->assertTrue((bool) $project->has_export_sales);
        $this->assertSame(['Food and supplement manufacturers', 'Distributors'], $project->salesChannels()->orderBy('position')->pluck('name')->all());
        $this->assertSame(['Saudi Arabia', 'United Arab Emirates', 'Germany'], $project->exportMarkets()->orderBy('position')->pluck('name')->all());
        $this->assertSame(['usd', 'usd', 'euro'], $project->exportMarkets()->orderBy('position')->pluck('currency')->all());
        $this->assertEqualsCanonicalizing(['euro', 'usd'], $project->fxRates()->pluck('currency')->all());

        $usd = $project->fxRates()->where('currency', 'usd')->first()->rates;
        $this->assertCount(7, $usd);
        $this->assertSame(array_fill(0, 7, 56.0), array_map('floatval', (array) $usd), 'USD fixed at 56');
        $euro = $project->fxRates()->where('currency', 'euro')->first()->rates;
        $this->assertSame(array_fill(0, 7, 60.0), array_map('floatval', (array) $euro), 'EUR fixed at 60');

        foreach ($project->products()->get() as $product) {
            $this->assertSame(5, $product->segments()->count(), $product->name.': two channels and three markets');
            $this->assertGreaterThan(0, array_sum((array) $product->segments()->where('kind', 'export')->first()->monthly_values), $product->name);
        }
    }
}
