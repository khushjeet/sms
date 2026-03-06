@extends('layouts.app') @section('content_one')
    @php
        $schoolName = config('school.name', config('app.name', 'School'));
        $schoolAddress = config('school.address', '');
        $schoolPhone = config('school.phone', '');
        $schoolWebsite = config('school.website', '');
        $schoolLogo = config('school.logo_url', '');
        $schoolRegNo = config('school.reg_no', '');
        $schoolUdise = config('school.udise', '');
        $watermarkText = strtolower(preg_replace('/\s+/', '', (string) $schoolName));
    @endphp
    <div class="container" id="receiptContainer"
        style="width: 190mm; max-width: 100%; height: fit-content; position: relative; margin: 0 auto; box-sizing: border-box; overflow: hidden;">
        <div class="watermark-overlay"
            style="background-image: url(&quot;data:image/svg+xml;utf8,&lt;svg xmlns='http://www.w3.org/2000/svg' width='200' height='150'&gt;&lt;text x='0' y='100' fill='black' font-size='18' font-family='Arial' font-weight='bold' transform='rotate(-30 50 100)'&gt;{{ $watermarkText }}&lt;/text&gt;&lt;/svg&gt;&quot;);"></div>
        <div class="card mt-3"
            style="font-family: sans-serif; border: 1px solid #111; padding: 12px; position: relative; z-index: 1; background: transparent;">
            <div class="receipt-page" style="padding: 8px;">
                <div class="header-top"
                    style="display:flex; justify-content:space-between; align-items:flex-start; font-size:12px; margin-bottom:6px;">
                    <div class="reg-no" style="text-align:left;"> <strong>Reg No.</strong> {{ $schoolRegNo }} </div>
                    <div class="udise" style="text-align:right;"> <strong>UDISE :</strong> {{ $schoolUdise }} </div>
                </div>
                <div class="header-main"
                    style="display:flex; align-items:center; justify-content:space-between; gap: 12px;">
                    <div class="header-left"
                        style="width: 120px; display:flex; align-items:center; justify-content:flex-start;">
                        @if (!empty($schoolLogo))
                            <img src="{{ $schoolLogo }}" alt="Logo" style="width: 70px; height: 70px; object-fit:contain;">
                        @endif
                    </div>
                    <div class="header-center" style="flex:1; text-align:center; padding: 4px 8px;">
                        <h1
                            style="font-size:30px; margin:0; font-weight:800; letter-spacing:2px; text-transform:uppercase;">
                            {{ $schoolName }} </h1>
                        <div style="margin-top:6px;">
                            <div style="font-size:14px; font-weight:600;">{{ $schoolAddress }}</div>
                            <div style="font-size:13px; margin-top:3px;">
                                Mob. {{ $schoolPhone }} &nbsp;&nbsp; | &nbsp;&nbsp;
                                <a href="{{ $schoolWebsite }}" target="_blank"
                                    style="text-decoration:none; color:#007bff;">{{ $schoolWebsite }}</a>
                            </div>
                        </div>
                    </div> @php
                        $nameSlug = rawurlencode(trim($student->first_name . '-' . $student->last_name));
                        $rollSlug = rawurlencode($student->roll_number ?? 'na');
                        $ts = now()->format('YmdHis');
                        $domain = rtrim(config('app.url', url('/')), '/');
                        $publicLink = "{$domain}/fee/{$nameSlug}-{$rollSlug}-{$ts}";
                        try {
                            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::size(250)->generate($publicLink);
                            $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
                        } catch (\Throwable $e) {
                            $qrSize = 250;
                            $qrDataUri = "https://chart.googleapis.com/chart?cht=qr&chs={$qrSize}x{$qrSize}&chl=" . rawurlencode($publicLink) . '&choe=UTF-8';
                        }
                    @endphp <div class="header-right"
                        style="width:200px; display:flex; align-items:center; justify-content:flex-end;"> <a
                            href="{{ $publicLink }}" target="_blank" title="Scan to view fee details"> <img
                                src="{{ $qrDataUri }}" alt="QR Code"
                                style="width:140px; height:140px; border:1px solid #ccc; padding:6px; background:#fff; border-radius:4px;">
                        </a> </div>
                </div>
                <div style="border-top: 2px solid #111; margin-top: 10px;"></div>
            </div>
            <div class="card-body" style="padding-top: 12px;">
                <h5>Student Details:</h5>
                <div class="row mb-3" style="display:flex; flex-wrap:wrap;">
                    <div style="flex: 0 0 25%; padding:6px;"><strong>Name:</strong> {{ $student->first_name }}
                        {{ $student->last_name }}</div>
                    <div style="flex: 0 0 25%; padding:6px;"><strong>Roll Number:</strong> {{ $student->roll_number }}</div>
                    <div style="flex: 0 0 25%; padding:6px;"><strong>Class:</strong> {{ $student->class }}</div>
                    <div style="flex: 0 0 25%; padding:6px;"><strong>Section:</strong> {{ $student->section ?? 'N/A' }}
                    </div>
                </div> @php $parent_info = \App\Models\ParentInformation::where('student_id', $student->id)->first(); @endphp <div class="row mb-3" style="display:flex; flex-wrap:wrap;">
                    <div style="flex: 0 0 50%; padding:6px;"><strong>Date:</strong> {{ now()->format('d M Y') }}</div>
                    <div style="flex: 0 0 50%; padding:6px;"><strong>Admission Number:</strong>
                        {{ $student->admission_number ?? 'N/A' }}</div>
                    <div style="flex: 0 0 50%; padding:6px;"><strong>Father Name:</strong>
                        {{ $parent_info->father_name ?? 'N/A' }}</div>
                </div>
                <h5>Fee Details:</h5>
                <div style="overflow-x:auto;">
                    <table class="table table-striped"
                        style="width:100%; border-collapse:collapse; background: transparent;">
                        <thead>
                            <tr>
                                <th style="border: 1px solid #111; padding: 8px; width: 5%;">#</th>
                                <th style="border: 1px solid #111; padding: 8px; width: 55%;">Fees Type</th>
                                <th style="border: 1px solid #111; padding: 8px; width: 20%;">Month</th>
                                <th style="border: 1px solid #111; padding: 8px; width: 20%;">Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody> @php
                            $total = 0;
                            $index = 1;
                        @endphp @foreach ($fees as $fee)
                                @php
                                    $amount = isset($fee['total']) ? floatval($fee['total']) : 0;
                                    $total += $amount;
                                    $months = isset($fee['months']) && is_array($fee['months']) ? implode(', ', $fee['months']) : '-';
                                @endphp <tr>
                                    <td style="border: 1px solid #111; padding: 8px;">{{ $index++ }}</td>
                                    <td style="border: 1px solid #111; padding: 8px;">
                                        <strong>{{ $fee['fee_type'] ?? 'N/A' }}</strong></td>
                                    <td style="border: 1px solid #111; padding: 8px;">{{ $months }}</td>
                                    <td style="border: 1px solid #111; padding: 8px;">₹{{ number_format($amount, 2) }}</td>
                                </tr>
                                @endforeach </tbody>
                    </table>
                </div>
                <div style="text-align:right; margin-top: 15px;">
                    <h4 style="margin:6px 0;"><strong>Total:</strong> ₹{{ number_format($total, 2) }}</h4>
                    <h5 style="margin:6px 0;">Amount in Words: {{ ucfirst(convertNumberToWords((int) $total)) }} Rupees Only
                    </h5>
                </div>
                <div class="text-right mt-3 no-print"
                    style="text-align:right; display:flex; gap:10px; justify-content:flex-end;"> <button
                        id="printReceiptBtn" class="btn btn-primary" style="padding:10px 25px; font-weight:bold;"> Print
                        Receipt </button> <button id="downloadPdfBtn" class="btn btn-success"
                        style="padding:10px 25px; font-weight:bold;"> Save As PDF </button> </div>
            </div>
        </div>
    </div>
    <style>
        /* WATERMARK FOR SCREEN AND PRINT */
        .watermark-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
            opacity: 0.1;
            background-repeat: repeat;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* PRINT STYLES (CTR+P / window.print) */
        @media print {
            @page {
                size: auto;
                margin: 5mm;
            }

            body * {
                visibility: hidden;
            }

            #receiptContainer,
            #receiptContainer * {
                visibility: visible;
            }

            #receiptContainer {
                position: absolute;
                left: 0;
                top: 0;
                width: 100% !important;
                margin: 0;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .watermark-overlay {
                display: block !important;
            }
        }
    </style> @php
        function convertNumberToWords($number)
        {
            $words = [0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve', 13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen', 16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty', 30 => 'thirty', 40 => 'forty', 50 => 'fifty', 60 => 'sixty', 70 => 'seventy', 80 => 'eighty', 90 => 'ninety'];
            if ($number < 21) {
                return $words[$number];
            }
            if ($number < 100) {
                return $words[10 * floor($number / 10)] . ($number % 10 ? ' ' . $words[$number % 10] : '');
            }
            if ($number < 1000) {
                return $words[floor($number / 100)] . ' hundred' . ($number % 100 ? ' and ' . convertNumberToWords($number % 100) : '');
            }
            if ($number < 1000000) {
                return convertNumberToWords(floor($number / 1000)) . ' thousand' . ($number % 1000 ? ' ' . convertNumberToWords($number % 1000) : '');
            }
            return $number;
        }
    @endphp
    <script>
        (function() {
                let mode =
                null; // 'print' | 'download' const printBtn = document.getElementById('printReceiptBtn'); const downloadBtn = document.getElementById('downloadPdfBtn'); // PRINT if (printBtn) { printBtn.addEventListener('click', function (e) { e.preventDefault(); mode = 'print'; window.print(); }); } // DOWNLOAD (Save as PDF) if (downloadBtn) { downloadBtn.addEventListener('click', function (e) { e.preventDefault(); mode = 'download'; // Filename hint (best browser-supported way) document.title = "Fee_Receipt_{{ $student->roll_number ?? 'student' }}"; window.print(); }); } // Handle AFTER print dialog closes window.addEventListener('afterprint', function () { if (mode === 'print') { // redirect only after PRINT window.location.href = "{{ route('dashboard') }}"; } // For download → do nothing mode = null; }); })();
    </script>
@endsection
