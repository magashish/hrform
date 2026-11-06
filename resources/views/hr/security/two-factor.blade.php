@extends('layouts.app')

@section('title', 'Two-factor authentication')

@section('content')
    <section class="card narrow">
        <h1>Two-factor authentication</h1>

        @if ($method === 'email')
            <p><span class="badge badge-ok">On</span> Each time you sign in, a 6-digit code is emailed to <strong>{{ $user->email }}</strong>. You don't need an authenticator app.</p>
            <p class="hint">Keep your email account protected with its own strong password and two-step verification, because anyone who can read your email could receive these codes.</p>
        @else

        @if ($required && ! $user->hasConfirmedTwoFactor())
            <div class="alert alert-warning" role="alert">
                Two-factor authentication is required for all HR accounts. Set it up to continue.
            </div>
        @endif

        @if ($user->hasConfirmedTwoFactor())
            <p><span class="badge badge-ok">Enabled</span> Your account is protected by an authenticator app.</p>

            @if ($recoveryCodes)
                <div class="alert alert-warning" role="status">
                    <p><strong>Save these recovery codes now.</strong> Each can be used once if you lose your authenticator. They will not be shown again.</p>
                    <ul class="codes">
                        @foreach ($recoveryCodes as $code)
                            <li><code>{{ $code }}</code></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
                @csrf
                <button type="submit" class="button button-secondary">Generate new recovery codes</button>
            </form>

            @unless ($required)
                <form method="POST" action="{{ route('two-factor.disable') }}" class="mt">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button-danger">Disable two-factor authentication</button>
                </form>
            @endunless

            <p class="mt"><a href="{{ route('hr.dashboard') }}">Continue to dashboard</a></p>

        @elseif ($pendingConfirmation)
            <ol>
                <li>Scan this QR code with an authenticator app (for example Microsoft Authenticator, Google Authenticator or 1Password).</li>
                <li>Enter the 6-digit code the app shows to confirm.</li>
            </ol>

            <div class="qr" aria-label="QR code for authenticator setup">{!! $qrSvg !!}</div>

            <details>
                <summary>Can't scan? Enter this key manually</summary>
                <p><code class="secret">{{ $secretKey }}</code></p>
            </details>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt" novalidate>
                @csrf
                <div class="field">
                    <label for="code">Authentication code</label>
                    <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required>
                    @error('code', 'confirmTwoFactorAuthentication')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="actions">
                    <button type="submit" class="button">Confirm and enable</button>
                </div>
            </form>

        @else
            <p>You will need an authenticator app on your phone.</p>
            <form method="POST" action="{{ route('two-factor.enable') }}">
                @csrf
                <button type="submit" class="button">Set up two-factor authentication</button>
            </form>
        @endif
        @endif
    </section>
    @if ($method === 'email' || $user->hasConfirmedTwoFactor())
        <section class="card narrow">
            <h2>Change password</h2>

            @if (session('status') === 'password-updated')
                <div class="alert alert-success" role="status">Password changed.</div>
            @endif

            <form method="POST" action="{{ route('user-password.update') }}" novalidate>
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" required autocomplete="current-password">
                    @error('current_password', 'updatePassword')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="field">
                    <label for="new_password">New password</label>
                    <input id="new_password" type="password" name="password" required autocomplete="new-password">
                    @error('password', 'updatePassword')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <div class="field">
                    <label for="new_password_confirmation">Confirm new password</label>
                    <input id="new_password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                </div>
                <div class="actions">
                    <button type="submit" class="button">Change password</button>
                </div>
            </form>
        </section>
    @endif
@endsection
