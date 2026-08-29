<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboardService;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(AdminDashboardService $dashboard): View
    {
        return view('admin.dashboard', $dashboard->data());
    }
}
