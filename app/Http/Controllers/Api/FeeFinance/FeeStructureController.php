<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeeStructure;
use Illuminate\Http\Request;

class FeeStructureController extends Controller
{
    public function index()
    {
        return FeeStructure::with(['class', 'academicYear'])->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'fee_type' => 'required_without:fee_head|string',
            'fee_head' => 'required_without:fee_type|string',
            'amount' => 'required|numeric|min:0',
            'frequency' => 'nullable|in:one_time,monthly,quarterly,annually',
            'description' => 'nullable|string',
            'is_mandatory' => 'boolean'
        ]);

        $fee = FeeStructure::create([
            'class_id' => $data['class_id'],
            'academic_year_id' => $data['academic_year_id'],
            'fee_type' => $data['fee_type'] ?? $data['fee_head'],
            'amount' => $data['amount'],
            'frequency' => $data['frequency'] ?? 'annually',
            'description' => $data['description'] ?? null,
            'is_mandatory' => $data['is_mandatory'] ?? true,
        ]);

        AuditLog::log('create', $fee, null, $fee->toArray(), 'Fee structure created');

        return response()->json($fee, 201);
    }

    public function show($id)
    {
        return FeeStructure::with(['class', 'academicYear'])->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $fee = FeeStructure::findOrFail($id);

        $oldValues = $fee->toArray();

        $payload = $request->only(['fee_type', 'fee_head', 'amount', 'frequency', 'is_mandatory', 'description']);
        if (isset($payload['fee_head']) && !isset($payload['fee_type'])) {
            $payload['fee_type'] = $payload['fee_head'];
        }
        unset($payload['fee_head']);

        $fee->update($payload);

        AuditLog::log('update', $fee, $oldValues, $fee->toArray(), 'Fee structure updated');

        return response()->json($fee);
    }
}
