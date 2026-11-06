<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SecurityBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_hsts_only_when_enabled_and_secure(): void
    {
        $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

        config(['portal.security.hsts' => true]);
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    }

    public function test_pages_contain_no_inline_scripts_or_styles(): void
    {
        $this->actingAsHr();

        foreach (['/', '/hr', '/hr/users', '/hr/users/create', '/hr/security/two-factor'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $url);
            $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html, $url);
            $this->assertStringNotContainsString('<style', $html, $url);
        }
    }

    public function test_two_factor_qr_code_page_is_csp_compatible(): void
    {
        $this->useTotp();
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post('/hr/user/two-factor-authentication');

        $html = $this->get('/hr/security/two-factor')->assertOk()->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $html);
    }

    public function test_document_disks_are_private_and_not_served(): void
    {
        foreach (['documents', 'exports', 'local'] as $disk) {
            $this->assertFalse((bool) config("filesystems.disks.{$disk}.serve"), $disk);
            $this->assertStringNotContainsString(public_path(), (string) config("filesystems.disks.{$disk}.root"));
        }

        $this->assertNull(config('filesystems.disks.documents.url'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));

        Storage::fake('documents');
        $this->get('/storage/documents/anything')->assertNotFound();
    }

    public function test_session_is_hardened(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertContains(config('session.same_site'), ['lax', 'strict']);
        $this->assertLessThanOrEqual(60, config('session.lifetime'));
    }

    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->app->call([$this->app->make(DatabaseSeeder::class), 'run']);
    }
}
