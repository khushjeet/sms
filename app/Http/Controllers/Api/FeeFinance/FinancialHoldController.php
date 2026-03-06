<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FinancialHold;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FinancialHoldController extends Controller
{
    public function index(Request $request)
    {
        $query = FinancialHold::query()->orderByDesc('created_at');

        if ($request->filled('active')) {
            $active = filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                $query->where('is_active', $active);
            }
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'reason' => 'required|string',
            'outstanding_amount' => 'nullable|numeric|min:0',
        ]);

        $hold = FinancialHold::create([
            'student_id' => $data['student_id'],
            'reason' => $data['reason'],
            'outstanding_amount' => $data['outstanding_amount'] ?? 0,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        AuditLog::log('create', $hold, null, $hold->toArray(), 'Financial hold created');

        return response()->json($hold, 201);
    }

    public function update(Request $request, $id)
    {
        $hold = FinancialHold::findOrFail($id);
        $oldValues = $hold->toArray();

        $data = $request->validate([
            'active' => 'required|boolean',
        ]);

        $hold->update([
            'is_active' => $data['active'],
        ]);

        AuditLog::log('update', $hold, $oldValues, $hold->toArray(), 'Financial hold status updated');

        return response()->json($hold);
    }
}
