<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeeInstallment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeInstallmentController extends Controller
{
    public function index(Request $request)
    {
        $query = FeeInstallment::with(['feeHead', 'class', 'academicYear']);

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', $request->integer('academic_year_id'));
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        if ($request->filled('fee_head_id')) {
            $query->where('fee_head_id', $request->integer('fee_head_id'));
        }

        return $query->orderBy('due_date')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fee_head_id' => 'required|exists:fee_heads,id',
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string',
            'due_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $installment = FeeInstallment::create([
            'fee_head_id' => $data['fee_head_id'],
            'class_id' => $data['class_id'],
            'academic_year_id' => $data['academic_year_id'],
            'name' => $data['name'],
            'due_date' => $data['due_date'],
            'amount' => $data['amount'],
            'status' => $data['status'] ?? 'active',
        ]);

        AuditLog::log('create', $installment, null, $installment->toArray(), 'Fee installment created');

        return response()->json($installment, 201);
    }

    public function update(Request $request, $id)
    {
        $installment = FeeInstallment::findOrFail($id);
        $oldValues = $installment->toArray();

        $data = $request->validate([
            'name' => 'sometimes|string',
            'due_date' => 'sometimes|date',
            'amount' => 'sometimes|numeric|min:0',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $installment->update($data);

        AuditLog::log('update', $installment, $oldValues, $installment->toArray(), 'Fee installment updated');

        return response()->json($installment);
    }
}
