<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\StudentTransportAssignment;

class TransportChargeController extends Controller
{
    public function byEnrollment($id)
    {
        $enrollment = Enrollment::with(['student'])->findOrFail($id);

        $assignment = StudentTransportAssignment::with(['route', 'stop'])
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'active')
            ->first();

        if (!$assignment) {
            return response()->json([
                'enrollment_id' => $enrollment->id,
                'has_transport' => false,
                'fee_amount' => 0,
                'route' => null,
                'stop' => null,
            ]);
        }

        return response()->json([
            'enrollment_id' => $enrollment->id,
            'has_transport' => true,
            'fee_amount' => $assignment->stop?->fee_amount ?? $assignment->route?->fee_amount ?? 0,
            'route' => $assignment->route,
            'stop' => $assignment->stop,
        ]);
    }
}
