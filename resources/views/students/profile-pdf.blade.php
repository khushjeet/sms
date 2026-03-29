<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Student Details</title>
    <style>
        @page { size: A4 portrait; margin: 7mm; }
        * { box-sizing: border-box; font-weight: 700 !important; }
        body { margin: 0; font-family: DejaVu Sans, Arial, sans-serif; color: #1f2937; font-size: 11px; font-weight: 700; }
        .page { position: relative; min-height: 100%; background: #f3ecec; }
        .watermark {
            position: fixed;
            top: 34%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0.08;
            z-index: 0;
            text-align: center;
            width: 100%;
        }
        .watermark img { width: 280px; height: 280px; object-fit: contain; }
        .watermark-text { font-size: 64px; color: #94a3b8; transform: rotate(-28deg); display: inline-block; }
        .card {
            position: relative;
            z-index: 1;
            background: #ececec;
            border: 1px solid #2c2c2c;
            border-radius: 12px;
            padding: 12px;
        }
        .header { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: middle; }
        .photo-box, .logo-box {
            width: 76px;
            height: 86px;
            border: 1px solid #c6c6c6;
            background: #ffffff;
            text-align: center;
        }
        .photo-box img, .logo-box img { width: 72px; height: 82px; object-fit: cover; margin: 1px; }
        .logo-box img { object-fit: contain; }
        .empty { color: #94a3b8; font-size: 10px; line-height: 86px; }
        .school-center { text-align: center; padding: 0 10px; }
        .school-name { margin: 0; color: #143d44; font-size: 20px; font-weight: 700; text-transform: uppercase; }
        .school-sub { margin: 2px 0 0; font-size: 11px; }
        .school-contact { margin: 2px 0 0; font-size: 10px; font-weight: 700; }
        .divider { border-top: 2px solid #2c2c2c; margin: 8px 0; }
        .identity { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .identity td { width: 25%; vertical-align: top; padding-right: 10px; }
        .label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #374151; }
        .value { margin-top: 2px; font-size: 11px; font-weight: 700; }
        .value.name { color: #143d44; font-size: 14px; }
        .main { width: 100%; border-collapse: collapse; }
        .left-col { width: 135px; vertical-align: top; padding-right: 12px; }
        .right-col { vertical-align: top; }
        .mini-block { margin-bottom: 10px; }
        .mini-title { font-size: 11px; font-weight: 700; }
        .section-title { margin: 0 0 5px; color: #143d44; font-size: 14px; font-weight: 700; }
        .soft-divider { border-top: 1px solid #b0b0b0; margin: 7px 0 8px; }
        .triple { width: 100%; border-collapse: collapse; }
        .triple td { width: 33.33%; vertical-align: top; padding-right: 10px; }
        .dual { width: 100%; border-collapse: collapse; }
        .dual td { width: 50%; vertical-align: top; padding-right: 10px; }
        .field-title { font-size: 11px; font-weight: 700; margin-bottom: 3px; }
        .field-line { margin: 0 0 3px; word-break: break-word; }
        .muted { color: #6b7280; }
        .bank-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .bank-table th, .bank-table td {
            border: 1px solid #bfc5cc;
            padding: 5px 6px;
            text-align: left;
            vertical-align: top;
            font-size: 10px;
        }
        .bank-table th { width: 150px; background: #eceff1; }
        .signatures {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .signatures td {
            width: 50%;
            vertical-align: bottom;
            text-align: center;
            padding: 0 14px;
        }
        .signature-line {
            border-top: 1px solid #111827;
            height: 1px;
            margin: 0 auto 6px;
            width: 180px;
        }
        .signature-image {
            width: 130px;
            height: 42px;
            object-fit: contain;
            display: block;
            margin: 0 auto 5px;
        }
        .signature-label {
            font-size: 10px;
            color: #111827;
        }
        .generated { margin-top: 8px; text-align: right; color: #6b7280; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $watermark = strtoupper((string) ($school['watermark_text'] ?? $school['name'] ?? 'SCHOOL'));
        $schoolLogo = $school['logo_data_url'] ?? ($school['logo'] ?? null);
        $watermarkLogo = $school['watermark_logo_data_url'] ?? ($school['watermark_logo'] ?? $schoolLogo);
    @endphp

    <div class="watermark">
        @if (!empty($watermarkLogo))
            <img src="{{ $watermarkLogo }}" alt="School logo watermark" />
        @else
            <span class="watermark-text">{{ $watermark }}</span>
        @endif
    </div>

    <div class="page">
        <div class="card">
            <table class="header">
                <tr>
                    <td style="width: 80px;">
                        <div class="photo-box">
                            @if (!empty($pdf['photo']))
                                <img src="{{ $pdf['photo'] }}" alt="Student photo" />
                            @else
                                <span class="empty">No Photo</span>
                            @endif
                        </div>
                    </td>
                    <td class="school-center">
                        <p class="school-name">{{ $school['name'] ?? 'School' }}</p>
                        @if (!empty($school['address']))
                            <p class="school-sub">{{ $school['address'] }}</p>
                        @endif
                        @if (!empty($school['udise']))
                            <p class="school-sub">UDISE: {{ $school['udise'] }}</p>
                        @endif
                        @if (!empty($school['reg_no']))
                            <p class="school-sub">Reg No: {{ $school['reg_no'] }}</p>
                        @endif
                        <p class="school-contact">
                            @if (!empty($school['phone']))Mob. {{ $school['phone'] }}@endif
                            @if (!empty($school['phone']) && !empty($school['website'])) | @endif
                            @if (!empty($school['website'])){{ $school['website'] }}@endif
                        </p>
                    </td>
                    <td style="width: 80px;">
                        <div class="logo-box">
                            @if (!empty($schoolLogo))
                                <img src="{{ $schoolLogo }}" alt="School logo" />
                            @else
                                <span class="empty">Logo</span>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>

            <div class="divider"></div>

            <table class="identity">
                <tr>
                    <td>
                        <div class="label">Name</div>
                        <div class="value name">{{ $pdf['student_name'] }}</div>
                    </td>
                    <td>
                        <div class="label">DOB</div>
                        <div class="value">{{ $pdf['dob'] }}</div>
                    </td>
                    <td>
                        <div class="label">Gender</div>
                        <div class="value">{{ $pdf['gender'] }}</div>
                    </td>
                    <td>
                        <div class="label">Blood Group</div>
                        <div class="value">{{ $pdf['blood_group'] }}</div>
                    </td>
                </tr>
            </table>

            <div class="soft-divider"></div>

            <table class="main">
                <tr>
                    <td class="left-col">
                        <div class="mini-block">
                            <div class="mini-title">Admission #</div>
                            <div class="value">{{ $pdf['admission_number'] }}</div>
                        </div>
                        <div class="mini-block">
                            <div class="mini-title">Class</div>
                            <div class="value">{{ $pdf['class_name'] }}</div>
                        </div>
                        <div class="mini-block">
                            <div class="mini-title">Roll No</div>
                            <div class="value">{{ $pdf['roll_number'] }}</div>
                        </div>
                    </td>
                    <td class="right-col">
                        <p class="section-title">Parent / Guardian</p>
                        <table class="triple">
                            <tr>
                                <td>
                                    <div class="field-title">Father</div>
                                    <p class="field-line">{{ $pdf['father_name'] }}</p>
                                    <p class="field-line muted">{{ $pdf['father_phone'] }}</p>
                                    <p class="field-line muted">{{ $pdf['father_email'] }}</p>
                                </td>
                                <td>
                                    <div class="field-title">Mother</div>
                                    <p class="field-line">{{ $pdf['mother_name'] }}</p>
                                    <p class="field-line muted">{{ $pdf['mother_phone'] }}</p>
                                    <p class="field-line muted">{{ $pdf['mother_email'] }}</p>
                                </td>
                                <td>
                                    <div class="field-title">Father Occupation</div>
                                    <p class="field-line">{{ $pdf['father_occupation'] }}</p>
                                    <div class="field-title">Mother Occupation</div>
                                    <p class="field-line">{{ $pdf['mother_occupation'] }}</p>
                                </td>
                            </tr>
                        </table>

                        <div class="soft-divider"></div>

                        <p class="section-title">Address</p>
                        <table class="dual">
                            <tr>
                                <td>
                                    <div class="field-title">Permanent</div>
                                    <p class="field-line">{{ $pdf['permanent_address'] }}</p>
                                </td>
                                <td>
                                    <div class="field-title">Current</div>
                                    <p class="field-line">{{ $pdf['current_address'] }}</p>
                                </td>
                            </tr>
                        </table>

                        <div class="soft-divider"></div>

                        <p class="section-title">Bank Details</p>
                        <table class="bank-table">
                            <tr>
                                <th>Bank Account Number</th>
                                <td>{{ $pdf['account_number'] }}</td>
                            </tr>
                            <tr>
                                <th>Bank Account Holder Name</th>
                                <td>{{ $pdf['account_holder'] }}</td>
                            </tr>
                            <tr>
                                <th>IFSC Code</th>
                                <td>{{ $pdf['ifsc'] }}</td>
                            </tr>
                            <tr>
                                <th>Relation With Account Holder</th>
                                <td>{{ $pdf['relation_with_account_holder'] }}</td>
                            </tr>
                        </table>

                        <table class="signatures">
                            <tr>
                                <td>
                                    @if (!empty($pdf['principal_signature']))
                                        <img src="{{ $pdf['principal_signature'] }}" alt="Principal signature" class="signature-image" />
                                    @else
                                        <div class="signature-line"></div>
                                    @endif
                                    <div class="signature-label">Principal Signature</div>
                                </td>
                                <td>
                                    @if (!empty($pdf['director_signature']))
                                        <img src="{{ $pdf['director_signature'] }}" alt="Director signature" class="signature-image" />
                                    @else
                                        <div class="signature-line"></div>
                                    @endif
                                    <div class="signature-label">Director Signature</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>

            <div class="generated">Generated on {{ $generated_on }}</div>
        </div>
    </div>
</body>
</html>
