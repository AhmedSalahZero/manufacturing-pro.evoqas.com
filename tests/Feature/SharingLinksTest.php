<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SharingLink;
use App\Models\SharingLinkVisitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsStudy;
use Tests\TestCase;

// Public read-only links: who may make them, what a visitor sees, switching off, the visit log.
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

    // ── What a visitor sees ─────────────────────────────────────────────────

    public function test_a_visitor_without_an_account_sees_the_dashboard_and_the_statements(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->actingAs($this->owner)->put(route('projects.dashboard.comments', $project), ['one' => 'Steady sales.', 'two' => ''])->assertSessionHasNoErrors();
        $this->asGuest();

        $props = $this->props($this->get(route('shared.show', $link->link_code))->assertOk());

        $this->assertTrue($props['ready']);
        $this->assertSame('Plant A', $props['project']['name']);
        $this->assertSame('Steady sales.', $props['comments']['one']);
        $this->assertCount(3, $props['dashboard']['years']);
        $this->assertCount(3, $props['statements']['years']);
        $this->assertNull($props['auth']['user']);
    }

    public function test_the_visitor_is_sent_nothing_but_the_results(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->asGuest();

        $json = json_encode($this->props($this->get(route('shared.show', $link->link_code))->assertOk()));

        $this->assertStringNotContainsString($this->owner->email, $json);
        $this->assertStringNotContainsString('company_id', $json);
        $this->assertStringNotContainsString('collection_policy_value', $json);
        $this->assertStringNotContainsString($link->link_code, $json);
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->get(route('shared.show', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_a_switched_off_link_shows_the_closed_page(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $link->update(['closed' => true]);
        $this->asGuest();

        $this->get(route('shared.show', $link->link_code))->assertStatus(410)
            ->assertInertia(fn (Assert $page) => $page->component('Shared/Closed'));
        $this->assertSame(0, SharingLinkVisitor::count(), 'a closed link logs nothing');
    }

    public function test_a_link_switched_on_again_works_again(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $link->update(['closed' => true]);
        $link->update(['closed' => false]);
        $this->asGuest();

        $this->get(route('shared.show', $link->link_code))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shared/Show'));
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

        $this->get(route('shared.show', $link->link_code))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shared/Show')->where('ready', false)->missing('dashboard'));
    }

    public function test_each_address_is_logged_once_an_hour(): void
    {
        $project = $this->study();
        $link = $this->link($project);
        $this->asGuest();

        $this->get(route('shared.show', $link->link_code))->assertOk();
        $this->get(route('shared.show', $link->link_code))->assertOk();
        $this->assertSame(1, SharingLinkVisitor::where('link_code', $link->link_code)->count());

        SharingLinkVisitor::where('link_code', $link->link_code)->update(['created_at' => now()->subHours(2)]);
        $this->get(route('shared.show', $link->link_code))->assertOk();
        $this->assertSame(2, SharingLinkVisitor::where('link_code', $link->link_code)->count());
    }
}
