<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function edit(): View
    {
        return view('hr.settings', [
            'definitions' => collect($this->settings->definitions())->groupBy('group', preserveKeys: true),
            'values' => $this->settings->all(),
            'availableEvents' => config('portal.notifications.available_hr_events'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['hr_notification_events'] = array_values((array) $request->input('hr_notification_events', []));

        $this->settings->update($input, $request->user());

        return back()->with('status', 'Settings saved.');
    }
}
