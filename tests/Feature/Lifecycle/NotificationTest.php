<?php

namespace Tests\Feature\Lifecycle;

use App\Mail\ApplicantNoticeMail;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Notifications\HrAlert;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PortalHelpers;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use PortalHelpers, RefreshDatabase;

    public function test_hr_is_notified_of_uploads_submissions_and_completion_without_personal_data(): void
    {
        Notification::fake();
        $hrA = $this->hrUser();
        $hrB = $this->hrUser();
        $disabled = User::factory()->withTwoFactor()->disabled()->create();
        [$applicant] = $this->applicantWithInvitation(['first_name' => 'Secret', 'last_name' => 'Name'], $hrA);
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create(['name' => 'Direct deposit']));

        $this->signInApplicant($applicant);
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf()]);
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1']);

        $subjects = [];
        Notification::assertSentTo([$hrA, $hrB], HrAlert::class, function (HrAlert $n, $channels, $notifiable) use (&$subjects) {
            $subjects[] = $n->subject;
            $mail = $n->toMail($notifiable)->render();
            $this->assertStringNotContainsString('Secret', $mail);
            $this->assertStringNotContainsString('Name', $n->subject.$n->line);

            return true;
        });
        Notification::assertNotSentTo($disabled, HrAlert::class);

        $this->assertContains('File uploaded: Direct deposit', $subjects);
        $this->assertContains('Document submitted: Direct deposit', $subjects);
        $this->assertContains('Onboarding packet completed', $subjects);
    }

    public function test_recipients_and_events_follow_settings(): void
    {
        Notification::fake();
        $hr = $this->hrUser();
        app(SettingsService::class)->update([
            'hr_notification_recipients' => 'hr-team@example.com',
            'hr_notification_events' => ['packet_completed'],
        ], $hr);

        [$applicant] = $this->applicantWithInvitation(hr: $hr);
        $doc = $this->assign($applicant, DocumentTemplate::factory()->create());
        $this->signInApplicant($applicant);
        $this->post("/portal/documents/{$doc->id}/upload", ['file' => $this->pdf()]);
        $this->post("/portal/documents/{$doc->id}/submit", ['confirm' => '1']);

        Notification::assertNotSentTo($hr, HrAlert::class);
        Notification::assertSentOnDemand(HrAlert::class, function (HrAlert $n, $channels, $notifiable) {
            return $notifiable->routes['mail'] === ['hr-team@example.com'] && $n->subject === 'Onboarding packet completed';
        });
        Notification::assertSentOnDemandTimes(HrAlert::class, 1);
    }

    public function test_settings_are_validated_and_audited(): void
    {
        $hr = $this->hrUser();
        $settings = app(SettingsService::class);

        try {
            $settings->update(['retention_days' => 90], $hr);
            $this->fail('Retention over 60 days should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('retention_days', $e->errors());
        }

        try {
            $settings->update(['filename_pattern' => '../{last_name}'], $hr);
            $this->fail('Unsafe pattern should be rejected.');
        } catch (ValidationException) {
        }

        try {
            $settings->update(['hr_notification_recipients' => 'not-an-email'], $hr);
            $this->fail('Bad email should be rejected.');
        } catch (ValidationException) {
        }

        $settings->update(['retention_days' => 30, 'unknown_key' => 'x'], $hr);
        $this->assertSame(30, (int) $settings->get('retention_days'));
        $this->assertDatabaseMissing('settings', ['key' => 'unknown_key']);
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.updated']);
    }

    public function test_settings_page_saves_through_http(): void
    {
        $this->actingAsHr();
        $this->get('/hr/settings')->assertOk()->assertSee('Filename pattern');

        $values = app(SettingsService::class)->all();
        $values['reminder_days_before_expiry'] = '2';
        $values['hr_notification_events'] = ['document_submitted'];

        $this->put('/hr/settings', $values)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2', app(SettingsService::class)->get('reminder_days_before_expiry'));
    }

    public function test_expiry_reminders_are_sent_once(): void
    {
        Mail::fake();
        [$applicant] = $this->applicantWithInvitation();

        $this->artisan('portal:reminders:send')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->travel(4)->days(); // under 3 days before expiry
        $this->travel(5)->minutes();
        $this->artisan('portal:reminders:send')->assertSuccessful();
        $this->artisan('portal:reminders:send')->assertSuccessful();

        Mail::assertQueued(ApplicantNoticeMail::class, 1);
        Mail::assertQueued(ApplicantNoticeMail::class, fn (ApplicantNoticeMail $m) => $m->hasTo($applicant->email) && str_contains($m->render(), 'expires on'));

        $this->travel(2)->days(); // 1 day before expiry
        $this->artisan('portal:reminders:send')->assertSuccessful();
        Mail::assertQueued(ApplicantNoticeMail::class, 2);
        $this->assertDatabaseHas('audit_events', ['event' => 'reminder.sent']);
    }

    public function test_incomplete_reminder_and_no_reminders_after_submission(): void
    {
        Mail::fake();
        app(SettingsService::class)->update(['reminder_days_before_expiry' => '', 'reminder_incomplete_every_days' => 2], $this->hrUser());
        [$active] = $this->applicantWithInvitation();
        [$done] = $this->applicantWithInvitation();
        $done->forceFill(['status' => 'submitted'])->save();

        $this->travel(2)->days();
        $this->travel(1)->hours();
        $this->artisan('portal:reminders:send')->assertSuccessful();

        Mail::assertQueued(ApplicantNoticeMail::class, fn ($m) => $m->hasTo($active->email));
        Mail::assertNotQueued(ApplicantNoticeMail::class, fn ($m) => $m->hasTo($done->email));
    }
}
