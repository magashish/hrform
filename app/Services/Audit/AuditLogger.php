<?php

namespace App\Services\Audit;

use App\Models\Applicant;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Writes allowlisted, non-sensitive audit events.
 *
 * - The event name must be declared in config/audit.php.
 * - Only metadata keys declared for that event are kept.
 * - Only scalar values are kept; strings are truncated.
 * - Request payloads are never serialized.
 */
class AuditLogger
{
    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $metadata = [],
        ?User $hrActor = null,
        ?string $applicantRef = null,
        ?string $actorType = null,
        ?string $actorId = null,
    ): AuditEvent {
        // Event names contain dots, so look them up without dot-notation.
        $allowed = (array) config('audit.events', []);
        $allowed = $allowed[$event] ?? null;

        if (! is_array($allowed)) {
            throw new InvalidArgumentException("Undeclared audit event [{$event}].");
        }

        $hrActor ??= $this->currentHrUser();

        if ($actorType === null) {
            $actorType = $hrActor ? AuditEvent::ACTOR_HR : AuditEvent::ACTOR_SYSTEM;
            $actorId = $hrActor ? (string) $hrActor->getKey() : null;
        }

        return AuditEvent::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event' => $event,
            'subject_type' => $subject ? $this->subjectType($subject) : null,
            'subject_id' => $subject ? $this->subjectId($subject) : null,
            'applicant_ref' => $applicantRef,
            'metadata' => $this->sanitize($metadata, $allowed) ?: null,
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $allowedKeys
     * @return array<string, scalar|null>
     */
    public function sanitize(array $metadata, array $allowedKeys): array
    {
        $max = (int) config('audit.max_value_length', 200);
        $clean = [];

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];

            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, $max);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            }
            // Arrays/objects are dropped deliberately.
        }

        return $clean;
    }

    private function subjectType(Model $subject): string
    {
        return match (true) {
            $subject instanceof User => 'hr_user',
            default => Str::snake(class_basename($subject)),
        };
    }

    /**
     * Applicants are referenced by their pseudonymous applicant_ref so the
     * reference stays meaningful (and non-identifying) after purge.
     */
    private function subjectId(Model $subject): string
    {
        return $subject instanceof Applicant ? $subject->applicant_ref : (string) $subject->getKey();
    }

    private function currentHrUser(): ?User
    {
        $user = $this->request?->user();

        return $user instanceof User ? $user : null;
    }

    private function ipAddress(): ?string
    {
        if (! config('portal.audit.record_ip') || ! $this->request) {
            return null;
        }

        return $this->request->ip();
    }

    private function userAgent(): ?string
    {
        if (! config('portal.audit.record_user_agent') || ! $this->request) {
            return null;
        }

        $ua = $this->request->userAgent();

        return $ua === null ? null : mb_substr($ua, 0, 255);
    }
}
