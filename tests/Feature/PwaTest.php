<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

// The installable app and its offline helper. Run:  php artisan test --filter=PwaTest
class PwaTest extends TestCase
{
    /**
     * Serves a file the build normally makes (public/build/...). If you have not built yet, a stand-in is
     * made for the test and removed again; a real build is never touched.
     */
    private function withBuildFile(string $name, string $content, callable $assert): void
    {
        $path = public_path("build/{$name}");
        $existed = is_file($path);

        if (! $existed) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $content);
        }

        try {
            $assert();
        } finally {
            if (! $existed) {
                File::delete($path);
            }
        }
    }

    public function test_the_offline_helper_is_served_from_the_site_root_and_never_cached(): void
    {
        $this->withBuildFile('sw.js', '// test worker', function () {
            $response = $this->get('/sw.js');

            $response->assertOk();
            $response->assertHeader('Service-Worker-Allowed', '/');
            $this->assertStringContainsString('javascript', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        });
    }

    public function test_the_app_manifest_is_served_from_the_site_root(): void
    {
        $this->withBuildFile('manifest.webmanifest', '{"name":"test"}', function () {
            $response = $this->get('/manifest.webmanifest');

            $response->assertOk();
            $this->assertStringContainsString('manifest+json', $response->headers->get('Content-Type'));
        });
    }

    public function test_both_answer_not_found_before_the_first_build(): void
    {
        if (is_file(public_path('build/sw.js')) || is_file(public_path('build/manifest.webmanifest'))) {
            $this->markTestSkipped('The app was already built, so these files exist.');
        }

        $this->get('/sw.js')->assertNotFound();
        $this->get('/manifest.webmanifest')->assertNotFound();
    }

    public function test_the_pages_point_to_the_manifest_and_the_icons(): void
    {
        $this->get('/login')
            ->assertSee('rel="manifest" href="/manifest.webmanifest"', false)
            ->assertSee('/images/icons/apple-touch-icon.png', false)
            ->assertSee('name="theme-color"', false);
    }

    public function test_the_icons_and_pictures_exist(): void
    {
        foreach (['logo.png', 'login-bg.jpg', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/maskable-512.png', 'icons/apple-touch-icon.png', 'icons/favicon-64.png'] as $file) {
            $this->assertFileExists(public_path("images/{$file}"));
        }

        [$w, $h] = getimagesize(public_path('images/icons/icon-512.png'));
        $this->assertSame([512, 512], [$w, $h]);
        [$w, $h] = getimagesize(public_path('images/icons/icon-192.png'));
        $this->assertSame([192, 192], [$w, $h]);
    }

    public function test_the_offline_page_speaks_both_languages_and_depends_on_nothing(): void
    {
        $html = file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString('You are offline', $html);
        $this->assertStringContainsString('أنت غير متصل بالإنترنت', $html);
        $this->assertStringNotContainsString('<script src=', $html);
        $this->assertStringNotContainsString('rel="stylesheet"', $html);
    }

    public function test_the_offline_helper_never_stores_signed_in_pages(): void
    {
        $source = file_get_contents(resource_path('js/pwa/sw.js'));

        // Pages always go to the server; only the offline page is a fallback.
        $this->assertStringContainsString('await fetch(request)', $source);
        $this->assertStringContainsString("matchPrecache('/offline.html')", $source);
        // No page cache is ever written.
        $this->assertStringNotContainsString('caches.open', $source);
        $this->assertStringNotContainsString('StaleWhileRevalidate', $source);
        $this->assertStringNotContainsString('NetworkFirst', $source);
    }
}
