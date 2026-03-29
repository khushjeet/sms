<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Verify Receipt {{ $receiptNumber }}</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 18px; background: #f1f5f9; color: #0f172a; }
    .card { max-width: 680px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 18px 20px; box-shadow: 0 12px 28px rgba(15,23,42,0.08); }
    .badge { display:inline-block; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .ok { background: #dcfce7; color: #166534; }
    .bad { background: #fee2e2; color: #991b1b; }
    .muted { color: #CACFD6; font-size: 13px; }
    .kvs { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px; }
    .kvs div { background:#f8fafc; border:1px solid #e2e8f0; padding: 10px 12px; border-radius: 12px; }
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 6px;">Receipt Verification</h2>
    <div class="muted">Receipt #: <strong>{{ $receiptNumber }}</strong></div>

    @if($type === 'unknown')
      <p style="margin-top:12px;">
        <span class="badge bad">Not Found</span>
      </p>
      <p class="muted">This receipt number is not present in the system.</p>
    @else
      <p style="margin-top:12px;">
        <span class="badge ok">Verified</span>
      </p>
      <div class="kvs">
        <div><strong>Type</strong><br><span class="muted">{{ strtoupper($type) }}</span></div>
        <div><strong>Amount</strong><br><span class="muted">₹{{ number_format((float) $amount, 2) }}</span></div>
        <div><strong>Date</strong><br><span class="muted">{{ $paidAt }}</span></div>
        <div><strong>Method</strong><br><span class="muted">{{ $paymentMethod }}</span></div>
      </div>
    @endif
  </div>
</body>
</html>

