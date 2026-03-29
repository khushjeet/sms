<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DownloadAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class AuditDownloadController extends Controller
{
    public function catalog(Request $request)
    {
        $this->requireAuditAccess($request);

        $recentCounts = DownloadAuditLog::query()
            ->select('module', DB::raw('COUNT(*) as total'))
            ->groupBy('module')
            ->pluck('total', 'module');

        return response()->json([
            'modules' => [
                [
                    'module' => 'assign_marks',
                    'title' => 'Assign Marks',
                    'route' => '/admin/assign-marks',
                    'description' => 'Finalized marks sheets and compiled class-wise exports.',
                    'available_formats' => ['csv', 'pdf'],
                    'available_items' => (int) DB::table('compiled_marks')->where('is_finalized', true)->count(),
                    'recent_downloads' => (int) ($recentCounts['assign_marks'] ?? 0),
                    'reports' => [
                        ['key' => 'final_marks_sheet', 'label' => 'Final Marks Sheet', 'formats' => ['csv', 'pdf']],
                    ],
                ],
                [
                    'module' => 'published_results',
                    'title' => 'Published Results',
                    'route' => '/admin/published-results',
                    'description' => 'Published result papers and session-wise result outputs.',
                    'available_formats' => ['pdf'],
                    'available_items' => (int) DB::table('student_results')->count(),
                    'recent_downloads' => (int) ($recentCounts['published_results'] ?? 0),
                    'reports' => [
                        ['key' => 'result_paper', 'label' => 'Result Paper', 'formats' => ['pdf']],
                    ],
                ],
                [
                    'module' => 'fee_reports',
                    'title' => 'Fee Reports',
                    'route' => '/finance',
                    'description' => 'Due, collection, route-wise, and ledger-oriented financial exports.',
                    'available_formats' => ['csv', 'pdf'],
                    'available_items' => (int) (
                        DB::table('payments')->count()
                        + DB::table('student_fee_ledger')->count()
                    ),
                    'recent_downloads' => (int) ($recentCounts['fee_reports'] ?? 0),
                    'reports' => [
                        ['key' => 'due_report', 'label' => 'Due Report', 'formats' => ['csv']],
                        ['key' => 'collection_report', 'label' => 'Collection Report', 'formats' => ['csv']],
                        ['key' => 'route_wise_report', 'label' => 'Route-Wise Report', 'formats' => ['csv']],
                        ['key' => 'student_ledger', 'label' => 'Student Ledger', 'formats' => ['csv', 'pdf']],
                        ['key' => 'class_ledger', 'label' => 'Class Ledger', 'formats' => ['csv', 'pdf']],
                    ],
                ],
            ],
        ]);
    }

    public function logs(Request $request)
    {
        $this->requireAuditAccess($request);

        $query = $this->filteredLogsQuery($request)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('downloaded_at')
            ->orderByDesc('id');

        return response()->json(
            $query->paginate((int) $request->input('per_page', 25))
        );
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $this->requireAuditAccess($request);
        $rows = $this->filteredLogsQuery($request)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('downloaded_at')
            ->orderByDesc('id')
            ->get();

        $filename = 'audit_download_logs_' . now()->format('Ymd_His') . '.csv';
        $this->recordInternalDownload($user->id, 'csv', $filename, $rows->count(), $request->all(), [
            'type' => 'audit_log_export',
        ]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Downloaded At', 'Module', 'Report Key', 'Report Label', 'Format', 'Status', 'File Name', 'Checksum', 'Rows', 'User', 'Email']);

            foreach ($rows as $row) {
                $name = trim(($row->user?->first_name ?? '') . ' ' . ($row->user?->last_name ?? ''));
                fputcsv($out, [
                    $row->id,
                    optional($row->downloaded_at)->toDateTimeString(),
                    $row->module,
                    $row->report_key,
                    $row->report_label,
                    $row->format,
                    $row->status,
                    $row->file_name,
                    $row->file_checksum,
                    $row->row_count,
                    $name,
                    $row->user?->email,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function archive(Request $request)
    {
        $user = $this->requireAuditAccess($request);
        $rows = $this->filteredLogsQuery($request)
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('downloaded_at')
            ->orderByDesc('id')
            ->get();

        $summary = [
            'generated_at' => now()->toIso8601String(),
            'generated_by_user_id' => (int) $user->id,
            'filters' => $request->all(),
            'total_logs' => $rows->count(),
            'by_module' => $rows->groupBy('module')->map->count(),
            'by_format' => $rows->groupBy('format')->map->count(),
        ];

        $csvLines = [];
        $csvLines[] = ['ID', 'Downloaded At', 'Module', 'Report Key', 'Report Label', 'Format', 'Status', 'File Name', 'Checksum', 'Rows', 'User Email'];
        foreach ($rows as $row) {
            $csvLines[] = [
                $row->id,
                optional($row->downloaded_at)->toDateTimeString(),
                $row->module,
                $row->report_key,
                $row->report_label,
                $row->format,
                $row->status,
                $row->file_name,
                $row->file_checksum,
                $row->row_count,
                $row->user?->email,
            ];
        }
        $csvContent = $this->buildCsv($csvLines);

        $manifest = [
            'archive_version' => 1,
            'generated_at' => $summary['generated_at'],
            'total_logs' => $rows->count(),
            'files' => [
                [
                    'name' => 'download_logs.csv',
                    'sha256' => hash('sha256', $csvContent),
                ],
                [
                    'name' => 'summary.json',
                    'sha256' => hash('sha256', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
                ],
            ],
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'audit_logs_');
        if ($zipPath === false) {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Unable to prepare archive file.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'Unable to open archive file.');
        }

        $zip->addFromString('download_logs.csv', $csvContent);
        $zip->addFromString('summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        $zipContent = file_get_contents($zipPath);
        $filename = 'audit_download_archive_' . now()->format('Ymd_His') . '.zip';
        $this->recordInternalDownload($user->id, 'zip', $filename, $rows->count(), $request->all(), [
            'type' => 'audit_log_archive',
            'sha256' => $zipContent !== false ? hash('sha256', $zipContent) : null,
        ]);

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function store(Request $request)
    {
        $user = $this->requireAuditAccess($request);

        $validated = $request->validate([
            'module' => ['required', 'string', 'max:80'],
            'report_key' => ['required', 'string', 'max:120'],
            'report_label' => ['required', 'string', 'max:160'],
            'format' => ['required', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:40'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_checksum' => ['nullable', 'string', 'size:64'],
            'row_count' => ['nullable', 'integer', 'min:0'],
            'filters' => ['nullable', 'array'],
            'context' => ['nullable', 'array'],
        ]);

        $log = DownloadAuditLog::query()->create([
            'user_id' => (int) $user->id,
            'module' => (string) $validated['module'],
            'report_key' => (string) $validated['report_key'],
            'report_label' => (string) $validated['report_label'],
            'format' => strtolower((string) $validated['format']),
            'status' => strtolower((string) ($validated['status'] ?? 'completed')),
            'file_name' => $validated['file_name'] ?? null,
            'file_checksum' => $validated['file_checksum'] ?? null,
            'row_count' => $validated['row_count'] ?? null,
            'filters' => $validated['filters'] ?? null,
            'context' => $validated['context'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'downloaded_at' => now(),
        ]);

        AuditLog::log('download', $log, null, $log->toArray(), 'Download generated from audit center tracked module');

        return response()->json([
            'message' => 'Download audit recorded.',
            'log_id' => (int) $log->id,
        ], 201);
    }

    private function requireAuditAccess(Request $request)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole(['super_admin', 'school_admin', 'accountant'])) {
            abort(403, 'Audit download access required.');
        }

        return $user;
    }

    private function filteredLogsQuery(Request $request)
    {
        return DownloadAuditLog::query()
            ->when($request->filled('module'), fn ($query) => $query->where('module', (string) $request->input('module')))
            ->when($request->filled('report_key'), fn ($query) => $query->where('report_key', (string) $request->input('report_key')))
            ->when($request->filled('format'), fn ($query) => $query->where('format', strtolower((string) $request->input('format'))))
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', (int) $request->input('user_id')))
            ->when($request->filled('date_from'), function ($query) use ($request) {
                $query->where('downloaded_at', '>=', Carbon::parse((string) $request->input('date_from'))->startOfDay());
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                $query->where('downloaded_at', '<=', Carbon::parse((string) $request->input('date_to'))->endOfDay());
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%' . trim((string) $request->input('search')) . '%';
                $query->where(function ($sub) use ($search) {
                    $sub->where('report_label', 'like', $search)
                        ->orWhere('file_name', 'like', $search)
                        ->orWhere('module', 'like', $search)
                        ->orWhere('report_key', 'like', $search);
                });
            });
    }

    private function buildCsv(array $lines): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($lines as $line) {
            fputcsv($handle, $line);
        }
        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    private function recordInternalDownload(int $userId, string $format, string $fileName, int $rowCount, array $filters, array $context = []): void
    {
        $log = DownloadAuditLog::query()->create([
            'user_id' => $userId,
            'module' => 'audit_downloads',
            'report_key' => $format === 'zip' ? 'download_log_archive' : 'download_log_export',
            'report_label' => $format === 'zip' ? 'Download Log Archive' : 'Download Log Export',
            'format' => $format,
            'status' => 'completed',
            'file_name' => $fileName,
            'file_checksum' => $context['sha256'] ?? null,
            'row_count' => $rowCount,
            'filters' => $filters,
            'context' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'downloaded_at' => now(),
        ]);

        AuditLog::log('download', $log, null, $log->toArray(), 'Audit center export generated');
    }
}
