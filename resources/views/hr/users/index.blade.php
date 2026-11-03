@extends('layouts.app')

@section('title', 'HR users')

@section('content')
    <div class="page-header">
        <h1>HR users</h1>
        @can('create', App\Models\User::class)
            <a class="button" href="{{ route('hr.users.create') }}">Add HR user</a>
        @endcan
    </div>

    <p class="muted">All HR users have the same permissions. Disabled users cannot sign in.</p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">2FA</th>
                <th scope="col">Last sign-in</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->hasConfirmedTwoFactor() ? 'Enabled' : 'Not enrolled' }}</td>
                    <td>{{ $user->last_login_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i') ?? 'Never' }}</td>
                    <td>
                        <span @class(['badge', 'badge-muted' => $user->isDisabled(), 'badge-ok' => ! $user->isDisabled()])>
                            {{ $user->isDisabled() ? 'Disabled' : 'Active' }}
                        </span>
                    </td>
                    <td class="right">
                        @can('disable', $user)
                            <form method="POST" action="{{ route('hr.users.disable', $user) }}" class="inline">
                                @csrf
                                <button type="submit" class="button button-small button-danger">Disable</button>
                            </form>
                        @endcan
                        @can('enable', $user)
                            <form method="POST" action="{{ route('hr.users.enable', $user) }}" class="inline">
                                @csrf
                                <button type="submit" class="button button-small">Enable</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{ $users->links('pagination.simple') }}
@endsection
