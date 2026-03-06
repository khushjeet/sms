<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StudentDashboard\StudentDashboardBuilder;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function __construct(
        private readonly StudentDashboardBuilder $dashboardBuilder,
    ) {
    }

    public function show(Request $request)
    {
        /** @var User|null $user */
        $user = $request->user();
        if (!$user || !$user->isStudent()) {
            abort(403, 'Only student users can access this dashboard.');
        }

        if (!$user->hasPermission('student.view_dashboard')) {
            abort(403, 'You do not have permission to view student dashboard.');
        }

        $requestedYearId = $request->filled('academic_year_id') ? (int) $request->input('academic_year_id') : null;
        $month = $this->dashboardBuilder->normalizeMonth((string) ($request->input('month') ?? now()->format('Y-m')));

        return response()->json($this->dashboardBuilder->build($user, $requestedYearId, $month));
    }
}
