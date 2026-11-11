<?php

namespace Tests\Feature;

use App\Calculations\OpeningBalanceCalculator;
use App\Models\Company;
use App\Models\Project;
use App\Models\SharingLink;
use App\Models\User;
use App\Services\OpeningBalanceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The demo company, the demo user and the two complete 5-year demo studies. Run:  php artisan test --filter=DemoSeederTest
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::where('email', DemoSeeder::EMAIL)->firstOrFail();
    }

    private function study(string $name): Project
    {
        return Project::where('name', $name)->firstOrFail();
    }

    public function test_the_demo_user_can_sign_in(): void
    {
        $this->seed(DemoSeeder::class);

        $user = $this->user();
        $this->assertTrue(Hash::check(DemoSeeder::PASSWORD, $user->password));
        $this->assertTrue($user->isCompanyAdmin());
        $this->assertSame('Nile Foods Demo', Company::findOrFail($user->company_id)->name_en);

        $this->post('/login', ['email' => DemoSeeder::EMAIL, 'password' => DemoSeeder::PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_it_makes_two_five_year_studies_one_new_and_one_existing(): void
    {
        $this->seed(DemoSeeder::class);

        $new = $this->study(DemoSeeder::STUDY_NEW);
        $existing = $this->study(DemoSeeder::STUDY_EXISTING);

        $this->assertSame(5, (int) $new->duration);
        $this->assertSame(5, (int) $existing->duration);
        $this->assertTrue((bool) $new->new_company);
        $this->assertFalse((bool) $existing->new_company);
        $this->assertSame('2027-01', $new->start_date->format('Y-m'));
        $this->assertSame('2026-01', $existing->start_date->format('Y-m'));
        $this->assertSame('egp', $new->main_functional_currency);
        $this->assertSame(4, $new->products()->count());
        $this->assertSame(3, $existing->products()->count());
        $this->assertGreaterThan(0, $new->manpowers()->count());
        $this->assertGreaterThan(0, $new->expenses()->count());
        $this->assertGreaterThan(0, $existing->fixedAssets()->count());
        $this->assertSame(0, $new->fixedAssetOpeningBalances()->count());
        $this->assertGreaterThan(0, $existing->fixedAssetOpeningBalances()->count());
    }

    public function test_the_opening_balance_sheet_of_the_existing_company_balances(): void
    {
        $this->seed(DemoSeeder::class);

        $existing = $this->study(DemoSeeder::STUDY_EXISTING);
        $check = OpeningBalanceCalculator::balance(app(OpeningBalanceService::class)->totals($existing));

        $this->assertTrue($check['balanced']);
        $this->assertGreaterThan(0, $check['assets']);
    }

    public function test_the_statements_balance_and_the_dashboards_have_an_irr_and_npv(): void
    {
        $this->seed(DemoSeeder::class);

        foreach ([DemoSeeder::STUDY_NEW, DemoSeeder::STUDY_EXISTING] as $name) {
            $project = $this->study($name);

            $this->actingAs($this->user())->get(route('projects.statements', $project))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('statements.check.balanced', true));

            $page = $this->actingAs($this->user())->get(route('projects.dashboard', $project))->assertOk()->viewData('page');
            $props = json_decode(is_string($page) ? $page : json_encode($page), true)['props'];

            $this->assertNotNull($props['dashboard']['kpi']['irr'], "$name has no IRR");
            $this->assertGreaterThan(0, $props['dashboard']['kpi']['irr'], "$name IRR");
            $this->assertGreaterThan(0, $props['dashboard']['kpi']['npv'], "$name NPV");
            $this->assertCount(5, $props['dashboard']['revenue']['value']);
            // the sales are also shown by channel and by market
            $this->assertNotEmpty($props['dashboard']['sales_breakdown']['local'], "$name has no local channels on the dashboard");
            $this->assertNotEmpty($props['dashboard']['sales_breakdown']['export'], "$name has no export markets on the dashboard");
            $this->assertCount(5, $props['dashboard']['sales_breakdown']['local'][0]['values']);
            $this->assertGreaterThan(0, array_sum($props['dashboard']['sales_breakdown']['local'][0]['values']));
        }
    }

    public function test_every_screen_of_both_studies_opens(): void
    {
        $this->seed(DemoSeeder::class);

        foreach ([DemoSeeder::STUDY_NEW, DemoSeeder::STUDY_EXISTING] as $name) {
            $project = $this->study($name);

            foreach (['projects.edit', 'projects.manpower.edit', 'projects.expenses.edit', 'projects.assets.edit', 'projects.raw-materials.edit', 'projects.statements', 'projects.dashboard', 'projects.sensitivity', 'projects.report', 'projects.links'] as $route) {
                $this->actingAs($this->user())->get(route($route, $project))->assertOk();
            }

            foreach ($project->products()->get() as $product) {
                $this->actingAs($this->user())->get(route('projects.products.edit', [$project, $product]))->assertOk();
            }
        }

        $this->actingAs($this->user())->get(route('projects.opening.edit', $this->study(DemoSeeder::STUDY_EXISTING)))->assertOk();
        $this->actingAs($this->user())->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.studies', 2));
    }

    public function test_the_shared_link_of_a_demo_study_opens_without_signing_in(): void
    {
        $this->seed(DemoSeeder::class);

        $link = SharingLink::with('project')->where('project_id', $this->study(DemoSeeder::STUDY_NEW)->id)->firstOrFail();
        $this->app['auth']->forgetGuards();

        // The address of the link opens the first page of the study; the dashboard of the study opens through the same link too.
        $first = route('shared.projects.edit', ['code' => $link->link_code, 'project' => $link->project]);
        $this->get(route('shared.show', $link->link_code))->assertRedirect($first);
        $this->get($first)->assertOk();
        $this->get(route('shared.projects.dashboard', ['code' => $link->link_code, 'project' => $link->project]))->assertOk();
    }

    public function test_the_report_shows_every_building_block_of_the_study(): void
    {
        $this->seed(DemoSeeder::class);

        $sections = function (string $name): array {
            $page = $this->actingAs($this->user())->get(route('projects.report', $this->study($name)))->assertOk()->viewData('page');

            return json_decode(is_string($page) ? $page : json_encode($page), true)['props']['inputs'];
        };

        $new = $sections(DemoSeeder::STUDY_NEW);
        $this->assertSame(['products', 'rawMaterials', 'manpower', 'expenses', 'assets'], array_column($new, 'id'), 'a new company has no opening balances');

        $existing = $sections(DemoSeeder::STUDY_EXISTING);
        $this->assertSame(['products', 'rawMaterials', 'manpower', 'expenses', 'assets', 'opening'], array_column($existing, 'id'));

        foreach (array_merge($new, $existing) as $section) {
            $this->assertNotEmpty($section['blocks'], $section['id']);
            foreach ($section['blocks'] as $block) {
                $this->assertContains($block['type'], ['table', 'text']);
                if ($block['type'] === 'table') {
                    $this->assertNotEmpty($block['rows'], $section['id']);
                    foreach ($block['rows'] as $row) {
                        $this->assertCount(count($block['headers']), $row, $section['id'].' row width matches its headers');
                    }
                }
            }
        }

        // The channels, markets, exchange rates, own collection terms and the results by channel and market are all in the report.
        $titles = fn (array $sections) => collect($sections[0]['blocks'])->map(fn ($b) => $b['title']['k'] ?? null)->filter()->all();
        foreach ([$new, $existing] as $study) {
            foreach (['products.segments.reportFx', 'products.segments.reportChannels', 'products.segments.reportMarkets', 'products.segments.reportResults'] as $key) {
                $this->assertContains($key, $titles($study), $key);
            }
        }

        // Four products' worth of detail for the new company: its first product block names the product.
        $this->assertSame(4, collect($new[0]['blocks'])->filter(fn ($b) => isset($b['title']['text']))->count());
    }

    public function test_running_it_again_makes_no_copies(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', DemoSeeder::EMAIL)->count());
        $this->assertSame(1, Project::where('name', DemoSeeder::STUDY_NEW)->count());
        $this->assertSame(1, Project::where('name', DemoSeeder::STUDY_EXISTING)->count());
    }

    public function test_both_studies_use_sales_channels_export_markets_and_exchange_rates(): void
    {
        $this->seed(DemoSeeder::class);

        foreach ([DemoSeeder::STUDY_NEW => [3, 3], DemoSeeder::STUDY_EXISTING => [2, 2]] as $name => [$channels, $markets]) {
            $project = $this->study($name);

            $this->assertTrue((bool) $project->has_export_sales, $name);
            $this->assertSame($channels, $project->salesChannels()->count(), "$name channels");
            $this->assertSame($markets, $project->exportMarkets()->count(), "$name markets");
            $this->assertGreaterThan(0, $project->fxRates()->count(), "$name rates");

            foreach ($project->products()->with('exportSeasonality')->get() as $product) {
                $local = $product->segments()->where('kind', 'local')->get();
                $export = $product->segments()->where('kind', 'export')->get();

                $this->assertCount($channels, $local, "$name {$product->name} channels");
                $this->assertCount($markets, $export, "$name {$product->name} markets");
                $this->assertEqualsWithDelta(100.0, $local->sum(fn ($s) => (float) $s->allocation_percentages[0]), 0.001);
                $this->assertEqualsWithDelta(100.0, $export->sum(fn ($s) => (float) $s->allocation_percentages[0]), 0.001);
                $this->assertGreaterThan(0, array_sum((array) $local->first()->monthly_values), "$name {$product->name} was calculated by channel");
                $this->assertTrue($local->contains(fn ($s) => $s->uses_own_terms), "$name {$product->name} has a channel with its own terms");
                $this->assertNotNull($product->exportSeasonality);
            }
        }
    }

}
