<?php

namespace Tests\Feature\Audit;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database-level append-only guard (PostgreSQL only). Run with:
 *   DB_CONNECTION=pgsql DB_DATABASE=onboarding_test ... php artisan test
 */
class AuditDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only trigger test.');
        }
    }

    public function test_raw_updates_are_blocked(): void
    {
        app(AuditLogger::class)->record('auth.lockout');

        $this->expectException(QueryException::class);
        DB::table('audit_events')->update(['event' => 'tampered']);
    }

    public function test_raw_deletes_are_blocked_without_purge_flag(): void
    {
        app(AuditLogger::class)->record('auth.lockout');

        $this->expectException(QueryException::class);
        DB::table('audit_events')->delete();
    }

    public function test_deletes_allowed_with_explicit_purge_flag(): void
    {
        app(AuditLogger::class)->record('auth.lockout');

        DB::statement("SET LOCAL app.audit_purge = 'on'");
        DB::table('audit_events')->delete();

        $this->assertSame(0, DB::table('audit_events')->count());
    }
}
