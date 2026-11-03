<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_undeclared_events_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AuditLogger::class)->record('something.made_up');
    }

    public function test_only_allowlisted_scalar_metadata_is_kept(): void
    {
        config([
            'audit.events' => array_merge(config('audit.events'), ['test.event' => ['reason', 'count', 'nested']]),
            'audit.max_value_length' => 10,
        ]);

        $event = app(AuditLogger::class)->record('test.event', metadata: [
            'reason' => str_repeat('x', 50),
            'count' => 3,
            'nested' => ['ssn' => '123-45-6789'],
            'password' => 'secret',
            'form_data' => ['anything'],
        ]);

        $this->assertSame(['reason' => str_repeat('x', 10), 'count' => 3], $event->fresh()->metadata);
    }

    public function test_system_actor_when_no_user(): void
    {
        $event = app(AuditLogger::class)->record('auth.lockout');

        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $event->actor_type);
        $this->assertNull($event->actor_id);
    }

    public function test_hr_actor_and_subject_are_recorded(): void
    {
        $user = $this->hrUser();

        $event = app(AuditLogger::class)->record('hr_user.enabled', $user, hrActor: $user);

        $this->assertSame('hr', $event->actor_type);
        $this->assertSame((string) $user->id, $event->actor_id);
        $this->assertSame('hr_user', $event->subject_type);
    }

    public function test_ip_recording_can_be_disabled(): void
    {
        config(['portal.audit.record_ip' => false, 'portal.audit.record_user_agent' => false]);

        $event = app(AuditLogger::class)->record('auth.lockout');

        $this->assertNull($event->ip_address);
        $this->assertNull($event->user_agent);
    }

    public function test_audit_events_cannot_be_updated(): void
    {
        $event = app(AuditLogger::class)->record('auth.lockout');

        $this->expectException(LogicException::class);
        $event->update(['event' => 'auth.login']);
    }

    public function test_audit_events_cannot_be_deleted_through_the_model(): void
    {
        $event = app(AuditLogger::class)->record('auth.lockout');

        $this->expectException(LogicException::class);
        $event->delete();
    }
}
