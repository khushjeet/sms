<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <title>Bulk Admit Cards</title>
    <style>
      @page { size: A4 portrait; margin: 8mm; }
      * { box-sizing: border-box; font-weight: 700 !important; }
      body { font-family: DejaVu Sans, Arial, sans-serif; margin: 0; color: #111827; font-weight: 700; }
      .card { page-break-inside: avoid; position: relative; }
      .watermark {
        position: absolute;
        top: 36%;
        left: 50%;
        transform: translateX(-50%);
        width: 100%;
        text-align: center;
        opacity: 0.1;
        z-index: 0;
      }
      .watermark img { width: 300px; height: 300px; object-fit: contain; }
      .watermark-text { font-size: 72px; font-weight: 700; color: #8b97a7; transform: rotate(-28deg); display: inline-block; }
      .ips-header { border: 1px solid #111827; border-radius: 18px; background: #f3f4f6; padding: 10px 12px; }
      .ips-top-meta { width: 100%; display: table; table-layout: fixed; margin-bottom: 6px; font-size: 11px; color: #111827; }
      .ips-top-meta-left, .ips-top-meta-right { display: table-cell; width: 50%; vertical-align: middle; }
      .ips-top-meta-right { text-align: right; }
      .ips-header-main { width: 100%; display: table; table-layout: fixed; }
      .ips-header-main--no-qr .ips-right { width: 1px; padding: 0; }
      .ips-header-main--no-qr .ips-qr-fallback { display: none; }
      .ips-left, .ips-center, .ips-right { display: table-cell; vertical-align: top; }
      .ips-left { width: 100px; padding: 2px 8px 0 0; text-align: left; }
      .ips-right { width: 82px; padding: 6px 0 0 8px; text-align: right; }
      .ips-logo { width: 92px; height: 92px; border: 1px solid #d1d5db; background: #ffffff; object-fit: contain; padding: 2px; }
      .ips-qr { width: 76px; height: 76px; border: 1px solid #d1d5db; background: #ffffff; object-fit: contain; padding: 2px; display: inline-block; }
      .ips-qr-fallback { width: 76px; height: 76px; border: 1px solid #d1d5db; background: #ffffff; font-size: 11px; color: #6b7280; display: inline-flex; align-items: center; justify-content: center; }
      .ips-center { text-align: center; padding: 0 4px; }
      .ips-name { margin: 0; font-size: 26px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #123f4a; line-height: 1.15; white-space: nowrap; }
      .ips-address { margin-top: 4px; font-size: 14px; font-weight: 700; color: #111827; }
      .ips-contact { margin-top: 2px; font-size: 13px; color: #111827; font-weight: 700; line-height: 1.25; }
      .phone-line { white-space: nowrap; }
      .ips-title-row { margin-top: 8px; text-align: center; }
      h1 { margin: 0; font-size: 20px; }
      .session { margin: 2px 0 0; font-size: 12px; color: #111827; font-weight: 700; }
      .badge { border: 1px solid #cbd5f5; padding: 4px 10px; border-radius: 999px; font-size: 11px; text-transform: capitalize; background: #eef2ff; }
      .meta-grid { margin: 12px 0; display: block; position: relative; padding-right: 78px; }
      .meta-grid div { font-size: 12px; font-weight: 700; color: #111827; line-height: 1.25; margin: 0; }
      .photo-cell { position: absolute; top: 0; right: 0; width: 60px; text-align: right; }
      .student-photo { width: 60px; height: 72px; border: 1px solid #9ca3af; object-fit: cover; background: #ffffff; }
      table { width: 100%; border-collapse: collapse; margin-top: 10px; }
      th, td { border: 1px solid #9ca3af; padding: 6px; font-size: 11px; text-align: left; color: #111827; font-weight: 700; }
      th { background: #d1d5db; }
      td { background: #f8fafc; }
      .instructions { margin-top: 10px; border: 1px solid #9ca3af; background: #f8fafc; padding: 8px 10px; }
      .instructions-title { margin: 0 0 4px; font-size: 12px; color: #111827; }
      .instructions-list { margin: 0; padding-left: 18px; }
      .instructions-list li { font-size: 11px; line-height: 1.3; color: #111827; margin: 1px 0; }
      .ips-header, .meta-grid, table { position: relative; z-index: 2; }
      .signatures { margin-top: 22px; width: 100%; display: table; table-layout: fixed; position: relative; z-index: 2; }
      .signature-box { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; padding: 0 20px; }
      .signature-line { border-top: 1px solid #111827; height: 1px; margin-bottom: 6px; }
      .signature-image { width: 140px; height: 44px; object-fit: contain; display: block; margin: 0 auto 6px; }
      .signature-label { font-size: 12px; color: #111827; font-weight: 700; }
      .content-body { position: relative; z-index: 2; }
      .page-break { page-break-after: always; margin: 18px 0; }
    </style>
  </head>
  <body>
    @php
      $schoolName = $school['name'] ?? config('school.name');
      $schoolAddress = $school['address'] ?? config('school.address');
      $schoolPhone = $school['phone'] ?? config('school.phone');
      $schoolWebsite = $school['website'] ?? config('school.website');
      $schoolUdise = $school['udise'] ?? config('school.udise');
      $schoolRegNo = $school['reg_no'] ?? config('school.reg_no');
      $principalSignature = $school['principal_signature_data_url'] ?? null;
      $logoUrl = trim((string) ($school['logo_data_url'] ?? ($school['logo_url'] ?? config('school.logo_url') ?? '')));
      $watermarkText = strtoupper((string) ($school['watermark_text'] ?? $schoolName ?: 'SCHOOL'));
      $watermarkLogoUrl = trim((string) ($school['watermark_logo_data_url'] ?? ($school['watermark_logo_url'] ?? '')));
      $fallbackLogoPath = public_path('storage/assets/ips.png');
      $resolvedLogo = null;
      $resolvedWatermarkLogo = null;

      if ($logoUrl !== '') {
        $normalizedLogoUrl = ltrim(str_replace('\\', '/', $logoUrl), '/');
        $looksEmbedded = preg_match('/^(data:|file:)/i', $logoUrl) === 1;
        $publicLogoPath = public_path($normalizedLogoUrl);

        if ($looksEmbedded) {
          $resolvedLogo = $logoUrl;
        } elseif (file_exists($publicLogoPath)) {
          $resolvedLogo = $publicLogoPath;
        }
      }

      if (!$resolvedLogo && file_exists($fallbackLogoPath)) {
        $resolvedLogo = $fallbackLogoPath;
      }
      if ($watermarkLogoUrl !== '') {
        $normalizedWatermarkLogoUrl = ltrim(str_replace('\\', '/', $watermarkLogoUrl), '/');
        $looksEmbeddedWatermark = preg_match('/^(data:|file:)/i', $watermarkLogoUrl) === 1;
        $publicWatermarkPath = public_path($normalizedWatermarkLogoUrl);

        if ($looksEmbeddedWatermark) {
          $resolvedWatermarkLogo = $watermarkLogoUrl;
        } elseif (file_exists($publicWatermarkPath)) {
          $resolvedWatermarkLogo = $publicWatermarkPath;
        }
      }

      if (!$resolvedWatermarkLogo) {
        $resolvedWatermarkLogo = $resolvedLogo;
      }
      $phoneLine = trim((string) ($schoolPhone ?? ''));
    @endphp
    @if (empty($admitCards))
      <p>No admit cards available.</p>
    @else
      @foreach ($admitCards as $card)
        @php
          $sessionParts = array_filter([
            $card['exam_name'] ?? ($session['name'] ?? null),
            $card['class_name'] ?? ($session['class_name'] ?? null),
            $card['section_name'] ?? null,
            $card['academic_year'] ?? ($session['academic_year'] ?? null),
          ]);
          $hasQrCode = !empty($card['verification_qr_data_url']);
          // resolve photo per-card, preferring the provided URL then falling back
          $photoUrl = trim((string) ($card['photo_url'] ?? ''));
          $resolvedStudentPhoto = null;

          if ($photoUrl !== '') {
              $normalized = ltrim(str_replace('\\', '/', $photoUrl), '/');
              $looksEmbedded = preg_match('/^(data:|file:)/i', $photoUrl) === 1;
              $looksRemote = preg_match('/^https?:/i', $photoUrl) === 1;
              if ($looksEmbedded) {
                  $resolvedStudentPhoto = $photoUrl;
              } elseif ($looksRemote) {
                  $parsedPhotoPath = parse_url($photoUrl, PHP_URL_PATH);
                  $parsedPhotoPath = is_string($parsedPhotoPath) ? ltrim(str_replace('\\', '/', $parsedPhotoPath), '/') : '';
                  $localStoragePhotoPath = $parsedPhotoPath !== ''
                      ? public_path(preg_replace('/^storage\//', 'storage/', $parsedPhotoPath))
                      : null;
                  if ($localStoragePhotoPath && file_exists($localStoragePhotoPath)) {
                      $resolvedStudentPhoto = $localStoragePhotoPath;
                  }
              } else {
                  $publicPhotoPath = public_path($normalized);
                  $storagePhotoPath = public_path('storage/' . preg_replace('/^(public\/storage\/|storage\/)/', '', $normalized));
                  if (file_exists($publicPhotoPath)) {
                      $resolvedStudentPhoto = $publicPhotoPath;
                  } elseif (file_exists($storagePhotoPath)) {
                      $resolvedStudentPhoto = $storagePhotoPath;
                  }
              }
          }

        @endphp
        <section class="card">
          <div class="watermark">
            @if ($resolvedWatermarkLogo)
              <img src="{{ $resolvedWatermarkLogo }}" alt="Watermark Logo" />
            @else
              <span class="watermark-text">{{ $watermarkText }}</span>
            @endif
          </div>
          <div class="ips-header">
            <div class="ips-top-meta">
              <div class="ips-top-meta-left">
                @if (!empty($schoolUdise))
                  <strong>UDISE:</strong> {{ $schoolUdise }}
                @endif
              </div>
              <div class="ips-top-meta-right">
                @if (!empty($schoolRegNo))
                  <strong>Reg No:</strong> {{ $schoolRegNo }}
                @endif
              </div>
            </div>
            <div class="ips-header-main{{ $hasQrCode ? '' : ' ips-header-main--no-qr' }}">
              <div class="ips-left">
                @if ($resolvedLogo)
                  <img src="{{ $resolvedLogo }}" alt="School Logo Left" class="ips-logo" />
                @endif
              </div>
              <div class="ips-center">
                <h2 class="ips-name">{{ $schoolName }}</h2>
                @if (!empty($schoolAddress))
                  <div class="ips-address">{{ $schoolAddress }}</div>
                @endif
                @if ($phoneLine !== '')
                  <div class="ips-contact phone-line">Mob. {{ $phoneLine }}</div>
                @endif
                @if (!empty($schoolWebsite))
                  <div class="ips-contact">{{ $schoolWebsite }}</div>
                @endif
              </div>
              <div class="ips-right">
                @if ($hasQrCode)
                  <img
                    src="{{ $card['verification_qr_data_url'] }}"
                    alt="Verification QR"
                    class="ips-qr"
                  />
                @else
                  <div class="ips-qr-fallback"></div>
                @endif
              </div>
            </div>
            <div class="ips-title-row">
              <div>
                <h1>Admit Card</h1>
                @if (!empty($sessionParts))
                  <p class="session">{{ implode(' | ', $sessionParts) }}</p>
                @endif
              </div>
            </div>
          </div>
          <div class="content-body">
            <div class="meta-grid">
              <div><strong>Student:</strong> {{ $card['student_name'] ?? '-' }}</div>
              <div class="photo-cell">
                @if (!empty($resolvedStudentPhoto))
                  <img src="{{ $resolvedStudentPhoto }}" alt="Student Photo" class="student-photo" />
                @else
                  <span>-</span>
                @endif
              </div>
              <div><strong>Father Name:</strong> {{ $card['father_name'] ?? '-' }}</div>
              <div><strong>Mother Name:</strong> {{ $card['mother_name'] ?? '-' }}</div>
              <div><strong>DOB:</strong> {{ $card['dob'] ?? '-' }}</div>
              <div><strong>Class:</strong> {{ $card['class_name'] ?? '-' }}</div>
              <div><strong>Roll Number:</strong> {{ $card['roll_number'] ?? '-' }}</div>
              <div><strong>Seat Number:</strong> {{ $card['seat_number'] ?? '-' }}</div>
            </div>
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Subject</th>
                  <th>Code</th>
                  <th>Date</th>
                  <th>Exam Session</th>
                  <th>Time</th>
                  <th>Room</th>
                </tr>
              </thead>
              <tbody>
                @if (empty($card['schedule']))
                  <tr>
                    <td colspan="7">No schedule available.</td>
                  </tr>
                @else
                  @foreach ($card['schedule'] as $rowIndex => $row)
                    <tr>
                      <td>{{ $rowIndex + 1 }}</td>
                      <td>{{ $row['subject_name'] ?? '-' }}</td>
                      <td>{{ $row['subject_code'] ?? '-' }}</td>
                      <td>{{ $row['exam_date'] ?? '-' }}</td>
                      <td>{{ $row['exam_shift'] ?? '-' }}</td>
                      <td>{{ $row['start_time'] ?? '-' }} - {{ $row['end_time'] ?? '-' }}</td>
                      <td>{{ $row['room_number'] ?? '-' }}</td>
                    </tr>
                  @endforeach
                @endif
              </tbody>
            </table>
            <div class="instructions">
              <p class="instructions-title"><strong>General Instructions</strong></p>
              <ol class="instructions-list">
                <li>Carry this admit card and school ID card on all exam days.</li>
                <li>Reach the examination room at least 30 minutes before the exam time.</li>
                <li>No mobile phones, smart watches, calculators, or unfair material are allowed.</li>
                <li>Use only blue or black pen unless specifically instructed otherwise.</li>
                <li>Write your roll number and other required details correctly on the answer sheet.</li>
                <li>Follow all instructions given by invigilators and maintain discipline.</li>
              </ol>
            </div>
            <div class="signatures">
              <div class="signature-box">
                <div class="signature-line"></div>
                <div class="signature-label">Class Teacher Signature</div>
              </div>
              <div class="signature-box">
                @if (!empty($principalSignature))
                  <img src="{{ $principalSignature }}" alt="Principal signature" class="signature-image" />
                @else
                  <div class="signature-line"></div>
                @endif
                <div class="signature-label">Principal Signature</div>
              </div>
            </div>
          </div>
        </section>
        @if (! $loop->last)
          <div class="page-break"></div>
        @endif
      @endforeach
    @endif
  </body>
</html>
