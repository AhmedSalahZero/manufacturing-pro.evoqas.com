<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// The second top bar: every page of a study receives the study's products and kind; no other page does.
class StudyNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_of_a_study_carries_its_products_for_the_study_bar(): void
    {
        $this->seed(DemoSeeder::class);
        $user = User::where('email', DemoSeeder::EMAIL)->firstOrFail();
        $new = Project::where('name', DemoSeeder::STUDY_NEW)->firstOrFail();
        $existing = Project::where('name', DemoSeeder::STUDY_EXISTING)->firstOrFail();

        foreach (['projects.edit', 'projects.manpower.edit', 'projects.expenses.edit', 'projects.assets.edit', 'projects.statements', 'projects.dashboard', 'projects.report', 'projects.links', 'projects.raw-materials.edit'] as $route) {
            $this->actingAs($user)->get(route($route, $new))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('study.id', $new->id)->where('study.new_company', true)->has('study.products', 4)->where('study.has_raw', true));
        }

        $this->actingAs($user)->get(route('projects.opening.edit', $existing))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('study.new_company', false)->has('study.products', 3)->where('study.products.0.name', fn ($name) => is_string($name)));
    }

    public function test_pages_outside_a_study_have_no_study_bar(): void
    {
        $this->seed(DemoSeeder::class);
        $user = User::where('email', DemoSeeder::EMAIL)->firstOrFail();

        $this->actingAs($user)->get('/home')->assertOk()->assertInertia(fn (Assert $page) => $page->where('study', null));
        $this->actingAs($user)->get(route('projects.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('study', null));
    }
}
