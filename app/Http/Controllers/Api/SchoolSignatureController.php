<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SchoolSignatureController extends Controller
{
    private const SIGNATURE_KEYS = [
        'principal' => 'principal_signature_path',
        'director' => 'director_signature_path',
    ];

    public function show()
    {
        return response()->json([
            'principal_signature_path' => SchoolSetting::getValue('principal_signature_path'),
            'director_signature_path' => SchoolSetting::getValue('director_signature_path'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'principal_signature' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'director_signature' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if (!$request->hasFile('principal_signature') && !$request->hasFile('director_signature')) {
            return response()->json(['message' => 'Upload at least one signature image.'], 422);
        }

        $pathsToDelete = [];

        if ($request->hasFile('principal_signature')) {
            $oldPath = SchoolSetting::getValue('principal_signature_path');
            $newPath = $request->file('principal_signature')->store('school/signatures/principal', 'public');
            DB::transaction(function () use ($newPath) {
                SchoolSetting::putValue('principal_signature_path', $newPath);
            });
            if ($oldPath && $oldPath !== $newPath) {
                $pathsToDelete[] = $oldPath;
            }
        }

        if ($request->hasFile('director_signature')) {
            $oldPath = SchoolSetting::getValue('director_signature_path');
            $newPath = $request->file('director_signature')->store('school/signatures/director', 'public');
            DB::transaction(function () use ($newPath) {
                SchoolSetting::putValue('director_signature_path', $newPath);
            });
            if ($oldPath && $oldPath !== $newPath) {
                $pathsToDelete[] = $oldPath;
            }
        }

        foreach (array_unique($pathsToDelete) as $pathToDelete) {
            Storage::disk('public')->delete($pathToDelete);
        }

        return response()->json([
            'message' => 'School signatures updated successfully.',
            'data' => [
                'principal_signature_path' => SchoolSetting::getValue('principal_signature_path'),
                'director_signature_path' => SchoolSetting::getValue('director_signature_path'),
            ],
        ]);
    }

    public function destroy(string $slot)
    {
        $key = self::SIGNATURE_KEYS[$slot] ?? null;
        if ($key === null) {
            return response()->json(['message' => 'Invalid signature slot.'], 404);
        }

        $path = SchoolSetting::getValue($key);
        if ($path) {
            Storage::disk('public')->delete($path);
        }

        SchoolSetting::putValue($key, null);

        return response()->json([
            'message' => ucfirst($slot) . ' signature deleted successfully.',
            'data' => [
                'principal_signature_path' => SchoolSetting::getValue('principal_signature_path'),
                'director_signature_path' => SchoolSetting::getValue('director_signature_path'),
            ],
        ]);
    }
}
