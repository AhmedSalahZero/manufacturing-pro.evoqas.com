<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Browser protections and "never store signed-in pages". Run:  php artisan test --filter=SecurityTest
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_the_standard_protections(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_the_content_security_policy_starts_in_report_only_mode(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $this->assertFalse($response->headers->has('Content-Security-Policy'));

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=_-]+'/", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function test_the_policy_can_be_enforced_or_switched_off(): void
    {
        config(['security.csp' => 'enforce']);
        $this->get('/login')->assertHeader('Content-Security-Policy');

        config(['security.csp' => 'off']);
        $off = $this->get('/login');
        $this->assertFalse($off->headers->has('Content-Security-Policy'));
        $this->assertFalse($off->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_signed_in_pages_may_never_be_stored(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/home');

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $response->assertHeader('Pragma', 'no-cache');
    }

    public function test_the_sign_in_page_is_not_marked_no_store(): void
    {
        $this->assertStringNotContainsString('no-store', (string) $this->get('/login')->headers->get('Cache-Control'));
    }

    public function test_the_browser_security_report_address_answers_quietly(): void
    {
        $this->postJson('/csp-report', ['csp-report' => ['blocked-uri' => 'https://evil.example/x.js', 'effective-directive' => 'script-src']])->assertNoContent();
        $this->postJson('/csp-report', ['csp-report' => ['blocked-uri' => 'chrome-extension://abc', 'effective-directive' => 'script-src']])->assertNoContent();
        $this->post('/csp-report', [])->assertNoContent();
    }

    public function test_signing_out_works_even_when_the_page_security_token_expired(): void
    {
        $this->actingAs(User::factory()->create());

        $this->withMiddleware()->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
