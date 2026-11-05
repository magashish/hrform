<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Onboarding') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body class="portal">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="container header-inner">
        <span class="brand">{{ config('app.name') }}</span>
        @if (app(\App\Services\Applicants\CurrentApplicant::class)->check())
            <nav class="nav" aria-label="Applicant navigation">
                <a href="{{ route('portal.home') }}">My documents</a>
                <form method="POST" action="{{ route('portal.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="link-button">Save &amp; sign out</button>
                </form>
            </nav>
        @endif
    </div>
</header>
<main id="main" class="container container-narrow">
    @if (session('portal_notice'))
        <div class="alert alert-success" role="status">{{ session('portal_notice') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <p><strong>Please check the following:</strong></p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
<footer class="site-footer">
    <div class="container"><small>Your information is transmitted securely and only shared with the HR team. Need help? Contact HR.</small></div>
</footer>
</body>
</html>
