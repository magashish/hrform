<?php

use App\Http\Controllers\Hr\ApplicantController;
use App\Http\Controllers\Hr\ConsentVersionController;
use App\Http\Controllers\Hr\DashboardController;
use App\Http\Controllers\Hr\DocumentReviewController;
use App\Http\Controllers\Hr\EmailCodeController;
use App\Http\Controllers\Hr\ExportController;
use App\Http\Controllers\Hr\FileController;
use App\Http\Controllers\Hr\HrUserController;
use App\Http\Controllers\Hr\InvitationController;
use App\Http\Controllers\Hr\PacketController;
use App\Http\Controllers\Hr\SettingsController;
use App\Http\Controllers\Hr\SystemController;
use App\Http\Controllers\Hr\TemplateController;
use App\Http\Controllers\Hr\TwoFactorSetupController;
use App\Http\Controllers\Portal\AccessController;
use App\Http\Controllers\Portal\ConsentController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\HomeController;
use App\Http\Controllers\Webhooks\EsignWebhookController;
use App\Http\Middleware\EnsureApplicantSession;
use App\Http\Middleware\EnsureConsentAcknowledged;
use App\Http\Middleware\EnsureHrUserIsActive;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RequireEmailCodeIfEmailMethod;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
Route::pattern('applicant', $uuid);
Route::pattern('document', $uuid);
Route::pattern('export', $uuid);
Route::pattern('file', '[0-9]+');
Route::pattern('kind', 'version|supporting');

Route::view('/', 'welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Applicant portal
|--------------------------------------------------------------------------
| No passwords: invitation code -> email confirmation -> email OTP. Every
| page after sign-in re-validates the session (EnsureApplicantSession).
*/

Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('access', [AccessController::class, 'show'])->name('access');
    Route::post('access', [AccessController::class, 'store'])->middleware('throttle:portal-code')->name('access.store');
    Route::get('verify', [AccessController::class, 'verify'])->name('verify');
    Route::post('verify/email', [AccessController::class, 'requestOtp'])->middleware('throttle:portal-otp')->name('verify.email');
    Route::post('verify/code', [AccessController::class, 'verifyOtp'])->middleware('throttle:portal-otp')->name('verify.code');

    Route::middleware(EnsureApplicantSession::class)->group(function () {
        Route::post('logout', [AccessController::class, 'logout'])->name('logout');
        Route::get('consent', [ConsentController::class, 'show'])->name('consent');
        Route::post('consent', [ConsentController::class, 'store'])->name('consent.store');

        Route::middleware(EnsureConsentAcknowledged::class)->group(function () {
            Route::get('/', HomeController::class)->name('home');
            Route::get('complete', [DocumentController::class, 'complete'])->name('complete');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
            Route::put('documents/{document}/draft', [DocumentController::class, 'saveDraft'])->name('documents.draft');
            Route::post('documents/{document}/acknowledge', [DocumentController::class, 'acknowledge'])->name('documents.acknowledge');
            Route::post('documents/{document}/upload', [DocumentController::class, 'upload'])->middleware('throttle:portal-upload')->name('documents.upload');
            Route::post('documents/{document}/supporting/{requirement}', [DocumentController::class, 'uploadSupporting'])
                ->where('requirement', '[a-z0-9_]{1,60}')->middleware('throttle:portal-upload')->name('documents.supporting');
            Route::get('documents/{document}/review', [DocumentController::class, 'review'])->name('documents.review');
            Route::post('documents/{document}/submit', [DocumentController::class, 'submit'])->name('documents.submit');
            Route::get('documents/{document}/print', [DocumentController::class, 'printForm'])->name('documents.print');
            Route::get('documents/{document}/files/{kind}/{file}', [DocumentController::class, 'downloadOwn'])->name('documents.file');
        });
    });
});

/*
|--------------------------------------------------------------------------
| HR area
|--------------------------------------------------------------------------
| Fortify registers /hr/login, /hr/two-factor-challenge, /hr/forgot-password,
| /hr/reset-password, /hr/user/confirm-password and /hr/user/two-factor-*.
*/

