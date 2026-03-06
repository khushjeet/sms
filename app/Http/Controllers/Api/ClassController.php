<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClassController extends Controller
{
    /**
     * Display a listing of classes
     */
    public function index(Request $request)
    {
        $query = ClassModel::with('sections');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Order by numeric_order for proper class sequence
        $classes = $query->orderBy('numeric_order', 'asc')->paginate($request->per_page ?? 15);

        return response()->json($classes);
    }

    /**
     * Store a newly created class
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:classes,name',
            'numeric_order' => 'required|integer|min:1|unique:classes,numeric_order',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $class = ClassModel::create([
            'name' => $validated['name'],
            'numeric_order' => $validated['numeric_order'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'message' => 'Class created successfully',
            'data' => $class->load('sections')
        ], 201);
    }

    /**
     * Display the specified class
     */
    public function show($id)
    {
        $class = ClassModel::with(['sections', 'feeStructures', 'subjects'])->findOrFail($id);

        return response()->json($class);
    }

    /**
     * Update the specified class
     */
    public function update(Request $request, $id)
    {
        $class = ClassModel::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('classes')->ignore($id)],
            'numeric_order' => ['sometimes', 'integer', 'min:1', Rule::unique('classes')->ignore($id)],
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $class->update($validated);

        return response()->json([
            'message' => 'Class updated successfully',
            'data' => $class->fresh()->load('sections')
        ]);
    }

    /**
     * Remove the specified class (soft delete)
     */
    public function destroy($id)
    {
        $class = ClassModel::findOrFail($id);

        // Check if class has sections
        if ($class->sections()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete class with existing sections. Deactivate it instead.',
            ], 422);
        }

        $class->delete();

        return response()->json([
            'message' => 'Class deleted successfully'
        ]);
    }
}
