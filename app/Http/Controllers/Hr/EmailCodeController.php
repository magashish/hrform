<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\HrEmailCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Enter the code we emailed you" step after the HR password.
 */
class EmailCodeController extends Controller
{
    public function __construct(private readonly HrEmailCodeService $codes) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! HrEmailCodeService::usesEmail() || $this->codes->hasPassed($request, $user)) {
            return redirect()->route('hr.dashboard');
        }

        // Send the first code automatically when the step is reached.
        if ((int) $request->session()->get(HrEmailCodeService::SESSION_SENT) !== $user->id) {
            if (! $this->codes->send($request, $user)) {
                $request->session()->flash('code_error', 'We could not send a code right now. Please wait a few minutes and try again.');
            }
        }

        return view('auth.email-code', ['email' => $this->maskEmail($user->email)]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! $this->codes->verify($request, $request->user(), (string) $request->input('code'))) {
            return back()->withErrors(['code' => 'That code is not valid or has expired. Check your email or send a new code.']);
        }

        return redirect()->intended(route('hr.dashboard'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $sent = $this->codes->send($request, $request->user());

        return back()->with($sent ? 'status' : 'code_error', $sent
            ? 'A new code has been sent.'
            : 'Too many codes requested. Please wait 15 minutes and try again.');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
