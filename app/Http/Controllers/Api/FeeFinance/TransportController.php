<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\StudentTransport;
use App\Models\TransportStop;
use Illuminate\Http\Request;

class TransportController extends Controller
{
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'enrollment_id' => 'nullable|exists:enrollments,id',
            'student_id' => 'nullable|exists:students,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'route_id' => 'required|exists:transport_routes,id',
            'stop_id' => 'required|exists:transport_stops,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        if (!$validated['enrollment_id'] && !$validated['student_id']) {
            return response()->json([
                'message' => 'Either enrollment_id or student_id is required'
            ], 422);
        }

        if ($validated['enrollment_id']) {
            $enrollment = Enrollment::findOrFail($validated['enrollment_id']);
            $validated['student_id'] = $enrollment->student_id;
            $validated['academic_year_id'] = $validated['academic_year_id'] ?? $enrollment->academic_year_id;
        }

        if (!$validated['academic_year_id']) {
            return response()->json([
                'message' => 'academic_year_id is required when enrollment_id is not provided'
            ], 422);
        }

        $stop = TransportStop::where('id', $validated['stop_id'])
            ->where('route_id', $validated['route_id'])
            ->first();

        if (!$stop) {
            return response()->json([
                'message' => 'Stop does not belong to the specified route'
            ], 422);
        }

        $transport = StudentTransport::updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'academic_year_id' => $validated['academic_year_id'],
            ],
            [
                'route_id' => $validated['route_id'],
                'stop_id' => $validated['stop_id'],
                'status' => $validated['status'] ?? 'active',
            ]
        );

        $action = $transport->wasRecentlyCreated ? 'create' : 'update';
        AuditLog::log($action, $transport, null, $transport->toArray(), 'Transport assigned');

        return response()->json([
            'message' => 'Transport assigned successfully',
            'data' => $transport->load(['route', 'stop', 'academicYear'])
        ]);
    }

    public function stop($id)
    {
        $transport = StudentTransport::findOrFail($id);
        $oldValues = $transport->toArray();
        $transport->update(['status' => 'inactive']);

        AuditLog::log('update', $transport, $oldValues, $transport->toArray(), 'Transport stopped');

        return response()->json([
            'message' => 'Transport stopped successfully',
            'data' => $transport->fresh()
        ]);
    }
}
