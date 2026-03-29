<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $certificate['headline'] }}</title>
    @php
        $isWinner = ($certificate['type'] ?? 'participant') === 'winner';
        $headlineText = $isWinner ? 'CERTIFICATE OF ACHIEVEMENT' : 'CERTIFICATE OF PARTICIPATION';
        $titleText = $isWinner ? 'WINNER AWARD' : 'PARTICIPANT HONOR';
        $textLoad = strlen((string) ($certificate['student_name'] ?? ''))
            + strlen((string) ($certificate['title'] ?? ''))
            + strlen((string) ($certificate['achievement_title'] ?? ''))
            + strlen((string) ($certificate['venue'] ?? ''));
        $compactClass = $textLoad > 115 ? 'compact' : '';
        $compactClass = $textLoad > 170 ? 'compact ultra-compact' : $compactClass;
    @endphp
    <style>
        @page { size: 297mm 210mm; margin: 0; }
        html, body {
            margin: 0;
            padding: 0;
            width: 297mm;
            height: 210mm;
            overflow: hidden;
            font-family: DejaVu Sans, sans-serif;
            color: #2f2a1f;
            background: #f8ecd0;
        }
        body {
            background: #f8ecd0;
        }
        .page {
            position: relative;
            width: 297mm;
            height: 210mm;
            overflow: hidden;
            background: #f8ecd0;
        }
        .page::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='120' height='120' filter='url(%23n)' opacity='0.08'/%3E%3C/svg%3E") repeat,
                #f8ecd0;
            opacity: 0.2;
        }
        .frame-outer {
            position: absolute;
            inset: 22px;
            border: 4px solid #9b6f09;
        }
        .frame-middle {
            position: absolute;
            inset: 36px;
            border: 2px solid #d1a22a;
        }
        .frame-inner {
            position: absolute;
            inset: 50px;
            border: 1px solid #b98b1f;
            padding: 22px 42px 18px;
            box-sizing: border-box;
        }
        .corner {
            position: absolute;
            width: 58px;
            height: 58px;
            z-index: 3;
        }
        .corner::before,
        .corner::after {
            content: "";
            position: absolute;
            background: #b88917;
        }
        .corner.tl { top: 52px; left: 52px; }
        .corner.tr { top: 52px; right: 52px; }
        .corner.bl { bottom: 52px; left: 52px; }
        .corner.br { bottom: 52px; right: 52px; }
        .corner.tl::before,
        .corner.bl::before {
            left: 0;
            width: 5px;
            height: 58px;
        }
        .corner.tr::before,
        .corner.br::before {
            right: 0;
            width: 5px;
            height: 58px;
        }
        .corner.tl::after,
        .corner.tr::after {
            top: 0;
            width: 58px;
            height: 5px;
        }
        .corner.bl::after,
        .corner.br::after {
            bottom: 0;
            width: 58px;
            height: 5px;
        }
        .watermark {
            position: absolute;
            left: 50%;
            top: 104px;
            width: 250px;
            height: 250px;
            margin-left: -125px;
            opacity: 0.09;
            text-align: center;
        }
        .watermark img {
            width: 250px;
            height: 250px;
            object-fit: contain;
        }
        .content {
            position: relative;
            z-index: 2;
            text-align: center;
        }
        .brand {
            position: relative;
            min-height: 72px;
            margin-bottom: 6px;
        }
        .brand-logo {
            position: absolute;
            left: 6px;
            top: 6px;
            width: 66px;
            height: 66px;
            text-align: left;
        }
        .brand-logo img {
            width: 66px;
            height: 66px;
            object-fit: contain;
        }
        .school-name {
            font-family: DejaVu Serif, serif;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #8a6212;
            text-transform: uppercase;
            margin-top: 6px;
        }
        .school-meta {
            font-size: 11px;
            color: #78674a;
            margin-top: 2px;
        }
        .headline {
            margin-top: 12px;
            font-size: 12px;
            letter-spacing: 4.5px;
            color: #b07d17;
            font-weight: 700;
            text-transform: uppercase;
        }
        .certificate-type {
            margin-top: 6px;
            font-family: DejaVu Serif, serif;
            font-size: 31px;
            line-height: 1.05;
            font-weight: 700;
            color: #a07112;
            text-transform: uppercase;
        }
        .intro {
            margin-top: 18px;
            font-size: 14px;
            color: #72624a;
        }
        .student-name {
            margin-top: 10px;
            font-family: DejaVu Serif, serif;
            font-size: 31px;
            line-height: 1.05;
            font-weight: 700;
            color: #2f2a1f;
            text-transform: uppercase;
        }
        .description {
            width: 88%;
            margin: 18px auto 0;
            font-size: 14px;
            line-height: 1.45;
            color: #5b4d37;
        }
        .description strong {
            color: #3e3629;
        }
        .event-pill {
            display: inline-block;
            margin-top: 16px;
            padding: 7px 14px;
            border: 2px solid #c3911c;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            color: #855d12;
            background: #f8eed4;
        }
        .rank-badge {
            width: 116px;
            margin: 16px auto 0;
            padding: 24px 0 18px;
            border: 3px solid #c3911c;
            border-radius: 60px;
            background: #f2d37d;
            color: #6a4710;
        }
        .rank-badge .small {
            font-size: 10px;
            letter-spacing: 1px;
            text-transform: uppercase;
            font-weight: 700;
        }
        .rank-badge .big {
            margin-top: 6px;
            font-family: DejaVu Serif, serif;
            font-size: 24px;
            font-weight: 700;
        }
        .meta-grid {
            margin-top: 18px;
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .meta-item {
            display: table-cell;
            text-align: center;
            vertical-align: top;
        }
        .meta-label {
            font-size: 10px;
            letter-spacing: 2.5px;
            color: #8b7b5f;
            text-transform: uppercase;
        }
        .meta-value {
            margin-top: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #3b3428;
        }
        .certificate-number {
            margin-top: 10px;
            font-size: 11px;
            color: #8c7b5b;
            text-align: center;
        }
        .signatures {
            margin-top: 34px;
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        .signature-box {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: bottom;
            padding: 0 24px;
        }
        .signature-holder {
            height: 48px;
            text-align: center;
        }
        .signature-box img {
            display: block;
            width: 136px;
            max-height: 40px;
            margin: 0 auto;
            object-fit: contain;
        }
        .signature-line {
            margin-top: 8px;
            border-top: 1px solid #6d604c;
            padding-top: 5px;
            font-size: 11px;
            font-weight: 700;
            color: #3f382d;
        }
        .page.compact .description {
            width: 92%;
            margin-top: 14px;
            font-size: 13px;
            line-height: 1.35;
        }
        .page.compact .intro {
            margin-top: 14px;
        }
        .page.compact .meta-grid {
            margin-top: 16px;
        }
        .page.compact .signatures {
            margin-top: 24px;
        }
        .page.ultra-compact .certificate-type {
            font-size: 28px;
        }
        .page.ultra-compact .student-name {
            font-size: 27px;
        }
        .page.ultra-compact .description {
            width: 94%;
            font-size: 12px;
            line-height: 1.3;
        }
        .page.ultra-compact .meta-value {
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="page {{ $compactClass }}">
        <div class="frame-outer"></div>
        <div class="frame-middle"></div>
        <div class="frame-inner">
            <div class="content">
                <div class="brand">
                    <div class="brand-logo">
                        @if (!empty($school['logo']))
                            <img src="{{ $school['logo'] }}" alt="School logo" />
                        @endif
                    </div>
                    <div class="school-name">{{ $school['name'] }}</div>
                    <div class="school-meta">
                        {{ $school['address'] ?? '' }}
                        @if (!empty($school['phone'])) | {{ $school['phone'] }} @endif
                        @if (!empty($school['website'])) | {{ $school['website'] }} @endif
                    </div>
                </div>

                <div class="headline">{{ $headlineText }}</div>
                <div class="certificate-type">{{ $titleText }}</div>

                <div class="intro">This certificate is proudly presented to</div>
                <div class="student-name">{{ $certificate['student_name'] }}</div>

                <div class="description">
                    {{ $certificate['description'] }}
                    <strong>{{ $certificate['title'] }}</strong>
                    @if (!empty($certificate['achievement_title']))
                        for <strong>{{ $certificate['achievement_title'] }}</strong>
                    @endif
                    @if (!empty($certificate['event_date']))
                        held on <strong>{{ $certificate['event_date'] }}</strong>
                    @endif
                    @if (!empty($certificate['venue']))
                        at <strong>{{ $certificate['venue'] }}</strong>
                    @endif
                </div>

                @if ($isWinner && !empty($certificate['rank_label']))
                    <div class="rank-badge">
                        <div class="small">Awarded</div>
                        <div class="big">{{ $certificate['rank_label'] }}</div>
                    </div>
                @else
                    <div class="event-pill">Celebrating contribution, spirit, and excellence</div>
                @endif

                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Admission No</div>
                        <div class="meta-value">{{ $certificate['admission_number'] ?: 'N/A' }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Class / Section</div>
                        <div class="meta-value">{{ $certificate['class_section'] ?: 'N/A' }}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Generated On</div>
                        <div class="meta-value">{{ $certificate['generated_on'] }}</div>
                    </div>
                </div>

                <div class="certificate-number">Certificate No: {{ $certificate['certificate_number'] }}</div>

                <div class="signatures">
                    <div class="signature-box">
                        <div class="signature-holder">
                            @if (!empty($school['director_signature']))
                                <img src="{{ $school['director_signature'] }}" alt="Director signature" />
                            @endif
                        </div>
                        <div class="signature-line">Director</div>
                    </div>
                    <div class="signature-box">
                        <div class="signature-holder">
                            @if (!empty($school['principal_signature']))
                                <img src="{{ $school['principal_signature'] }}" alt="Principal signature" />
                            @endif
                        </div>
                        <div class="signature-line">Principal</div>
                    </div>
                </div>
            </div>

            @if (!empty($school['watermark_logo']))
                <div class="watermark"><img src="{{ $school['watermark_logo'] }}" alt="Watermark" /></div>
            @endif
        </div>
        <div class="corner tl"></div>
        <div class="corner tr"></div>
        <div class="corner bl"></div>
        <div class="corner br"></div>
    </div>
</body>
</html>
