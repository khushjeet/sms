<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1f2937;
            font-size: 11px;
            margin: 14px;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        .header {
            margin-bottom: 12px;
        }

        .header h1 {
            font-size: 18px;
            margin-bottom: 4px;
        }

        .header p {
            color: #4b5563;
            margin-bottom: 4px;
        }

        .meta {
            margin: 8px 0 12px;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #f9fafb;
        }

        .meta-row {
            margin-bottom: 3px;
        }

        .meta-row:last-child {
            margin-bottom: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 5px 6px;
            vertical-align: top;
        }

        th {
            background: #eff6ff;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .slot-col {
            width: 13%;
            background: #f8fafc;
        }

        .slot-title {
            font-weight: bold;
            margin-bottom: 2px;
        }

        .slot-time {
            color: #4b5563;
            font-size: 10px;
        }

        .cell-title {
            font-weight: bold;
            margin-bottom: 2px;
        }

        .empty {
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        @if (!empty($subtitle))
            <p>{{ $subtitle }}</p>
        @endif
        <p>Generated on {{ $generated_on }}</p>
    </div>

    <div class="meta">
        @if (!empty($payload['meta']['student_name']))
            <div class="meta-row"><strong>Student:</strong> {{ $payload['meta']['student_name'] }}</div>
        @endif
        @if (!empty($payload['meta']['teacher_name']))
            <div class="meta-row"><strong>Teacher:</strong> {{ $payload['meta']['teacher_name'] }}</div>
        @endif
        @if (!empty($payload['meta']['class_name']) || !empty($payload['meta']['section_name']))
            <div class="meta-row">
                <strong>Class / Section:</strong>
                {{ $payload['meta']['class_name'] ?? '-' }} / {{ $payload['meta']['section_name'] ?? '-' }}
            </div>
        @endif
        @if (!empty($payload['meta']['academic_year_name']))
            <div class="meta-row"><strong>Academic Year:</strong> {{ $payload['meta']['academic_year_name'] }}</div>
        @endif
        @if (!empty($payload['slots']))
            <div class="meta-row">
                <strong>Time Slots:</strong>
                {{ collect($payload['slots'])->map(fn ($slot) => ($slot['name'] ?? 'Slot') . ' (' . ($slot['time_range'] ?? '-') . ')')->implode(' | ') }}
            </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th class="slot-col">Day</th>
                @foreach (($payload['slots'] ?? []) as $slot)
                    <th>
                        <div class="slot-title">{{ $slot['name'] ?? 'Slot' }}</div>
                        <div class="slot-time">{{ $slot['time_range'] ?? '-' }}</div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse (($payload['days'] ?? []) as $day)
                <tr>
                    <td class="slot-col">
                        <div class="slot-title">{{ $day['label'] ?? 'Day' }}</div>
                    </td>
                    @foreach (($payload['matrix'] ?? []) as $row)
                        @php($cell = $row['days'][$day['value']] ?? null)
                        <td>
                            @if ($cell)
                                <div class="cell-title">{{ $cell['subject_name'] ?? ($cell['is_break'] ? 'Break' : '-') }}</div>
                                @if (!empty($cell['teacher_name']))
                                    <div>Teacher: {{ $cell['teacher_name'] }}</div>
                                @endif
                            @else
                                <div class="empty">-</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($payload['slots'] ?? []) + 1 }}" class="empty">No timetable data available.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
