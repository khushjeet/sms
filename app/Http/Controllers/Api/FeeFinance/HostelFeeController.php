<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\OptionalService;
use Illuminate\Http\Request;

class HostelFeeController extends Controller
{
    public function index(Request $request)
    {
        $academicYearId = $request->academic_year_id;

        if (!$academicYearId) {
            $academicYearId = AcademicYear::current()->value('id');
        }

        $query = OptionalService::where('name', 'Hostel');

        if ($academicYearId) {
            $query->where('academic_year_id', $academicYearId);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'amount' => 'required|numeric|min:0',
            'frequency' => 'required|in:monthly,quarterly,annually',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $service = OptionalService::create([
            'academic_year_id' => $data['academic_year_id'],
            'name' => 'Hostel',
            'amount' => $data['amount'],
            'frequency' => $data['frequency'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        AuditLog::log('create', $service, null, $service->toArray(), 'Hostel fee created');

        return response()->json($service, 201);
    }

    public function update(Request $request, $id)
    {
        $service = OptionalService::where('name', 'Hostel')->findOrFail($id);
        $oldValues = $service->toArray();

        $data = $request->validate([
            'amount' => 'sometimes|numeric|min:0',
            'frequency' => 'sometimes|in:monthly,quarterly,annually',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $service->update($data);

        AuditLog::log('update', $service, $oldValues, $service->toArray(), 'Hostel fee updated');

        return response()->json($service);
    }
}