Route::prefix('hr')->name('hr.')->middleware(['auth', EnsureHrUserIsActive::class])->group(function () {
    // Emailed sign-in code (HR_2FA_METHOD=email).
    Route::get('verify-code', [EmailCodeController::class, 'show'])->name('email-code');
    Route::post('verify-code', [EmailCodeController::class, 'verify'])->middleware('throttle:hr-code-verify')->name('email-code.verify');
    Route::post('verify-code/resend', [EmailCodeController::class, 'resend'])->middleware('throttle:hr-code-resend')->name('email-code.resend');

    // With the email method this page also requires the emailed code (the
    // middleware lets TOTP users without an app through so they can enrol).
    Route::get('security/two-factor', TwoFactorSetupController::class)
        ->middleware(['password.confirm', RequireEmailCodeIfEmailMethod::class])
        ->name('security.two-factor');

    Route::middleware(EnsureTwoFactorEnabled::class)->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        // Applicants
        Route::get('applicants', [ApplicantController::class, 'index'])->name('applicants.index');
        Route::get('applicants/create', [ApplicantController::class, 'create'])->name('applicants.create');
        Route::post('applicants', [ApplicantController::class, 'store'])->name('applicants.store');
        Route::get('applicants/{applicant}', [ApplicantController::class, 'show'])->name('applicants.show');
        Route::get('applicants/{applicant}/edit', [ApplicantController::class, 'edit'])->name('applicants.edit');
        Route::put('applicants/{applicant}', [ApplicantController::class, 'update'])->name('applicants.update');

        Route::post('applicants/{applicant}/invitations', [InvitationController::class, 'store'])->name('invitations.store');
        Route::delete('applicants/{applicant}/invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->whereNumber('invitation')->name('invitations.destroy');

        // Documents and review
        Route::post('applicants/{applicant}/documents', [DocumentReviewController::class, 'assign'])->name('documents.assign');
        Route::get('applicants/{applicant}/documents/{document}', [DocumentReviewController::class, 'show'])->name('documents.show');
        Route::delete('applicants/{applicant}/documents/{document}', [DocumentReviewController::class, 'unassign'])->name('documents.unassign');
        Route::post('applicants/{applicant}/documents/{document}/approve', [DocumentReviewController::class, 'approve'])->name('documents.approve');
        Route::post('applicants/{applicant}/documents/{document}/corrections', [DocumentReviewController::class, 'requestCorrection'])->name('documents.corrections');
        Route::post('applicants/{applicant}/documents/{document}/reveal', [DocumentReviewController::class, 'reveal'])
            ->middleware('password.confirm')->name('documents.reveal');

        // Files and exports
        Route::get('applicants/{applicant}/files/{kind}/{file}', [FileController::class, 'show'])->name('files.show');
        Route::delete('applicants/{applicant}/files/{kind}/{file}', [FileController::class, 'destroy'])
            ->middleware('password.confirm')->name('files.destroy');
        Route::post('applicants/{applicant}/exports', [ExportController::class, 'store'])->name('exports.store');
        Route::get('applicants/{applicant}/exports/{export}', [ExportController::class, 'download'])->name('exports.download');

        // Packet lifecycle
        Route::post('applicants/{applicant}/transfer', [PacketController::class, 'transfer'])->name('applicants.transfer');
        Route::post('applicants/{applicant}/legal-hold', [PacketController::class, 'legalHold'])
            ->middleware('password.confirm')->name('applicants.legal-hold');
        Route::delete('applicants/{applicant}', [PacketController::class, 'destroy'])
            ->middleware('password.confirm')->name('applicants.destroy');

        // Configuration (all HR users have equal permissions; writes re-confirm the password)
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/create', [TemplateController::class, 'create'])->name('templates.create');
        Route::post('templates', [TemplateController::class, 'store'])->middleware('password.confirm')->name('templates.store');
        Route::get('templates/{template}/edit', [TemplateController::class, 'edit'])->whereNumber('template')->name('templates.edit');
        Route::put('templates/{template}', [TemplateController::class, 'update'])->whereNumber('template')->middleware('password.confirm')->name('templates.update');
        Route::post('templates/{template}/fields', [TemplateController::class, 'addField'])->whereNumber('template')->middleware('password.confirm')->name('templates.fields.store');
        Route::delete('templates/{template}/fields/{field}', [TemplateController::class, 'removeField'])->whereNumber(['template', 'field'])->middleware('password.confirm')->name('templates.fields.destroy');
        Route::post('templates/{template}/print-asset', [TemplateController::class, 'uploadPrintAsset'])->whereNumber('template')->middleware('password.confirm')->name('templates.print-asset');
        Route::get('templates/{template}/print-asset', [FileController::class, 'templateAsset'])->whereNumber('template')->name('templates.print-asset.show');
        Route::post('templates/{template}/approvals', [TemplateController::class, 'recordApproval'])->whereNumber('template')->middleware('password.confirm')->name('templates.approvals.store');

        Route::get('consent', [ConsentVersionController::class, 'index'])->name('consent.index');
        Route::get('consent/create', [ConsentVersionController::class, 'create'])->name('consent.create');
        Route::post('consent', [ConsentVersionController::class, 'store'])->middleware('password.confirm')->name('consent.store');
        Route::get('consent/{consent}', [ConsentVersionController::class, 'show'])->whereNumber('consent')->name('consent.show');
        Route::post('consent/{consent}/activate', [ConsentVersionController::class, 'activate'])->whereNumber('consent')->middleware('password.confirm')->name('consent.activate');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('password.confirm')->name('settings.update');
        Route::get('system', SystemController::class)->name('system');

        // HR users
        Route::get('users', [HrUserController::class, 'index'])->name('users.index');
        Route::get('users/create', [HrUserController::class, 'create'])->name('users.create');
        Route::post('users', [HrUserController::class, 'store'])->middleware('password.confirm')->name('users.store');
        Route::post('users/{user}/disable', [HrUserController::class, 'disable'])->whereNumber('user')->middleware('password.confirm')->name('users.disable');
        Route::post('users/{user}/enable', [HrUserController::class, 'enable'])->whereNumber('user')->middleware('password.confirm')->name('users.enable');
    });
});

/*
| Future e-signature provider webhooks (404 while no provider is configured).
*/
Route::post('webhooks/esign/{provider}', EsignWebhookController::class)
    ->where('provider', '[a-z0-9_]{1,40}')
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.esign');
