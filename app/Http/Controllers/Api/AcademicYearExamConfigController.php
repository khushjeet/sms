<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYearExamConfig;
use Illuminate\Http\Request;

class AcademicYearExamConfigController extends Controller
{
    public function index(Request $request)
    {
        $this->requireSuperAdmin($request);

        $activeOnlyInput = $request->query('active_only');
        $activeOnly = null;
        if ($activeOnlyInput !== null && $activeOnlyInput !== '') {
            $parsed = filter_var($activeOnlyInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed === null && !in_array($activeOnlyInput, [0, 1, '0', '1'], true)) {
                return response()->json([
                    'message' => 'The active only field must be true or false.',
                    'errors' => ['active_only' => ['The active only field must be true or false.']],
                ], 422);
            }
            $activeOnly = (bool) $parsed;
        }

        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $query = AcademicYearExamConfig::query()
            ->with('academicYear:id,name')
            ->where('academic_year_id', (int) $validated['academic_year_id'])
            ->orderBy('sequence')
            ->orderBy('id');

        if ($activeOnly === true) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $userId = $this->requireSuperAdmin($request);

        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:100'],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $academicYearId = (int) $validated['academic_year_id'];
        $name = trim((string) $validated['name']);
        $sequence = isset($validated['sequence'])
            ? (int) $validated['sequence']
            : (int) (AcademicYearExamConfig::query()
                ->where('academic_year_id', $academicYearId)
                ->max('sequence') ?? 0) + 1;

        $exists = AcademicYearExamConfig::query()
            ->where('academic_year_id', $academicYearId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Exam already exists for this academic year.',
            ], 422);
        }

        $config = AcademicYearExamConfig::query()->create([
            'academic_year_id' => $academicYearId,
            'name' => $name,
            'sequence' => $sequence,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => $userId,
        ]);

        return response()->json([
            'message' => 'Exam configuration created successfully.',
            'data' => $config->load('academicYear:id,name'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $this->requireSuperAdmin($request);

        $config = AcademicYearExamConfig::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'sequence' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            $exists = AcademicYearExamConfig::query()
                ->where('academic_year_id', (int) $config->academic_year_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('id', '!=', $config->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Exam already exists for this academic year.',
                ], 422);
            }

            $validated['name'] = $name;
        }

        $config->update($validated);

        return response()->json([
            'message' => 'Exam configuration updated successfully.',
            'data' => $config->fresh()->load('academicYear:id,name'),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireSuperAdmin($request);

        $config = AcademicYearExamConfig::query()->findOrFail($id);

        if ($config->examSessions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete exam configuration that is already used in result sessions.',
            ], 422);
        }

        $config->delete();

        return response()->json([
            'message' => 'Exam configuration deleted successfully.',
        ]);
    }

    private function requireSuperAdmin(Request $request): int
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('super_admin')) {
            abort(403, 'Super admin access required.');
        }

        return (int) $user->id;
    }
}
