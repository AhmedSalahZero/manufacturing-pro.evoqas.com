<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsStudy;
use Tests\TestCase;

// Public read-only links: who may make them, what a visitor sees (every page of the study, looking only), switching off, the visit log.
// Run:  php artisan test --filter=SharingLinksTest
class SharingLinksTest extends TestCase
{
    use BuildsStudy;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    private function link($project, string $name = 'Bank'): SharingLink
    {
        $this->actingAs($this->owner)->post(route('projects.links.store', $project), ['name' => $name])->assertSessionHasNoErrors();

        return SharingLink::where('project_id', $project->id)->where('name', $name)->firstOrFail();
    }

    // ── Managing the links ──────────────────────────────────────────────────

    public function test_the_owner_creates_a_link_with_a_long_random_code(): void
    {
        $project = $this->study();
        $link = $this->link($project);

        $this->assertSame(40, strlen($link->link_code));
        $this->assertFalse((bool) $link->closed);
        $this->assertSame($this->owner->id, $link->user_id);
        $this->assertNotSame($link->link_code, $this->link($project, 'Investor')->link_code);
    }

    public function test_the_list_shows_the_link_address_and_the_number_of_visits(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        SharingLinkVisitor::create(['link_code' => $link->link_code, 'ip' => '10.0.0.1']);
        SharingLinkVisitor::create(['link_code' => $link->link_code, 'ip' => '10.0.0.2']);

        $props = $this->props($this->actingAs($this->owner)->get(route('projects.links', $project))->assertOk());

        $this->assertSame(route('shared.show', $link->link_code), $props['links'][0]['url']);
        $this->assertSame(2, $props['links'][0]['visits']);
        $this->assertFalse($props['links'][0]['closed']);
    }

    public function test_a_name_is_needed(): void
    {
        $project = $this->study();

        $this->actingAs($this->owner)->post(route('projects.links.store', $project), ['name' => ''])->assertSessionHasErrors('name');
        $this->assertSame(0, SharingLink::count());
    }

