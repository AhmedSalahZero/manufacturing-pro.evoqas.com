<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use App\Models\SharingLink;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The first screen after signing in. Run:  php artisan test --filter=HomeScreenTest
class HomeScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_shows_the_signed_in_person(): void
    {
        $user = User::factory()->create(['name' => 'Mona Ali']);

        $this->actingAs($user)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('auth.user.name', 'Mona Ali')
            ->where('auth.user.initials', 'MA')
            ->where('auth.user.email', $user->email)
            ->where('app.name', 'ZAVERO Manufacturing Pro')
            ->has('flash')
            ->has('timezone'));
    }

    public function test_the_password_never_reaches_the_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/home');

        $response->assertDontSee($user->password, false);
        $response->assertInertia(fn (Assert $page) => $page->missing('auth.user.password'));
    }

    public function test_the_page_title_is_the_application_name(): void
    {
        $this->actingAs(User::factory()->create())->get('/home')->assertSee('ZAVERO Manufacturing Pro', false);
    }

    public function test_home_counts_my_studies_and_lists_the_last_ones(): void
    {
        $owner = User::factory()->create();
        app(ProjectService::class)->create($owner, 'Plant A');
        app(ProjectService::class)->create($owner, 'Plant B');

        $colleague = User::factory()->create(['company_id' => $owner->company_id]);
        $theirs = app(ProjectService::class)->create($colleague, 'Their plant');
        ProjectShare::create(['project_id' => $theirs->id, 'user_id' => $owner->id, 'can_edit' => false]);

        $this->actingAs($owner)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('stats.studies', 3)
            ->where('stats.shared', 1)
            ->where('stats.edited_week', 3)
            ->where('stats.open_links', 0)
            ->has('recent', 3)
            ->has('recent.0', fn (Assert $row) => $row->hasAll(['id', 'name', 'completed', 'duration', 'access', 'updated_at'])));

    }

    public function test_home_does_not_count_studies_nobody_shared_with_me(): void
    {
        $owner = User::factory()->create();
        app(ProjectService::class)->create($owner, 'Private plant');
        $other = User::factory()->create(['company_id' => $owner->company_id]);

        $this->actingAs($other)->get('/home')->assertInertia(fn (Assert $page) => $page
            ->where('stats.studies', 0)
            ->has('recent', 0));
    }

    public function test_only_open_sharing_links_are_counted(): void
    {
        $owner = User::factory()->create();
        $project = app(ProjectService::class)->create($owner, 'Plant A');
        SharingLink::create(['project_id' => $project->id, 'user_id' => $owner->id, 'link_code' => 'open-one', 'closed' => false]);
        SharingLink::create(['project_id' => $project->id, 'user_id' => $owner->id, 'link_code' => 'shut-one', 'closed' => true]);

        $this->actingAs($owner)->get('/home')->assertInertia(fn (Assert $page) => $page->where('stats.open_links', 1));
    }
}
