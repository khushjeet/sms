<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AcademicYearController extends Controller
{
    /**
     * Display a listing of academic years
     */
    public function index(Request $request)
    {
        $query = AcademicYear::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('is_current')) {
            $query->where('is_current', $request->is_current === 'true' || $request->is_current === true);
        }

        $academicYears = $query->orderBy('start_date', 'desc')->paginate($request->per_page ?? 15);

        return response()->json($academicYears);
    }

    /**
     * Store a newly created academic year
     * SRS Section 12.1: Academic Year Transition
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:academic_years,name',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'description' => 'nullable|string',
            'is_current' => 'nullable|boolean',
        ]);

        // Convert string boolean to actual boolean
        $isCurrent = filter_var($request->input('is_current'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isCurrent === null) {
            $isCurrent = false;
        }

        try {
            DB::beginTransaction();

            // If setting as current, unset all other current years
            if ($isCurrent) {
                AcademicYear::where('is_current', true)->update(['is_current' => false]);
            }

            $academicYear = AcademicYear::create([
                'name' => $validated['name'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'description' => $validated['description'] ?? null,
                'is_current' => $isCurrent,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Academic year created successfully',
                'data' => $academicYear
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create academic year',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified academic year
     */
    public function show($id)
    {
        $academicYear = AcademicYear::with(['enrollments', 'sections', 'exams'])->findOrFail($id);

        return response()->json($academicYear);
    }

    /**
     * Update the specified academic year
     */
    public function update(Request $request, $id)
    {
        $academicYear = AcademicYear::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('academic_years')->ignore($id)],
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'description' => 'nullable|string',
            'is_current' => 'nullable|boolean',
            'status' => 'sometimes|in:active,closed,archived',
        ]);

        // Convert string boolean to actual boolean if provided
        if ($request->has('is_current')) {
            $isCurrent = filter_var($request->input('is_current'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isCurrent === null) {
                $isCurrent = false;
            }
            $validated['is_current'] = $isCurrent;
        }

        try {
            DB::beginTransaction();

            // If setting as current, unset all other current years
            if (isset($validated['is_current']) && $validated['is_current']) {
                AcademicYear::where('is_current', true)
                    ->where('id', '!=', $id)
                    ->update(['is_current' => false]);
            }

            $academicYear->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Academic year updated successfully',
                'data' => $academicYear->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update academic year',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified academic year (soft delete)
     */
    public function destroy($id)
    {
        $academicYear = AcademicYear::findOrFail($id);

        // Check if academic year has enrollments
        if ($academicYear->enrollments()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete academic year with existing enrollments. Archive it instead.',
            ], 422);
        }

        $academicYear->delete();

        return response()->json([
            'message' => 'Academic year deleted successfully'
        ]);
    }

    /**
     * Set academic year as current
     * SRS Section 12.1: Academic Year Transition
     */
    public function setCurrent(Request $request, $id)
    {
        $academicYear = AcademicYear::findOrFail($id);

        try {
            DB::beginTransaction();

            // Unset all other current years
            AcademicYear::where('is_current', true)
                ->where('id', '!=', $id)
                ->update(['is_current' => false]);

            // Set this year as current
            $academicYear->update([
                'is_current' => true,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Academic year set as current successfully',
                'data' => $academicYear->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to set academic year as current',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Close academic year (end of year transition)
     * SRS Section 12.1: Academic Year Transition
     */
    public function close(Request $request, $id)
    {
        $academicYear = AcademicYear::findOrFail($id);

        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Close the academic year
            $academicYear->update([
                'status' => 'closed',
                'is_current' => false,
            ]);

            // Lock all enrollments for this year
            $academicYear->enrollments()->update(['is_locked' => true]);

            DB::commit();

            return response()->json([
                'message' => 'Academic year closed successfully',
                'data' => $academicYear->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to close academic year',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current academic year
     */
    public function current()
    {
        $academicYear = AcademicYear::where('is_current', true)->first();

        if (!$academicYear) {
            return response()->json([
                'message' => 'No current academic year set'
            ], 404);
        }

        return response()->json($academicYear->load(['enrollments', 'sections', 'exams']));
    }
}
