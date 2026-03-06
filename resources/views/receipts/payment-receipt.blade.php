<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Receipt {{ $receiptNumber }}</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 18px; color: #0f172a; }
    .container { width: 190mm; max-width: 100%; margin: 0 auto; position: relative; }
    .card { border: 1px solid #111; border-radius: 10px; padding: 14px; }
    .row { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; }
    .muted { color: #475569; font-size: 12px; }
    .title { font-size: 22px; font-weight: 800; margin: 0; letter-spacing: 0.08em; text-transform: uppercase; }
    .sub { font-size: 13px; margin-top: 6px; }
    .logo-box { width: 120px; height: 90px; display: flex; align-items: center; justify-content: flex-start; }
    .logo-placeholder { width: 70px; height: 70px; border: 2px dashed #94a3b8; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-weight:700; color:#475569; }
    .qr-box { width: 180px; display: flex; justify-content: flex-end; }
    .qr-box img { width: 130px; height: 130px; border: 1px solid #cbd5f5; padding: 6px; background: #fff; border-radius: 6px; }
    hr { border: none; border-top: 2px solid #111; margin: 12px 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { border: 1px solid #111; padding: 8px; font-size: 13px; text-align: left; }
    th { background: #f1f5f9; }
    .kvs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
    .kvs > div { font-size: 13px; }
    .actions { display:flex; gap:10px; justify-content:flex-end; margin-top: 14px; }
    .btn { border: 1px solid #0f172a; background: #0f172a; color: #fff; padding: 10px 14px; border-radius: 10px; font-weight: 700; cursor: pointer; }
    .btn.secondary { background: #16a34a; border-color: #16a34a; }
    @media print {
      @page { size: auto; margin: 6mm; }
      .no-print { display: none !important; }
      body { padding: 0; }
      .card { border: 1px solid #111; }
    }
  </style>
</head>
<body>
@php
  $schoolName = config('school.name', config('app.name', 'School'));
  $schoolAddress = config('school.address', '');
  $schoolPhone = config('school.phone', '');
  $schoolWebsite = config('school.website', '');
  $logoUrl = config('school.logo_url', '');

  $domain = rtrim(config('app.url', url('/')), '/');
  $verifyLink = "{$domain}/verify/receipts/{$receiptNumber}";

  try {
      $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::size(220)->generate($verifyLink);
      $qrDataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
  } catch (\Throwable $e) {
      $qrSize = 220;
      $qrDataUri = "https://chart.googleapis.com/chart?cht=qr&chs={$qrSize}x{$qrSize}&chl=" . rawurlencode($verifyLink) . '&choe=UTF-8';
  }
@endphp

<div class="container">
  <div class="card">
    <div class="row">
      <div class="logo-box">
        @if(!empty($logoUrl))
          <img src="{{ $logoUrl }}" alt="Logo" style="width:70px;height:70px;object-fit:contain" />
        @else
          <div class="logo-placeholder">LOGO</div>
        @endif
      </div>

      <div style="flex:1; text-align:center; padding: 4px 10px;">
        <p class="title">{{ $schoolName }}</p>
        @if($schoolAddress)<div class="sub">{{ $schoolAddress }}</div>@endif
        <div class="sub">
          @if($schoolPhone) <span>{{ $schoolPhone }}</span> @endif
          @if($schoolPhone && $schoolWebsite) <span> | </span> @endif
          @if($schoolWebsite) <span>{{ $schoolWebsite }}</span> @endif
        </div>
        <div class="muted" style="margin-top:6px;">Fee Receipt (Credit)</div>
      </div>

      <div class="qr-box">
        <a href="{{ $verifyLink }}" target="_blank" rel="noreferrer" title="Scan to verify receipt">
          <img src="{{ $qrDataUri }}" alt="QR code" />
        </a>
      </div>
    </div>

    <hr />

    <div class="kvs">
      <div><strong>Receipt #:</strong> {{ $receiptNumber }}</div>
      <div><strong>Date:</strong> {{ $paidAt }}</div>
      <div><strong>Method:</strong> {{ $paymentMethod }}</div>
      <div><strong>Amount:</strong> ₹{{ number_format((float)$amount, 2) }}</div>
      <div><strong>Student:</strong> {{ $studentName }}</div>
      <div><strong>Admission #:</strong> {{ $admissionNumber }}</div>
      <div><strong>Class/Section:</strong> {{ $classSection }}</div>
      <div><strong>Enrollment ID:</strong> {{ $enrollmentId }}</div>
    </div>

    <div style="margin-top: 12px;">
      <table>
        <thead>
          <tr>
            <th>Description</th>
            <th style="width: 160px;">Reference</th>
            <th style="width: 140px;">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Payment received</td>
            <td>{{ $reference }}</td>
            <td>₹{{ number_format((float)$amount, 2) }}</td>
          </tr>
        </tbody>
      </table>
      @if(!empty($remarks))
        <p class="muted" style="margin-top:10px;"><strong>Notes:</strong> {{ $remarks }}</p>
      @endif
    </div>

    <div class="actions no-print">
      <button class="btn" type="button" id="printBtn">Print</button>
      <button class="btn secondary" type="button" id="saveBtn">Save as PDF</button>
    </div>
  </div>
</div>

<script>
  (function () {
    var mode = null;
    var printBtn = document.getElementById('printBtn');
    var saveBtn = document.getElementById('saveBtn');
    if (printBtn) {
      printBtn.addEventListener('click', function (e) {
        e.preventDefault();
        mode = 'print';
        window.print();
      });
    }
    if (saveBtn) {
      saveBtn.addEventListener('click', function (e) {
        e.preventDefault();
        mode = 'download';
        document.title = "Receipt_{{ $receiptNumber }}";
        window.print();
      });
    }
    window.addEventListener('afterprint', function () {
      mode = null;
    });
  })();
</script>
</body>
</html>