    public function test_only_the_owner_or_company_administrator_manages_links(): void
    {
        $company = Company::factory()->create();
        $this->owner = User::factory()->forCompany($company)->create();
        $editor = User::factory()->forCompany($company)->create();
        $project = $this->study();
        $this->actingAs($this->owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $editor->id, 'can_edit' => true]]])->assertSessionHasNoErrors();
        $link = $this->link($project);
        $stranger = User::factory()->companyAdmin()->create();

        foreach ([$editor, $stranger] as $person) {
            $this->actingAs($person)->get(route('projects.links', $project))->assertForbidden();
            $this->actingAs($person)->post(route('projects.links.store', $project), ['name' => 'Mine'])->assertForbidden();
            $this->actingAs($person)->put(route('projects.links.toggle', [$project, $link]))->assertForbidden();
            $this->actingAs($person)->delete(route('projects.links.destroy', [$project, $link]))->assertForbidden();
        }
        $this->assertSame(1, SharingLink::count());
    }

    public function test_a_link_of_another_study_cannot_be_reached_through_this_one(): void
    {
        $project = $this->study();
        $mine = $this->owner;
        $this->owner = User::factory()->create();
        $other = $this->study();
        $foreign = $this->link($other);
        $this->owner = $mine;

        $this->actingAs($this->owner)->put(route('projects.links.toggle', [$project, $foreign]))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('projects.links.destroy', [$project, $foreign]))->assertNotFound();
        $this->assertFalse((bool) $foreign->refresh()->closed);
    }

    public function test_a_link_can_be_switched_off_and_on_again(): void
    {
        $project = $this->study();
        $link = $this->link($project);

        $this->actingAs($this->owner)->put(route('projects.links.toggle', [$project, $link]))->assertSessionHas('success');
        $this->assertTrue((bool) $link->refresh()->closed);

        // (an identical send within a few seconds is ignored on purpose, so the second one carries a different note)
        $this->actingAs($this->owner)->put(route('projects.links.toggle', [$project, $link]), ['again' => 1]);
        $this->assertFalse((bool) $link->refresh()->closed);
    }

    public function test_deleting_a_link_removes_its_visits_too(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        SharingLinkVisitor::create(['link_code' => $link->link_code, 'ip' => '10.0.0.1']);

        $this->actingAs($this->owner)->delete(route('projects.links.destroy', [$project, $link]))->assertSessionHasNoErrors();

        $this->assertSame(0, SharingLink::count());
        $this->assertSame(0, SharingLinkVisitor::count());
    }

    // ── What a visitor sees ──────────────────────────────────────────────

    /** The address of one page of the study under a link. */
    private function shared(SharingLink $link, string $name, $project, array $extra = []): string
    {
        return route('shared.'.$name, ['code' => $link->link_code, 'project' => $project] + $extra);
    }

    public function test_the_address_of_the_link_opens_the_first_page_of_the_study(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->asGuest();

        $this->get(route('shared.show', $link->link_code))->assertRedirect($this->shared($link, 'projects.edit', $project));
    }

    public function test_a_visitor_without_an_account_opens_every_page_of_the_study_looking_only(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $product = $project->products()->orderBy('id')->first();
        $this->asGuest();

        $pages = [
            [$this->shared($link, 'projects.edit', $project), 'Projects/Edit', 'readOnly'],
            [$this->shared($link, 'projects.products.edit', $project, ['product' => $product]), 'Products/Edit', 'readOnly'],
            [$this->shared($link, 'projects.raw-materials.edit', $project), 'RawMaterials/Payments', 'readOnly'],
            [$this->shared($link, 'projects.manpower.edit', $project), 'Manpower/Edit', 'readOnly'],
            [$this->shared($link, 'projects.expenses.edit', $project), 'Expenses/Edit', 'readOnly'],
            [$this->shared($link, 'projects.assets.edit', $project), 'FixedAssets/Edit', 'readOnly'],
            [$this->shared($link, 'projects.statements', $project), 'Statements/Show', null],
            [$this->shared($link, 'projects.dashboard', $project), 'Dashboard/Show', 'canEdit'],
            [$this->shared($link, 'projects.sensitivity', $project), 'Sensitivity/Show', 'canEdit'],
        ];

        foreach ($pages as [$url, $component, $flag]) {
            $this->get($url)->assertOk()->assertInertia(function (Assert $page) use ($component, $flag, $link, $project) {
                $page->component($component)
                    ->where('auth.user', null)
                    ->where('public.code', $link->link_code)
                    ->where('study.id', $project->id);
                if ($flag === 'readOnly') {
                    $page->where('readOnly', true);
                }
                if ($flag === 'canEdit') {
                    $page->where('canEdit', false);
                }
            });
        }
    }

    public function test_even_the_signed_in_owner_only_looks_when_he_opens_the_link(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $product = $project->products()->orderBy('id')->first();
        // no asGuest(): the owner is still signed in, as when he clicks the link inside the app

        foreach ([
            $this->shared($link, 'projects.edit', $project),
            $this->shared($link, 'projects.products.edit', $project, ['product' => $product]),
            $this->shared($link, 'projects.manpower.edit', $project),
            $this->shared($link, 'projects.expenses.edit', $project),
            $this->shared($link, 'projects.assets.edit', $project),
        ] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('readOnly', true)->where('public.code', $link->link_code));
        }
        foreach (['projects.dashboard', 'projects.sensitivity'] as $name) {
            $this->actingAs($this->owner)->get($this->shared($link, $name, $project))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        }

        // the same pages inside the app are still editable for him
        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('readOnly', false)->where('public', null));
    }

    public function test_a_person_of_another_company_who_is_signed_in_can_look_through_a_link_too(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $stranger = User::factory()->companyAdmin()->create();

        $this->actingAs($stranger)->get($this->shared($link, 'projects.edit', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('readOnly', true));
        $this->actingAs($stranger)->get(route('projects.edit', $project))->assertForbidden();
    }

    public function test_the_opening_balances_of_an_existing_company_are_there_too(): void
    {
        $project = $this->study(false);
        $link = $this->link($project);
        $this->asGuest();

        $this->get($this->shared($link, 'projects.opening.edit', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OpeningBalances/Edit')->where('readOnly', true));
    }

    public function test_the_visitor_sees_the_whole_study_with_its_rates_but_not_who_owns_it(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->actingAs($this->owner)->put(route('projects.dashboard.comments', $project), ['one' => 'Steady sales.', 'two' => ''])->assertSessionHasNoErrors();
        $this->asGuest();

        $form = $this->props($this->get($this->shared($link, 'projects.edit', $project))->assertOk());
        $this->assertSame('Plant A', $form['project']['name']);
        $this->assertEqualsWithDelta(22.5, (float) $form['project']['tax_rate'], 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $form['project']['return_rate'], 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $form['project']['perpetual_growth_rate'], 0.001);
        $this->assertNull($form['project']['owner']);
        $this->assertStringNotContainsString($this->owner->email, json_encode($form));

        $dash = $this->props($this->get($this->shared($link, 'projects.dashboard', $project))->assertOk());
        $this->assertTrue($dash['ready']);
        $this->assertSame('Steady sales.', $dash['comments']['one']);
        $this->assertCount(3, $dash['dashboard']['years']);

        $statements = $this->props($this->get($this->shared($link, 'projects.statements', $project))->assertOk());
        $this->assertCount(3, $statements['statements']['years']);
    }

    public function test_nothing_can_be_saved_and_the_private_pages_stay_closed(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->asGuest();
        $base = '/shared/'.$link->link_code.'/projects/'.$project->id;

        // saving: there is no such address under a link, and the real ones need a sign-in
        $this->put($base.'/expenses', [])->assertStatus(405);
        $this->put(route('projects.expenses.update', $project), [])->assertRedirect('/login');
        $this->put(route('projects.update', $project), [])->assertRedirect('/login');
        $this->put(route('projects.dashboard.comments', $project), ['one' => 'x'])->assertRedirect('/login');
        // the report, the links of the study and the signed-in pages are not reachable from a link
        $this->get($base.'/report')->assertNotFound();
        $this->get($base.'/links')->assertNotFound();
        $this->get(route('projects.edit', $project))->assertRedirect('/login');
        $this->get(route('projects.report', $project))->assertRedirect('/login');
        $this->get(route('projects.links', $project))->assertRedirect('/login');
    }

    public function test_a_link_opens_its_own_study_only(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $other = $this->study();
        $this->asGuest();

        $this->get($this->shared($link, 'projects.edit', $other))->assertNotFound();
        $this->get($this->shared($link, 'projects.dashboard', $other))->assertNotFound();
        $this->get($this->shared($link, 'projects.edit', $project))->assertOk();
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $project = $this->study();
        $this->asGuest();

        $this->get(route('shared.show', str_repeat('x', 40)))->assertNotFound();
        $this->get(route('shared.projects.edit', ['code' => str_repeat('x', 40), 'project' => $project]))->assertNotFound();
    }

    public function test_a_switched_off_link_shows_the_closed_page_on_every_page(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $link->update(['closed' => true]);
        $this->asGuest();

        foreach ([route('shared.show', $link->link_code), $this->shared($link, 'projects.edit', $project), $this->shared($link, 'projects.dashboard', $project)] as $url) {
            $this->get($url)->assertStatus(410)->assertInertia(fn (Assert $page) => $page->component('Shared/Closed'));
        }
        $this->assertSame(0, SharingLinkVisitor::count(), 'a closed link logs nothing');
    }

    public function test_a_link_switched_on_again_works_again(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $link->update(['closed' => true]);
        $link->update(['closed' => false]);
        $this->asGuest();

        $this->get($this->shared($link, 'projects.statements', $project))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Statements/Show'));
    }

    public function test_a_deleted_link_stops_working(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->actingAs($this->owner)->delete(route('projects.links.destroy', [$project, $link]))->assertRedirect();
        $this->asGuest();

        $this->get(route('shared.show', $link->link_code))->assertNotFound();
        $this->get($this->shared($link, 'projects.edit', $project))->assertNotFound();
    }

    public function test_a_deleted_study_takes_its_links_with_it(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $project->delete();
        $this->asGuest();

        $this->get(route('shared.show', $link->link_code))->assertNotFound();
    }

    public function test_a_study_with_no_results_yet_says_so(): void
    {
        $project = app(\App\Services\ProjectService::class)->create($this->owner, 'Empty');
        $link = $this->link($project, 'Early');
        $this->asGuest();

        $this->get($this->shared($link, 'projects.dashboard', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Show')->where('ready', false)->missing('dashboard'));
    }

    public function test_each_address_is_logged_once_an_hour(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->asGuest();

        $this->get($this->shared($link, 'projects.edit', $project))->assertOk();
        $this->get($this->shared($link, 'projects.dashboard', $project))->assertOk();
        $this->get(route('shared.show', $link->link_code))->assertRedirect();
        $this->assertSame(1, SharingLinkVisitor::where('link_code', $link->link_code)->count());

        SharingLinkVisitor::where('link_code', $link->link_code)->update(['created_at' => now()->subHours(2)]);
        $this->get($this->shared($link, 'projects.statements', $project))->assertOk();
        $this->assertSame(2, SharingLinkVisitor::where('link_code', $link->link_code)->count());
    }

    // ── Fill links (the Share template button): the customer fills in the input pages ──────────────

    private function fillLink($project, ?string $expires = null): SharingLink
    {
        $this->actingAs($this->owner)->post(route('projects.links.store', $project), ['type' => 'fill', 'name' => '', 'expires_at' => $expires])->assertSessionHasNoErrors();

        return SharingLink::where('project_id', $project->id)->where('type', 'fill')->latest('id')->firstOrFail();
    }

    /** The address of one page of the study under a fill link. */
    private function filled(SharingLink $link, string $name, $project, array $extra = []): string
    {
        return route('fill.'.$name, ['code' => $link->link_code, 'project' => $project] + $extra);
    }

    private function assetsPayload(string $after = 'stay'): array
    {
        return ['after' => $after, 'rows' => [[
            'name' => 'Lathe', 'counts' => 1, 'amount' => 5000, 'depreciation_duration' => 5, 'start' => '2026-01', 'end' => '2026-02',
            'admin_depreciation_percentage' => 30, 'replacement_cost_rate' => 0, 'replacement_cost_interval' => 1,
            'payment_terms' => 'cash', 'equity_funding_rate' => 100, 'interest_rate' => 0, 'grace_period' => 0, 'tenor' => 60, 'installment_interval' => 'monthly',
            'is_as_revenue_percentages' => true,
        ]]];
    }

    public function test_a_fill_link_needs_no_name_and_is_a_link_of_its_own_kind(): void
    {
        $project = $this->study();
        $link = $this->fillLink($project);

        $this->assertSame('fill', $link->type);
        $this->assertNotSame('', (string) $link->name);
        $this->assertNull($link->expires_at);
        $this->assertSame(40, strlen($link->link_code));
        $this->assertStringContainsString('/fill/'.$link->link_code, route('fill.show', $link->link_code));
        $this->assertSame('view', $this->link($project)->type, 'the old kind of link is still a view link');
    }

    public function test_the_fill_address_opens_the_first_page_and_the_two_kinds_are_not_mixed_up(): void
    {
        $project = $this->study();
        $fill = $this->fillLink($project);
        $view = $this->link($project);
        $this->asGuest();

        $this->get(route('fill.show', $fill->link_code))->assertRedirect($this->filled($fill, 'projects.edit', $project));
        $this->get(route('fill.show', $view->link_code))->assertNotFound();
        $this->get(route('shared.show', $fill->link_code))->assertNotFound();
        $this->get($this->filled($view, 'projects.edit', $project))->assertNotFound();
        $this->get($this->shared($fill, 'projects.edit', $project))->assertNotFound();
    }

    public function test_a_customer_opens_every_input_page_and_can_fill_it_in_but_sees_no_results(): void
    {
        $project = $this->study(false);
        $link = $this->fillLink($project);
        $product = $project->products()->orderBy('id')->first();
        $this->asGuest();

        $pages = [
            [$this->filled($link, 'projects.edit', $project), 'Projects/Edit'],
            [$this->filled($link, 'projects.products.edit', $project, ['product' => $product]), 'Products/Edit'],
            [$this->filled($link, 'projects.raw-materials.edit', $project), 'RawMaterials/Payments'],
            [$this->filled($link, 'projects.manpower.edit', $project), 'Manpower/Edit'],
            [$this->filled($link, 'projects.expenses.edit', $project), 'Expenses/Edit'],
            [$this->filled($link, 'projects.assets.edit', $project), 'FixedAssets/Edit'],
            [$this->filled($link, 'projects.opening.edit', $project), 'OpeningBalances/Edit'],
        ];
        foreach ($pages as [$url, $component]) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)
                ->where('readOnly', false)->where('auth.user', null)->where('public.kind', 'fill')->where('public.code', $link->link_code));
        }

        // the results, the report and the links are not his
        $base = '/fill/'.$link->link_code.'/projects/'.$project->id;
        foreach (['statements', 'dashboard', 'sensitivity', 'report', 'links'] as $page) {
            $this->get($base.'/'.$page)->assertNotFound();
        }
        $this->get('/shared/'.$link->link_code.'/projects/'.$project->id.'/dashboard')->assertNotFound();
        $this->get(route('projects.dashboard', $project))->assertRedirect('/login');
    }

    public function test_a_customer_saves_a_page_straight_into_the_study_and_goes_on(): void
    {
        $project = $this->study(false);
        $link = $this->fillLink($project);
        $this->asGuest();

        // an existing company: after the fixed assets comes the opening balances
        $this->put($this->filled($link, 'projects.assets.update', $project), $this->assetsPayload('next'))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->filled($link, 'projects.opening.edit', $project));

        $this->assertSame(['Lathe'], FixedAsset::where('project_id', $project->id)->pluck('name')->all(), 'the study itself was changed');
    }

    public function test_the_last_page_of_a_new_company_is_the_fixed_assets_then_the_thank_you_page(): void
    {
        $project = $this->study(true);
        $link = $this->fillLink($project);
        $this->asGuest();

        $this->put($this->filled($link, 'projects.assets.update', $project), $this->assetsPayload('next'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('fill.thanks', $link->link_code));

        $this->get(route('fill.thanks', $link->link_code))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shared/Thanks')->where('project.id', $project->id));
    }

    public function test_save_and_close_and_staying_on_the_page_keep_the_customer_inside_his_pages(): void
    {
        $project = $this->study(true);
        $link = $this->fillLink($project);
        $this->asGuest();

        $this->put($this->filled($link, 'projects.assets.update', $project), $this->assetsPayload('list'))->assertRedirect(route('fill.thanks', $link->link_code));
        $this->put($this->filled($link, 'projects.assets.update', $project), $this->assetsPayload('stay'))->assertRedirect($this->filled($link, 'projects.assets.edit', $project));
    }

    public function test_the_customer_cannot_change_the_three_rates_of_the_owner(): void
    {
        $project = $this->study(true);
        $link = $this->fillLink($project);
        $products = $project->products()->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'measurement_unit' => 'piece', 'selling_start_date' => '2026-01', 'vat_rate' => 14, 'withhold_tax_rate' => 1,
        ])->all();
        $materials = $project->rawMaterials()->orderBy('id')->get()->map(fn ($m) => [
            'id' => $m->id, 'name' => $m->name, 'rm_inventory_coverage_days' => 0, 'measurement_unit' => 'kg', 'vat_rate' => 14, 'withhold_tax_rate' => 0,
        ])->all();
        $this->asGuest();

        $this->put($this->filled($link, 'projects.update', $project), [
            'name' => 'Plant A (customer)', 'new_company' => true, 'main_functional_currency' => 'egp', 'intro_paragraph' => 'Filled in by the customer.', 'business_sector_id' => null,
            'start_date' => '2026-01', 'duration' => 3, 'operation_start_date' => '2026-01',
            'tax_rate' => 99, 'return_rate' => 55, 'perpetual_growth_rate' => 9,
            'products' => $products, 'rawMaterials' => $materials,
        ])->assertSessionHasNoErrors()->assertRedirect($this->filled($link, 'projects.edit', $project));

        $project->refresh();
        $this->assertSame('Plant A (customer)', $project->name, 'his other changes are saved');
        $this->assertEqualsWithDelta(22.5, (float) $project->tax_rate, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $project->return_rate, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $project->perpetual_growth_rate, 0.001);
    }

    public function test_a_view_link_cannot_save_anything(): void
    {
        $project = $this->study();
        $view = $this->link($project);
        $this->asGuest();

        $this->put('/fill/'.$view->link_code.'/projects/'.$project->id.'/fixed-assets', $this->assetsPayload())->assertNotFound();
        $this->put('/shared/'.$view->link_code.'/projects/'.$project->id.'/fixed-assets', $this->assetsPayload())->assertStatus(405);
        $this->assertSame(1, FixedAsset::where('project_id', $project->id)->count());
        $this->assertSame('Press', FixedAsset::where('project_id', $project->id)->value('name'));
    }

    public function test_the_signed_in_owner_who_opens_a_fill_link_fills_in_like_the_customer_but_a_view_link_stays_view_only(): void
    {
        $project = $this->study();
        $fill = $this->fillLink($project);
        $view = $this->link($project);

        // still signed in as the owner
        $this->actingAs($this->owner)->get($this->filled($fill, 'projects.expenses.edit', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('readOnly', false)->where('public.kind', 'fill'));
        $this->actingAs($this->owner)->get($this->shared($view, 'projects.expenses.edit', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('readOnly', true)->where('public.kind', 'view'));
        // inside the app the same page is still editable for him
        $this->actingAs($this->owner)->get(route('projects.expenses.edit', $project))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('readOnly', false)->where('public', null));
    }

    // ── Paused and expired ────────────────────────────────────────────────────────────

    public function test_a_paused_fill_link_shows_the_closed_page_and_refuses_the_saves(): void
    {
        $project = $this->study();
        $link = $this->fillLink($project);
        $this->actingAs($this->owner)->put(route('projects.links.toggle', [$project, $link]))->assertRedirect();
        $this->asGuest();

        foreach ([route('fill.show', $link->link_code), $this->filled($link, 'projects.edit', $project)] as $url) {
            $this->get($url)->assertStatus(410)->assertInertia(fn (Assert $page) => $page->component('Shared/Closed')->where('reason', 'paused'));
        }
        $this->put($this->filled($link, 'projects.assets.update', $project), $this->assetsPayload())->assertStatus(410);
        $this->assertSame('Press', FixedAsset::where('project_id', $project->id)->value('name'), 'nothing was saved');
    }

    public function test_an_expired_link_shows_the_expired_page_and_the_last_day_still_works(): void
    {
        $project = $this->study();
        $fill = $this->fillLink($project);
        $view = $this->link($project);
        $fill->update(['expires_at' => now()->subDay()->toDateString()]);
        $view->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->asGuest();

        $this->get($this->filled($fill, 'projects.edit', $project))->assertStatus(410)->assertInertia(fn (Assert $page) => $page->component('Shared/Closed')->where('reason', 'expired'));
        $this->get($this->shared($view, 'projects.dashboard', $project))->assertStatus(410)->assertInertia(fn (Assert $page) => $page->where('reason', 'expired'));
        $this->get(route('shared.show', $view->link_code))->assertStatus(410);
        $this->put($this->filled($fill, 'projects.assets.update', $project), $this->assetsPayload())->assertStatus(410);
        $this->assertSame(0, SharingLinkVisitor::count(), 'an expired link logs nothing');

        $fill->update(['expires_at' => now()->toDateString()]);
        $view->update(['expires_at' => now()->toDateString()]);
        $this->get($this->filled($fill, 'projects.edit', $project))->assertOk();
        $this->get($this->shared($view, 'projects.edit', $project))->assertOk();
    }

    public function test_the_owner_sets_changes_and_clears_the_expiry_date(): void
    {
        $project = $this->study();
        $link = $this->fillLink($project);
        $day = now()->addDays(10)->toDateString();

        $this->actingAs($this->owner)->put(route('projects.links.expiry', [$project, $link]), ['expires_at' => $day])->assertSessionHasNoErrors();
        $this->assertSame($day, $link->fresh()->expires_at->format('Y-m-d'));

        $this->actingAs($this->owner)->put(route('projects.links.expiry', [$project, $link]), ['expires_at' => null])->assertSessionHasNoErrors();
        $this->assertNull($link->fresh()->expires_at);

        $this->actingAs($this->owner)->put(route('projects.links.expiry', [$project, $link]), ['expires_at' => 'tomorrow-ish'])->assertSessionHasErrors('expires_at');
    }

    public function test_a_new_link_may_have_an_expiry_date_but_not_one_in_the_past(): void
    {
        $project = $this->study();
        $day = now()->addDays(5)->toDateString();

        $this->actingAs($this->owner)->post(route('projects.links.store', $project), ['name' => 'Bank', 'expires_at' => $day])->assertSessionHasNoErrors();
        $this->assertSame($day, SharingLink::where('name', 'Bank')->firstOrFail()->expires_at->format('Y-m-d'));

        $this->actingAs($this->owner)->post(route('projects.links.store', $project), ['name' => 'Old', 'expires_at' => now()->subDay()->toDateString()])->assertSessionHasErrors('expires_at');
        $this->assertSame(0, SharingLink::where('name', 'Old')->count());
    }

    public function test_only_the_owner_or_company_administrator_changes_the_expiry_date(): void
    {
        $company = Company::factory()->create();
        $this->owner = User::factory()->forCompany($company)->create();
        $colleague = User::factory()->forCompany($company)->create();
        $project = $this->study();
        $link = $this->fillLink($project);
        $this->actingAs($this->owner)->put(route('projects.shares', $project), ['shares' => [['user_id' => $colleague->id, 'can_edit' => true]]])->assertSessionHasNoErrors();

        $this->actingAs($colleague)->put(route('projects.links.expiry', [$project, $link]), ['expires_at' => now()->addDay()->toDateString()])->assertForbidden();
        $this->assertNull($link->fresh()->expires_at);
    }

    public function test_the_links_page_and_the_list_of_studies_tell_the_kind_the_status_and_the_date(): void
    {
        $project = $this->study();
        $fill = $this->fillLink($project, now()->addDays(3)->toDateString());
        $this->link($project, 'Bank');

        $links = $this->props($this->actingAs($this->owner)->get(route('projects.links', $project))->assertOk())['links'];
        $byType = collect($links)->keyBy('type');
        $this->assertSame(route('fill.show', $fill->link_code), $byType['fill']['url']);
        $this->assertSame('active', $byType['fill']['status']);
        $this->assertSame(now()->addDays(3)->toDateString(), $byType['fill']['expires_at']);
        $this->assertSame(route('shared.show', SharingLink::where('type', 'view')->value('link_code')), $byType['view']['url']);

        $study = collect($this->props($this->actingAs($this->owner)->get(route('projects.index'))->assertOk())['studies'])->firstWhere('id', $project->id);
        $this->assertSame($fill->id, $study['fill_link']['id']);
        $this->assertSame('fill', $study['fill_link']['type']);
        $this->assertSame('active', $study['fill_link']['status']);

        $fill->update(['closed' => true]);
        $study = collect($this->props($this->actingAs($this->owner)->get(route('projects.index'))->assertOk())['studies'])->firstWhere('id', $project->id);
        $this->assertSame('paused', $study['fill_link']['status']);

        $fill->update(['closed' => false, 'expires_at' => now()->subDay()->toDateString()]);
        $study = collect($this->props($this->actingAs($this->owner)->get(route('projects.index'))->assertOk())['studies'])->firstWhere('id', $project->id);
        $this->assertSame('expired', $study['fill_link']['status']);
    }

    public function test_a_study_without_a_fill_link_has_none_in_the_list(): void
    {
        $project = $this->study();
        $this->link($project);

        $study = collect($this->props($this->actingAs($this->owner)->get(route('projects.index'))->assertOk())['studies'])->firstWhere('id', $project->id);
        $this->assertNull($study['fill_link'], 'a view link is not a template link');
    }

    public function test_a_fill_link_stops_when_the_subscription_of_the_company_has_ended(): void
    {
        $project = $this->study();
        $link = $this->fillLink($project);
        Company::whereKey($project->company_id)->update(['subscription_ends_at' => now()->subDays(3)->toDateString()]);
        $this->asGuest();

        $this->get($this->filled($link, 'projects.edit', $project))->assertStatus(410);
        $this->put($this->filled($link, 'projects.assets.update', $project), $this->assetsPayload())->assertStatus(410);
    }

    public function test_deleting_a_fill_link_stops_it_at_once(): void
    {
        $project = $this->study();
        $link = $this->fillLink($project);
        $this->actingAs($this->owner)->delete(route('projects.links.destroy', [$project, $link]))->assertRedirect();
        $this->asGuest();

        $this->get(route('fill.show', $link->link_code))->assertNotFound();
        $this->get($this->filled($link, 'projects.edit', $project))->assertNotFound();
    }
}
