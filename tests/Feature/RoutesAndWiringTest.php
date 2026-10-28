<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

// The server's pieces agree with each other. Run:  php artisan test --filter=RoutesAndWiringTest
class RoutesAndWiringTest extends TestCase
{
    public function test_every_route_points_to_a_real_controller_method_and_known_middleware(): void
    {
        // Laravel only loads its middleware names (auth, guest, throttle, web ...) into the router when the
        // web kernel is created, which normally happens at the first page request. Create it now.
        app(\Illuminate\Contracts\Http\Kernel::class);

        $router = app('router');
        $known = array_merge(
            ['web', 'api', 'auth', 'guest', 'throttle', 'active', 'no-duplicate', 'read-only', 'super', 'company', 'company-admin'],
            array_keys($router->getMiddleware()),
            array_keys($router->getMiddlewareGroups())
        );

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $action = $route->getActionName();

            if ($action !== 'Closure' && str_contains($action, '@')) {
                [$class, $method] = explode('@', $action);
                $this->assertTrue(class_exists($class), "{$route->uri()}: controller {$class} does not exist");
                $this->assertTrue(method_exists($class, $method), "{$route->uri()}: {$class}::{$method} does not exist");
            }

            foreach ($route->middleware() as $name) {
                $base = explode(':', (string) $name)[0];
                $this->assertTrue(
                    in_array($base, $known, true) || class_exists($base),
                    "{$route->uri()}: unknown middleware '{$base}'"
                );
            }
        }
    }

    public function test_the_routes_the_screens_need_exist(): void
    {
        foreach (['login', 'logout', 'home', 'password.request', 'password.email', 'password.reset', 'password.store', 'preferences.update', 'pwa.sw', 'pwa.manifest', 'csp.report'] as $name) {
            $this->assertTrue(RouteFacade::has($name), "route '{$name}' is missing");
        }
    }

    public function test_the_signed_in_area_is_protected(): void
    {
        $home = RouteFacade::getRoutes()->getByName('home');

        $this->assertContains('auth', $home->middleware());
        $this->assertContains('active', $home->middleware());
        $this->assertContains('no-duplicate', $home->middleware());
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@x.test', 'password' => 'Passw0rd-long-1'])->assertNotFound();
    }

    public function test_the_application_is_set_up_for_both_languages(): void
    {
        $this->assertSame(['en', 'ar'], config('zavero.locales'));
        $this->assertSame(['ar'], config('zavero.rtl_locales'));
        $this->assertSame('ZAVERO Manufacturing Pro', config('app.name'));
        $this->assertSame('utf8mb4', config('database.connections.mysql.charset'));
        $this->assertSame('utf8mb4_unicode_ci', config('database.connections.mysql.collation'));
    }

    public function test_the_health_address_answers(): void
    {
        $this->get('/up')->assertOk();
    }
}
