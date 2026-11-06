<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Onboarding Portal') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="{{ auth()->check() ? route('hr.dashboard') : route('home') }}">{{ config('app.name') }}</a>

        @php($hrReady = auth()->check() && (! config('portal.hr.two_factor_required')
            || (config('portal.hr.two_factor_method') === 'email'
                ? (int) session(\App\Services\Hr\HrEmailCodeService::SESSION_PASSED) === auth()->id()
                : auth()->user()->hasConfirmedTwoFactor())))
        @auth
            @unless ($hrReady)
            <nav aria-label="HR navigation" class="nav">
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="link-button">Log out</button>
                </form>
            </nav>
            @else
            <nav aria-label="HR navigation" class="nav">
                <a href="{{ route('hr.dashboard') }}" @class(['active' => request()->routeIs('hr.dashboard')])>Dashboard</a>
                <a href="{{ route('hr.applicants.index') }}" @class(['active' => request()->routeIs('hr.applicants.*', 'hr.documents.*')])>Applicants</a>
                <a href="{{ route('hr.templates.index') }}" @class(['active' => request()->routeIs('hr.templates.*')])>Documents</a>
                <a href="{{ route('hr.consent.index') }}" @class(['active' => request()->routeIs('hr.consent.*')])>Disclosure</a>
                <a href="{{ route('hr.settings') }}" @class(['active' => request()->routeIs('hr.settings')])>Settings</a>
                <a href="{{ route('hr.system') }}" @class(['active' => request()->routeIs('hr.system')])>System</a>
                <a href="{{ route('hr.users.index') }}" @class(['active' => request()->routeIs('hr.users.*')])>HR users</a>
                <a href="{{ route('hr.security.two-factor') }}" @class(['active' => request()->routeIs('hr.security.*')])>Security</a>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="link-button">Log out</button>
                </form>
            </nav>
            @endunless
        @endauth
    </div>
</header>

<main id="main" class="container">
    @php($statusKeys = ['two-factor-authentication-enabled', 'two-factor-authentication-confirmed', 'two-factor-authentication-disabled', 'recovery-codes-generated', 'reset-link-requested', 'password-updated'])
    @if (session('status') && ! in_array(session('status'), $statusKeys, true))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <p><strong>Please correct the following:</strong></p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<footer class="site-footer">
    <div class="container">
        <small>Confidential. Authorized use only.</small>
    </div>
</footer>
</body>
</html>
