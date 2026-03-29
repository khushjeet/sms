<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SchoolDetailsController extends Controller
{
    public function show()
    {
        return response()->json($this->payload());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'udise_code' => ['nullable', 'string', 'max:255'],
            'watermark_text' => ['nullable', 'string', 'max:255'],
            'watermark_logo_url' => ['nullable', 'string', 'max:1000'],
            'logo_url' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $newLogoPath = null;
        $oldLogoValue = SchoolSetting::getValue('school_logo_url');

        if ($request->hasFile('logo')) {
            $newLogoPath = $request->file('logo')->store('school/logo', 'public');
        }

        DB::transaction(function () use ($validated, $newLogoPath) {
            SchoolSetting::putValue('school_name', trim((string) $validated['name']));
            SchoolSetting::putValue('school_address', trim((string) ($validated['address'] ?? '')) ?: null);
            SchoolSetting::putValue('school_phone', trim((string) ($validated['phone'] ?? '')) ?: null);
            SchoolSetting::putValue('school_website', trim((string) ($validated['website'] ?? '')) ?: null);
            SchoolSetting::putValue('school_registration_number', trim((string) ($validated['registration_number'] ?? '')) ?: null);
            SchoolSetting::putValue('school_udise_code', trim((string) ($validated['udise_code'] ?? '')) ?: null);
            SchoolSetting::putValue('school_watermark_text', trim((string) ($validated['watermark_text'] ?? '')) ?: null);
            SchoolSetting::putValue('school_watermark_logo_url', trim((string) ($validated['watermark_logo_url'] ?? '')) ?: null);

            $logoValue = $newLogoPath
                ?? (trim((string) ($validated['logo_url'] ?? '')) ?: null);

            SchoolSetting::putValue('school_logo_url', $logoValue);
        });

        if ($newLogoPath && $oldLogoValue && $oldLogoValue !== $newLogoPath) {
            $oldStoragePath = $this->normalizeStoredLogoPath($oldLogoValue);
            if ($oldStoragePath !== null && Storage::disk('public')->exists($oldStoragePath)) {
                Storage::disk('public')->delete($oldStoragePath);
            }
        }

        return response()->json([
            'message' => 'School details updated successfully.',
            'data' => $this->payload(),
        ]);
    }

    private function payload(): array
    {
        $logoUrl = SchoolSetting::getValue('school_logo_url', config('school.logo_url'));
        $watermarkLogoUrl = SchoolSetting::getValue('school_watermark_logo_url');

        return [
            'name' => SchoolSetting::getValue('school_name', config('school.name')),
            'address' => SchoolSetting::getValue('school_address', config('school.address')),
            'phone' => SchoolSetting::getValue('school_phone', config('school.phone')),
            'website' => SchoolSetting::getValue('school_website', config('school.website')),
            'registration_number' => SchoolSetting::getValue('school_registration_number', config('school.reg_no')),
            'udise_code' => SchoolSetting::getValue('school_udise_code', config('school.udise')),
            'watermark_text' => SchoolSetting::getValue('school_watermark_text', config('school.name')),
            'watermark_logo_url' => $this->normalizeAssetUrl($watermarkLogoUrl),
            'logo_url' => $this->normalizeAssetUrl($logoUrl),
            'logo_data_url' => $this->buildImageDataUrl($logoUrl),
            'watermark_logo_data_url' => $this->buildImageDataUrl($watermarkLogoUrl),
        ];
    }

    private function normalizeAssetUrl(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^https?:|^data:/i', $normalized) === 1) {
            return $normalized;
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = preg_replace('/^public\/storage\//', '', $normalized);
        $normalized = preg_replace('/^storage\//', '', $normalized);
        $normalized = is_string($normalized) ? ltrim($normalized, '/') : '';

        return $normalized !== '' ? url('storage/' . $normalized) : null;
    }

    private function normalizeStoredLogoPath(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^https?:|^data:/i', $normalized) === 1) {
            return null;
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = preg_replace('/^public\/storage\//', '', $normalized);
        $normalized = preg_replace('/^storage\//', '', $normalized);
        $normalized = is_string($normalized) ? ltrim($normalized, '/') : '';

        return $normalized !== '' ? $normalized : null;
    }

    private function buildImageDataUrl(?string $value): ?string
    {
        $path = $this->resolveLocalImagePath($value);
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: null;
        if ($mime === null || !str_starts_with($mime, 'image/')) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    private function resolveLocalImagePath(?string $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^data:/i', $normalized) === 1) {
            return null;
        }

        if (preg_match('/^https?:/i', $normalized) === 1) {
            $parsed = parse_url($normalized);
            $host = strtolower((string) ($parsed['host'] ?? ''));
            $path = (string) ($parsed['path'] ?? '');
            if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $path === '') {
                return null;
            }
            $normalized = ltrim($path, '/');
        }

        $normalized = str_replace('\\', '/', $normalized);
        $normalized = preg_replace('/^public\//', '', $normalized);
        $normalized = is_string($normalized) ? ltrim($normalized, '/') : '';

        if ($normalized === '') {
            return null;
        }

        return public_path($normalized);
    }
}
