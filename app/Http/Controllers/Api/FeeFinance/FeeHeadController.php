<?php

namespace App\Http\Controllers\Api\FeeFinance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FeeHead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeHeadController extends Controller
{
    public function index(Request $request)
    {
        $query = FeeHead::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return $query->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', Rule::unique('fee_heads', 'name')],
            'code' => ['nullable', 'string', Rule::unique('fee_heads', 'code')],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $feeHead = FeeHead::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        AuditLog::log('create', $feeHead, null, $feeHead->toArray(), 'Fee head created');

        return response()->json($feeHead, 201);
    }

    public function update(Request $request, $id)
    {
        $feeHead = FeeHead::findOrFail($id);
        $oldValues = $feeHead->toArray();

        $data = $request->validate([
            'name' => ['sometimes', 'string', Rule::unique('fee_heads', 'name')->ignore($feeHead->id)],
            'code' => ['nullable', 'string', Rule::unique('fee_heads', 'code')->ignore($feeHead->id)],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $feeHead->update($data);

        AuditLog::log('update', $feeHead, $oldValues, $feeHead->toArray(), 'Fee head updated');

        return response()->json($feeHead);
    }
}
