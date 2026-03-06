<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\OptionalService;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class OptionalServiceController extends Controller
{
    public function index(Request $request)
    {
        $academicYearId = $request->academic_year_id;

        if (!$academicYearId) {
            $academicYearId = AcademicYear::current()->value('id');
        }

        $query = OptionalService::query();

        if ($academicYearId) {
            $query->where('academic_year_id', $academicYearId);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'frequency' => 'required|in:monthly,quarterly,annually',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $service = OptionalService::create($data);

        AuditLog::log('create', $service, null, $service->toArray(), 'Optional service created');

        return response()->json($service, 201);
    }

    public function update(Request $request, $id)
    {
        $service = OptionalService::findOrFail($id);
        $oldValues = $service->toArray();

        $data = $request->validate([
            'name' => 'sometimes|string',
            'amount' => 'sometimes|numeric|min:0',
            'frequency' => 'sometimes|in:monthly,quarterly,annually',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $service->update($data);

        AuditLog::log('update', $service, $oldValues, $service->toArray(), 'Optional service updated');

        return response()->json($service);
    }
}
