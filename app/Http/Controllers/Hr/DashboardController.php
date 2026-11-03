<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        // Applicant counts and filters arrive in Phase C/E.
        return view('hr.dashboard');
    }
}
