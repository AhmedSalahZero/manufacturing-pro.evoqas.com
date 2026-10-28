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

        $this->get(route('shared.show', $link->link_code))->assertOk();
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
}
