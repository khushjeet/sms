<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\ClassModel;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SectionController extends Controller
{
    /**
     * Display a listing of sections
     */
    public function index(Request $request)
    {
        $query = Section::with(['class', 'academicYear', 'classTeacher']);

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('room_number', 'like', $like)
                    ->orWhereHas('class', function ($cq) use ($like) {
                        $cq->where('name', 'like', $like);
                    });
            });
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', (int) $request->class_id);
        }

        if ($request->filled('academic_year_id')) {
            $query->where('academic_year_id', (int) $request->academic_year_id);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 200));

        $sections = $query->orderBy('name', 'asc')->paginate($perPage);

        return response()->json($sections);
    }

    /**
     * Store a newly created section
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'class_teacher_id' => 'nullable|exists:users,id',
            'room_number' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);

        // Check unique constraint: class_id + academic_year_id + name
        $exists = Section::where('class_id', $validated['class_id'])
            ->where('academic_year_id', $validated['academic_year_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Section with this name already exists for this class and academic year'
            ], 422);
        }

        $section = Section::create([
            'class_id' => $validated['class_id'],
            'academic_year_id' => $validated['academic_year_id'],
            'name' => $validated['name'],
            'capacity' => $validated['capacity'] ?? 40,
            'class_teacher_id' => $validated['class_teacher_id'] ?? null,
            'room_number' => $validated['room_number'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        return response()->json([
            'message' => 'Section created successfully',
            'data' => $section->load(['class', 'academicYear', 'classTeacher'])
        ], 201);
    }

    /**
     * Display the specified section
     */
    public function show($id)
    {
        $section = Section::with([
            'class',
            'academicYear',
            'classTeacher',
            'enrollments.student.user'
        ])->findOrFail($id);

        return response()->json($section);
    }

    /**
     * Update the specified section
     */
    public function update(Request $request, $id)
    {
        $section = Section::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'capacity' => 'nullable|integer|min:1',
            'class_teacher_id' => 'nullable|exists:users,id',
            'room_number' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);

        // Check unique constraint if name is being changed
        if (isset($validated['name'])) {
            $exists = Section::where('class_id', $section->class_id)
                ->where('academic_year_id', $section->academic_year_id)
                ->where('name', $validated['name'])
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Section with this name already exists for this class and academic year'
                ], 422);
            }
        }

        $section->update($validated);

        return response()->json([
            'message' => 'Section updated successfully',
            'data' => $section->fresh()->load(['class', 'academicYear', 'classTeacher'])
        ]);
    }

    /**
     * Remove the specified section (soft delete)
     */
    public function destroy($id)
    {
        $section = Section::findOrFail($id);

        // Check if section has enrollments
        if ($section->enrollments()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete section with existing enrollments. Deactivate it instead.',
            ], 422);
        }

        $section->delete();

        return response()->json([
            'message' => 'Section deleted successfully'
        ]);
    }
}
