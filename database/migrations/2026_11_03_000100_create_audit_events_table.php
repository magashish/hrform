<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail.
     *
     * No foreign keys: audit rows reference actors/subjects by type + id and
     * applicants by a random pseudonymous UUID, so purging applicant data
     * never cascades into (or is blocked by) the audit trail.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20);          // hr | applicant | system
            $table->string('actor_id', 64)->nullable(); // user id or applicant_ref
            $table->string('event', 80);
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->uuid('applicant_ref')->nullable();
            $table->json('metadata')->nullable();       // allowlisted keys only
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at');

            $table->index('event');
            $table->index('occurred_at');
            $table->index('applicant_ref');
            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Database-level enforcement: updates are never allowed, deletes
            // only inside a transaction that has explicitly set
            // app.audit_purge = 'on' (used by the audit retention job).
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_events_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION 'audit_events is append-only';
                    END IF;
                    IF TG_OP = 'DELETE' AND coalesce(current_setting('app.audit_purge', true), '') <> 'on' THEN
                        RAISE EXCEPTION 'audit_events deletes require app.audit_purge';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_events_guard
                    BEFORE UPDATE OR DELETE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION audit_events_guard();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_events_guard ON audit_events; DROP FUNCTION IF EXISTS audit_events_guard();');
        }

        Schema::dropIfExists('audit_events');
    }
};
