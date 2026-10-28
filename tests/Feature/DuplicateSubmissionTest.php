<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// The same form sent twice within a few seconds is recorded once. Run:  php artisan test --filter=DuplicateSubmissionTest
class DuplicateSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in for any future "save" screen.
        Route::middleware(['web', 'auth', 'no-duplicate'])->group(function () {
            Route::post('/_test/save', fn () => response('saved'));
            Route::delete('/_test/remove', fn () => response('removed'));
        });
    }

    public function test_the_first_send_is_accepted_and_an_identical_second_one_is_not(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/_test/save', ['name' => 'Press'])->assertOk()->assertSee('saved');

        $this->from('/home')->post('/_test/save', ['name' => 'Press'])
            ->assertRedirect('/home')
            ->assertSessionHas('error', __('errors.duplicate_submission'));
    }

    public function test_a_different_entry_is_accepted(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/_test/save', ['name' => 'Press'])->assertOk();
        $this->post('/_test/save', ['name' => 'Lathe'])->assertOk();
    }

    public function test_the_order_of_the_fields_does_not_matter(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/_test/save', ['a' => 1, 'b' => 2])->assertOk();
        $this->from('/home')->post('/_test/save', ['b' => 2, 'a' => 1])->assertSessionHas('error');
    }

    public function test_two_different_people_may_send_the_same_thing(): void
    {
        $this->actingAs(User::factory()->create())->post('/_test/save', ['name' => 'Press'])->assertOk();

        $this->actingAs(User::factory()->create())->post('/_test/save', ['name' => 'Press'])->assertOk();
    }

    public function test_deleting_twice_is_never_blocked(): void
    {
        $this->actingAs(User::factory()->create());

        $this->delete('/_test/remove')->assertOk();
        $this->delete('/_test/remove')->assertOk();
    }
}
