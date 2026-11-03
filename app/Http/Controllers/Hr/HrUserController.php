<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreHrUserRequest;
use App\Models\User;
use App\Services\Hr\HrUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HrUserController extends Controller
{
    public function __construct(private readonly HrUserService $users) {}

    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('hr.users.index', [
            'users' => User::query()->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('hr.users.create');
    }

    public function store(StoreHrUserRequest $request): RedirectResponse
    {
        $this->users->create(
            $request->validated('name'),
            $request->validated('email'),
            createdBy: $request->user(),
        );

        return redirect()->route('hr.users.index')
            ->with('status', 'HR user created. They have been emailed a link to set their password.');
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('disable', $user);

        $this->users->disable($user, $request->user());

        return back()->with('status', 'HR user disabled.');
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('enable', $user);

        $this->users->enable($user, $request->user());

        return back()->with('status', 'HR user re-enabled.');
    }
}
