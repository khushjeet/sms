import { Student } from '../../models/student';
import { jsPDF } from 'jspdf';
import { environment } from '../../../environments/environment';

export interface SchoolPrintDetails {
  name: string;
  address: string;
  phone: string;
  email: string;
  website: string;
  logoUrl?: string | null;
}

interface BuildStudentPrintHtmlParams {
  student: Student;
  school: SchoolPrintDetails;
  generatedOn: string;
  avatar?: string | null;
  avatarBlob?: Blob | null;
  avatarDataUrl?: string | null;
  logoBlob?: Blob | null;
  logoDataUrl?: string | null;
}

interface StudentPdfData {
  studentName: string;
  className: string;
  rollNumber: string;
  fatherName: string;
  fatherPhone: string;
  fatherEmail: string;
  motherName: string;
  motherPhone: string;
  motherEmail: string;
  fatherOccupation: string;
  motherOccupation: string;
  permanentAddress: string;
  currentAddress: string;
  accountNumber: string;
  accountHolder: string;
  ifsc: string;
  relationWithAccountHolder: string;
}

export async function downloadStudentPdfFile(params: BuildStudentPrintHtmlParams): Promise<void> {
  const { student, school, generatedOn, avatar, avatarBlob, avatarDataUrl, logoBlob, logoDataUrl } = params;
  const data = extractPdfData(student);
  const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
  const [resolvedAvatarSource, resolvedLogoSource] = await Promise.all([
    resolvePdfImageSource({
      blob: avatarBlob,
      dataUrl: avatarDataUrl,
      url: avatar,
      studentId: student.id
    }),
    resolvePdfImageSource({
      blob: logoBlob,
      dataUrl: logoDataUrl,
      url: school.logoUrl ?? null
    })
  ]);

  const pageWidth = 595.28;
  const cardX = 46;
  const cardY = 38;
  const cardW = pageWidth - 92;
  const cardH = 740;
  const sectionRightX = cardX + 140;
  const bodyX = cardX + 18;

  doc.setFillColor(243, 236, 236);
  doc.rect(0, 0, pageWidth, 842, 'F');
  drawWatermarkPattern(doc, 'ipsyogapatti', pageWidth, 842);
  doc.setFillColor(236, 236, 236);
  doc.roundedRect(cardX, cardY, cardW, cardH, 10, 10, 'F');
  doc.setDrawColor(44, 44, 44);
  doc.roundedRect(cardX, cardY, cardW, cardH, 10, 10, 'S');

  doc.setDrawColor(198, 198, 198);
  doc.rect(bodyX, cardY + 18, 86, 96);
  doc.rect(cardX + cardW - 104, cardY + 18, 86, 96);
  if (resolvedAvatarSource) {
    await drawPdfImage(doc, resolvedAvatarSource, bodyX + 2, cardY + 20, 82, 92);
  } else {
    doc.setTextColor(148, 163, 184);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text('No Photo', bodyX + 43, cardY + 70, { align: 'center' });
  }
  if (resolvedLogoSource) {
    await drawPdfImage(doc, resolvedLogoSource, cardX + cardW - 102, cardY + 20, 82, 92);
  }

  doc.setTextColor(20, 61, 68);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(24);
  doc.text(school.name, cardX + cardW / 2, cardY + 52, { align: 'center' });
  doc.text('SCHOOL', cardX + cardW / 2, cardY + 82, { align: 'center' });

  doc.setTextColor(25, 25, 25);
  doc.setFontSize(11);
  doc.text(school.address, cardX + cardW / 2, cardY + 100, { align: 'center' });
  doc.setFont('helvetica', 'bold');
  doc.text(`Mob. ${school.phone} | ${school.website}`, cardX + cardW / 2, cardY + 116, { align: 'center' });

  doc.setDrawColor(44, 44, 44);
  doc.setLineWidth(1.4);
  doc.line(bodyX, cardY + 128, cardX + cardW - 18, cardY + 128);

  doc.setTextColor(20, 61, 68);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(14);
  drawNameBlock(doc, data.studentName.toUpperCase(), bodyX + 6, cardY + 148, sectionRightX - bodyX - 18);

  doc.setTextColor(35, 35, 35);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(11);
  doc.text('DOB', sectionRightX, cardY + 156);
  doc.text('Gender', sectionRightX + 126, cardY + 156);
  doc.text('Blood Group', sectionRightX + 252, cardY + 156);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10.5);
  doc.text(formatDate(student.date_of_birth), sectionRightX, cardY + 174);
  doc.text(student.gender || '-', sectionRightX + 126, cardY + 174);
  doc.text(student.blood_group || '-', sectionRightX + 252, cardY + 174);

  doc.setDrawColor(176, 176, 176);
  doc.setLineWidth(0.8);
  doc.line(sectionRightX, cardY + 194, cardX + cardW - 28, cardY + 194);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10.5);
  doc.text('Admission #:', bodyX + 6, cardY + 228);
  doc.text('Class:', bodyX + 6, cardY + 258);
  doc.text('Roll No:', bodyX + 6, cardY + 286);
  doc.setFont('helvetica', 'bold');
  doc.text(student.admission_number || '-', bodyX + 6, cardY + 246);
  doc.text(data.className, bodyX + 48, cardY + 258);
  doc.text(data.rollNumber, bodyX + 48, cardY + 286);

  doc.setTextColor(20, 61, 68);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(17);
  doc.text('Parent / Guardian', sectionRightX, cardY + 220);

  doc.setTextColor(35, 35, 35);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10.5);
  doc.text('Father', sectionRightX, cardY + 248);
  doc.text('Mother', sectionRightX + 126, cardY + 248);
  doc.text('Father Occupation', sectionRightX + 252, cardY + 248);

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  drawWrapped(doc, data.fatherName, sectionRightX, cardY + 266, 116);
  drawWrapped(doc, data.fatherPhone, sectionRightX, cardY + 284, 116, [100, 116, 139]);
  drawWrapped(doc, data.fatherEmail, sectionRightX, cardY + 302, 116, [100, 116, 139]);

  drawWrapped(doc, data.motherName, sectionRightX + 126, cardY + 266, 116);
  drawWrapped(doc, data.motherPhone, sectionRightX + 126, cardY + 284, 116, [100, 116, 139]);
  drawWrapped(doc, data.motherEmail, sectionRightX + 126, cardY + 302, 116, [100, 116, 139]);

  drawWrapped(doc, data.fatherOccupation, sectionRightX + 252, cardY + 266, 104);
  doc.setFont('helvetica', 'bold');
  doc.setTextColor(35, 35, 35);
  doc.text('Mother Occupation', sectionRightX + 252, cardY + 304);
  doc.setFont('helvetica', 'bold');
  drawWrapped(doc, data.motherOccupation, sectionRightX + 252, cardY + 320, 104);

  doc.setDrawColor(176, 176, 176);
  doc.line(sectionRightX, cardY + 372, cardX + cardW - 28, cardY + 372);

  doc.setTextColor(20, 61, 68);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(17);
  doc.text('Address', sectionRightX, cardY + 396);
  doc.setTextColor(35, 35, 35);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10.5);
  doc.text('Permanent', sectionRightX, cardY + 420);
  doc.text('Current', sectionRightX + 190, cardY + 420);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10);
  drawWrapped(doc, data.permanentAddress, sectionRightX, cardY + 438, 176);
  drawWrapped(doc, data.currentAddress, sectionRightX + 190, cardY + 438, 166);

  doc.line(sectionRightX, cardY + 490, cardX + cardW - 28, cardY + 490);
  doc.setTextColor(20, 61, 68);
  doc.setFontSize(17);
  doc.text('Bank Details', sectionRightX, cardY + 514);

  doc.setTextColor(35, 35, 35);
  doc.setFont('helvetica', 'bold');
  doc.setFontSize(10.5);
  doc.text('Bank Account Number', sectionRightX, cardY + 538);
  doc.text('Bank Account Holder Name', sectionRightX + 126, cardY + 538);
  doc.text('IFSC Code', sectionRightX + 292, cardY + 538);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(10);
  doc.text(data.accountNumber, sectionRightX, cardY + 556);
  drawWrapped(doc, data.accountHolder, sectionRightX + 126, cardY + 556, 154);
  doc.text(data.ifsc, sectionRightX + 292, cardY + 556);

  doc.setFont('helvetica', 'bold');
  doc.text('Relation With Account Holder', sectionRightX, cardY + 580);
  doc.setFont('helvetica', 'normal');
  doc.text(data.relationWithAccountHolder, sectionRightX, cardY + 596);

  doc.setFontSize(9);
  doc.setTextColor(107, 114, 128);
  doc.text(`Generated on ${generatedOn}`, cardX + cardW - 18, cardY + cardH - 14, { align: 'right' });

  doc.save(`student-${(student.admission_number || student.id).toString().replace(/\s+/g, '-')}.pdf`);
}

export function buildStudentPrintHtml(params: BuildStudentPrintHtmlParams): string {
  const { student, school, generatedOn, avatar } = params;
  const data = extractPdfData(student);
  const {
    studentName,
    className,
    rollNumber,
    fatherName,
    fatherPhone,
    fatherEmail,
    motherName,
    motherPhone,
    motherEmail,
    fatherOccupation,
    motherOccupation,
    permanentAddress,
    currentAddress,
    accountNumber,
    accountHolder,
    ifsc,
    relationWithAccountHolder
  } = data;

  const schoolTableRows = [
    ['School Name', school.name],
    ['Address', school.address],
    ['Phone', school.phone],
    ['Email', school.email],
    ['Website', school.website]
  ]
    .map(([label, value]) => `<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(value)}</td></tr>`)
    .join('');

  return `
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <title>Student Details - ${escapeHtml(student.admission_number)}</title>
    <style>
      :root {
        --ink: #123b44;
        --text: #1f2937;
        --muted: #64748b;
        --line: #2f2f2f;
        --soft-line: #b8b8b8;
        --card: #ececec;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        background: #f1ebeb;
        color: var(--text);
        font-family: Arial, sans-serif;
      }
      .sheet {
        width: 720px;
        margin: 20px auto;
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 12px;
        padding: 20px;
      }
      .school-header {
        display: grid;
        grid-template-columns: 112px 1fr 120px;
        align-items: center;
        gap: 14px;
      }
      .photo,
      .logo {
        width: 112px;
        height: 120px;
        border: 1px solid #c8c8c8;
        border-radius: 6px;
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
      }
      .photo img,
      .logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
      }
      .empty {
        color: #94a3b8;
        font-size: 12px;
      }
      .school-center {
        text-align: center;
      }
      .school-name {
        margin: 0;
        color: var(--ink);
        font-size: 22px;
        line-height: 1.1;
        letter-spacing: 1.5px;
        font-weight: 800;
        text-transform: uppercase;
      }
      .school-address {
        margin: 10px 0 4px;
        font-weight: 700;
        font-size: 14px;
      }
      .school-contact {
        margin: 0;
        color: #202020;
        font-size: 12px;
      }
      .school-contact .site { color: #ef4444; }
      .divider {
        border: none;
        border-top: 2px solid var(--line);
        margin: 12px 0 14px;
      }
      .row-identity {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
      }
      .label {
        font-weight: 700;
        font-size: 13px;
      }
      .value {
        margin-top: 2px;
        font-size: 14px;
      }
      .name-value {
        color: #123b44;
        text-transform: uppercase;
      }
      .soft-divider {
        border: none;
        border-top: 1px solid var(--soft-line);
        margin: 12px 0;
      }
      .main {
        display: grid;
        grid-template-columns: 150px 1fr;
        gap: 18px;
      }
      .mini-info p {
        margin: 0 0 10px;
        font-size: 13px;
      }
      .mini-info strong {
        font-size: 14px;
      }
      .section-title {
        margin: 0 0 8px;
        font-size: 18px;
        color: var(--ink);
      }
      .triple {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
      }
      .field strong {
        display: block;
        margin-bottom: 2px;
        font-size: 13px;
      }
      .field p {
        margin: 0 0 4px;
        word-break: break-word;
      }
      .muted { color: var(--muted); }
      .dual {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
      }
      .table-title {
        margin: 6px 0 8px;
        font-size: 18px;
        color: var(--ink);
      }
      table {
        width: 100%;
        border-collapse: collapse;
        background: #f5f5f5;
      }
      th,
      td {
        border: 1px solid #bfc5cc;
        padding: 7px 8px;
        text-align: left;
        font-size: 12px;
        vertical-align: top;
      }
      th {
        width: 170px;
        background: #eceff1;
      }
      .generated {
        margin-top: 8px;
        font-size: 11px;
        color: #6b7280;
        text-align: right;
      }
      @media print {
        @page { size: A4; margin: 8mm; }
        body { background: #fff; }
        .sheet { margin: 0 auto; }
      }
    </style>
  </head>
  <body>
    <div class="sheet">
      <div class="school-header">
        <div class="photo">
          ${avatar ? `<img src="${escapeHtml(avatar)}" alt="Student photo" />` : '<span class="empty">No Photo</span>'}
        </div>
        <div class="school-center">
          <h1 class="school-name">${escapeHtml(school.name)}</h1>
          <p class="school-address">${escapeHtml(school.address)}</p>
          <p class="school-contact">Mob. ${escapeHtml(school.phone)} | <span class="site">${escapeHtml(school.website)}</span></p>
        </div>
        <div class="logo">
          ${school.logoUrl ? `<img src="${escapeHtml(school.logoUrl)}" alt="School logo" />` : '<span class="empty">Logo</span>'}
        </div>
      </div>

      <hr class="divider" />

      <div class="row-identity">
        <div>
          <div class="label">Name</div>
          <div class="value name-value">${escapeHtml(studentName)}</div>
        </div>
        <div>
          <div class="label">DOB</div>
          <div class="value">${escapeHtml(formatDate(student.date_of_birth))}</div>
        </div>
        <div>
          <div class="label">Gender</div>
          <div class="value">${escapeHtml(student.gender || '-')}</div>
        </div>
        <div>
          <div class="label">Blood Group</div>
          <div class="value">${escapeHtml(student.blood_group || '-')}</div>
        </div>
      </div>

      <hr class="soft-divider" />

      <div class="main">
        <div class="mini-info">
          <p><strong>Admission #:</strong><br />${escapeHtml(student.admission_number || '-')}</p>
          <p><strong>Class:</strong> ${escapeHtml(className)}</p>
        </div>
        <div>
          <h2 class="section-title">Parent / Guardian</h2>
          <div class="triple">
            <div class="field">
              <strong>Father</strong>
              <p>${escapeHtml(fatherName)}</p>
              <p class="muted">${escapeHtml(fatherPhone)}</p>
              <p class="muted">${escapeHtml(fatherEmail)}</p>
            </div>
            <div class="field">
              <strong>Mother</strong>
              <p>${escapeHtml(motherName)}</p>
              <p class="muted">${escapeHtml(motherPhone)}</p>
              <p class="muted">${escapeHtml(motherEmail)}</p>
            </div>
            <div class="field">
              <strong>Father Occupation</strong>
              <p>${escapeHtml(fatherOccupation)}</p>
              <strong>Mother Occupation</strong>
              <p>${escapeHtml(motherOccupation)}</p>
            </div>
          </div>

          <hr class="soft-divider" />

          <h2 class="section-title">Address</h2>
          <div class="dual">
            <div class="field">
              <strong>Permanent</strong>
              <p>${escapeHtml(permanentAddress)}</p>
            </div>
            <div class="field">
              <strong>Current</strong>
              <p>${escapeHtml(currentAddress)}</p>
            </div>
          </div>

          <hr class="soft-divider" />

          <h2 class="section-title">Bank Details</h2>
          <div class="triple">
            <div class="field"><strong>Account Number</strong><p>${escapeHtml(accountNumber)}</p></div>
            <div class="field"><strong>Account Holder</strong><p>${escapeHtml(accountHolder)}</p></div>
            <div class="field"><strong>IFSC</strong><p>${escapeHtml(ifsc)}</p></div>
          </div>

          <h3 class="table-title">Bank Details Table</h3>
          <table>
            <tr><th>Bank Account Number</th><td>${escapeHtml(accountNumber)}</td></tr>
            <tr><th>Bank Account Holder Name</th><td>${escapeHtml(accountHolder)}</td></tr>
            <tr><th>IFSC Code</th><td>${escapeHtml(ifsc)}</td></tr>
            <tr><th>Relation With Account Holder</th><td>${escapeHtml(relationWithAccountHolder)}</td></tr>
          </table>

          <hr class="soft-divider" />

        </div>
      </div>
      <p class="generated">Generated on ${escapeHtml(generatedOn)}</p>
    </div>
  </body>
</html>`;
}

function extractPdfData(student: Student): StudentPdfData {
  const anyStudent = student as any;
  const studentName = student.user?.full_name || `${student.user?.first_name ?? ''} ${student.user?.last_name ?? ''}`.trim() || '-';
  const className = anyStudent?.currentEnrollment?.section?.class?.name
    || anyStudent?.current_enrollment?.section?.class?.name
    || student.profile?.class?.name
    || '-';
  const rollNumber = anyStudent?.profile?.roll_number || anyStudent?.roll_number || '-';
  const fatherName = anyStudent?.profile?.father_name || anyStudent?.father_name || anyStudent?.fatherName || '-';
  const fatherPhone = anyStudent?.profile?.father_mobile_number || anyStudent?.father_mobile_number || anyStudent?.profile?.father_mobile || anyStudent?.father_phone || anyStudent?.fatherPhone || '-';
  const fatherEmail = anyStudent?.profile?.father_email || anyStudent?.father_email || anyStudent?.fatherEmail || '-';
  const motherName = anyStudent?.profile?.mother_name || anyStudent?.mother_name || anyStudent?.motherName || '-';
  const motherPhone = anyStudent?.profile?.mother_mobile_number || anyStudent?.mother_mobile_number || anyStudent?.profile?.mother_mobile || anyStudent?.mother_phone || anyStudent?.motherPhone || '-';
  const motherEmail = anyStudent?.profile?.mother_email || anyStudent?.mother_email || anyStudent?.motherEmail || '-';
  const fatherOccupation = anyStudent?.profile?.father_occupation || anyStudent?.father_occupation || anyStudent?.fatherOccupation || '-';
  const motherOccupation = anyStudent?.profile?.mother_occupation || anyStudent?.mother_occupation || anyStudent?.motherOccupation || '-';
  const permanentAddress = anyStudent?.profile?.permanent_address || anyStudent?.permanent_address || student.address || '-';
  const currentAddress = anyStudent?.profile?.current_address || anyStudent?.current_address || `${student.address || ''} ${student.city || ''} ${student.state || ''} ${student.pincode || ''}`.trim() || '-';
  const accountNumber = anyStudent?.profile?.bank_account_number || anyStudent?.bank_account_number || anyStudent?.account_number || '-';
  const accountHolder = anyStudent?.profile?.bank_account_holder || anyStudent?.bank_account_holder || anyStudent?.account_holder || fatherName;
  const ifsc = anyStudent?.profile?.ifsc_code || anyStudent?.bank_ifsc || anyStudent?.ifsc || '-';
  const relationWithAccountHolder = anyStudent?.profile?.relation_with_account_holder || anyStudent?.relation_with_account_holder || '-';

  return {
    studentName,
    className,
    rollNumber: String(rollNumber),
    fatherName,
    fatherPhone,
    fatherEmail,
    motherName,
    motherPhone,
    motherEmail,
    fatherOccupation,
    motherOccupation,
    permanentAddress,
    currentAddress,
    accountNumber,
    accountHolder,
    ifsc,
    relationWithAccountHolder
  };
}

function drawWrapped(
  doc: jsPDF,
  text: string,
  x: number,
  y: number,
  width: number,
  color: [number, number, number] = [35, 35, 35]
): void {
  doc.setTextColor(color[0], color[1], color[2]);
  doc.setFont('helvetica', 'bold');
  const lines = doc.splitTextToSize(text || '-', width) as string[];
  doc.text(lines.slice(0, 3), x, y);
}

async function toDataUrl(url: string | null | undefined, studentId?: number): Promise<string | null> {
  if (!url) {
    return null;
  }
  if (url.startsWith('data:')) {
    return url;
  }

  const candidates = buildImageCandidates(url, studentId);
  const authHeaders = getAuthHeaders();
  const apiBase = environment.apiBaseUrl.replace(/\/$/, '');
  const apiPath = extractPath(environment.apiBaseUrl);
  for (const candidate of candidates) {
    try {
      const shouldUseAuth = candidate.startsWith(apiBase) || candidate.startsWith(apiPath);
      const response = await fetch(candidate, {
        mode: 'cors',
        credentials: shouldUseAuth ? 'include' : 'omit',
        headers: shouldUseAuth ? authHeaders : undefined
      });
      if (!response.ok) {
        continue;
      }

      const contentType = (response.headers.get('content-type') || '').toLowerCase();
      if (contentType && !isSupportedImageMimeType(contentType) && contentType !== 'application/octet-stream') {
        continue;
      }

      const blob = await response.blob();
      const blobType = blob.type.toLowerCase();
      if (blobType && !isSupportedImageMimeType(blobType) && blobType !== 'application/octet-stream') {
        continue;
      }

      const dataUrl = await blobToDataUrl(blob);
      if (dataUrl) {
        return dataUrl;
      }
    } catch {
      // Try next candidate URL.
    }
  }
  return null;
}

function buildImageCandidates(url: string, studentId?: number): string[] {
  const values = new Set<string>();
  const apiBase = environment.apiBaseUrl.replace(/\/$/, '');
  const apiOrigin = new URL(environment.apiBaseUrl).origin;
  const apiPath = extractPath(environment.apiBaseUrl);
  const normalized = url.trim();

  const proxiedInput = toProxiedPath(normalized, apiOrigin);
  if (proxiedInput) {
    values.add(proxiedInput);
  }
  values.add(normalized);

  if (normalized.startsWith('/')) {
    values.add(`${apiOrigin}${normalized}`);
  } else if (!normalized.startsWith('http://') && !normalized.startsWith('https://')) {
    values.add(`${apiOrigin}/${normalized.replace(/^\/+/, '')}`);
  }

  if (normalized.includes('/public/storage/')) {
    values.add(normalized.replace('/public/storage/', '/storage/'));
  }
  if (normalized.includes('/storage/')) {
    values.add(normalized.replace('/storage/', '/public/storage/'));
  }

  const relativePath = normalized.replace(/^\/+/, '');
  if (relativePath.startsWith('public/storage/')) {
    const storagePath = relativePath.replace(/^public\/storage\//, '');
    values.add(`${apiOrigin}/public/storage/${storagePath}`);
    values.add(`${apiOrigin}/storage/${storagePath}`);
  } else if (relativePath.startsWith('storage/')) {
    const storagePath = relativePath.replace(/^storage\//, '');
    values.add(`${apiOrigin}/storage/${storagePath}`);
    values.add(`${apiOrigin}/public/storage/${storagePath}`);
  }

  if (studentId) {
    values.add(`${apiPath}/students/${studentId}/avatar`);
    values.add(`${apiBase}/students/${studentId}/avatar`);
  }

  return Array.from(values);
}

function toProxiedPath(url: string, apiOrigin: string): string | null {
  if (!url.startsWith('http://') && !url.startsWith('https://')) {
    return url.startsWith('/') ? url : `/${url}`;
  }

  try {
    const parsed = new URL(url);
    if (parsed.origin !== apiOrigin) {
      return null;
    }
    return `${parsed.pathname}${parsed.search}`;
  } catch {
    return null;
  }
}

function extractPath(url: string): string {
  try {
    const parsed = new URL(url);
    const path = parsed.pathname.replace(/\/$/, '');
    return path || '/';
  } catch {
    return '/api/v1';
  }
}

export async function blobToDataUrl(blob: Blob | null | undefined): Promise<string | null> {
  if (!blob) {
    return null;
  }

  const blobType = blob.type.toLowerCase();
  if (blobType && !isSupportedImageMimeType(blobType) && blobType !== 'application/octet-stream') {
    return null;
  }

  const dataUrl = await new Promise<string | null>((resolve) => {
    const reader = new FileReader();
    reader.onload = () => resolve((reader.result as string) || null);
    reader.onerror = () => resolve(null);
    reader.readAsDataURL(blob);
  });

  if (!dataUrl) {
    return null;
  }

  const normalizedDataUrl = await normalizeImageDataUrl(dataUrl, blob);
  return ensurePdfCompatibleImageDataUrl(normalizedDataUrl);
}

async function normalizeImageDataUrl(dataUrl: string, blob: Blob): Promise<string> {
  if (!dataUrl.startsWith('data:application/octet-stream')) {
    return dataUrl;
  }

  const inferredMime = await inferImageMimeType(blob);
  if (!inferredMime) {
    return dataUrl;
  }

  return dataUrl.replace('data:application/octet-stream', `data:${inferredMime}`);
}

async function ensurePdfCompatibleImageDataUrl(dataUrl: string): Promise<string> {
  if (dataUrl.startsWith('data:image/png') || dataUrl.startsWith('data:image/jpeg')) {
    return dataUrl;
  }

  try {
    return await rasterizeImageDataUrl(dataUrl, 'image/png');
  } catch {
    return dataUrl;
  }
}

function rasterizeImageDataUrl(dataUrl: string, targetMimeType: 'image/png' | 'image/jpeg'): Promise<string> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => {
      try {
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth || image.width || 1;
        canvas.height = image.naturalHeight || image.height || 1;

        const context = canvas.getContext('2d');
        if (!context) {
          reject(new Error('Canvas context unavailable'));
          return;
        }

        if (targetMimeType === 'image/jpeg') {
          context.fillStyle = '#ffffff';
          context.fillRect(0, 0, canvas.width, canvas.height);
        }

        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        resolve(canvas.toDataURL(targetMimeType));
      } catch (error) {
        reject(error);
      }
    };
    image.onerror = () => reject(new Error('Image load failed'));
    image.src = dataUrl;
  });
}

async function inferImageMimeType(blob: Blob): Promise<string | null> {
  const bytes = new Uint8Array(await blob.slice(0, 16).arrayBuffer());

  if (bytes.length >= 4 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
    return 'image/png';
  }

  if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
    return 'image/jpeg';
  }

  if (
    bytes.length >= 12 &&
    bytes[0] === 0x52 &&
    bytes[1] === 0x49 &&
    bytes[2] === 0x46 &&
    bytes[3] === 0x46 &&
    bytes[8] === 0x57 &&
    bytes[9] === 0x45 &&
    bytes[10] === 0x42 &&
    bytes[11] === 0x50
  ) {
    return 'image/webp';
  }

  if (bytes.length >= 4 && bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x38) {
    return 'image/gif';
  }

  return null;
}

function isSupportedImageMimeType(value: string): boolean {
  return value.startsWith('image/');
}

async function resolvePdfImageSource(params: {
  blob?: Blob | null;
  dataUrl?: string | null;
  url?: string | null;
  studentId?: number;
}): Promise<string | null> {
  if (params.blob) {
    const blobSource = await blobToDataUrl(params.blob);
    if (blobSource) {
      return blobSource;
    }
  }

  if (params.dataUrl) {
    return params.dataUrl;
  }

  if (!params.url) {
    return null;
  }

  return toDataUrl(params.url, params.studentId);
}

async function drawPdfImage(
  doc: jsPDF,
  source: string,
  x: number,
  y: number,
  width: number,
  height: number
): Promise<void> {
  const normalizedSource = await normalizePdfImageSource(source);
  doc.addImage(normalizedSource, detectImageFormat(normalizedSource), x, y, width, height);
}

async function normalizePdfImageSource(source: string): Promise<string> {
  try {
    return await rasterizeImageDataUrl(source, 'image/png');
  } catch {
    return source;
  }
}

function getAuthHeaders(): Record<string, string> {
  try {
    const raw = localStorage.getItem('sms_auth_session');
    if (!raw) {
      return {};
    }
    const parsed = JSON.parse(raw) as { token?: string };
    if (!parsed?.token) {
      return {};
    }
    return { Authorization: `Bearer ${parsed.token}` };
  } catch {
    return {};
  }
}

function detectImageFormat(dataUrl: string): 'PNG' | 'JPEG' {
  if (dataUrl.startsWith('data:image/png')) {
    return 'PNG';
  }

  return 'JPEG';
}

function drawNameBlock(doc: jsPDF, name: string, x: number, y: number, width: number): void {
  doc.setTextColor(20, 61, 68);
  doc.setFont('helvetica', 'bold');
  const safeName = name || '-';

  let fontSize = 14;
  let lines: string[] = [];
  while (fontSize >= 10) {
    doc.setFontSize(fontSize);
    lines = doc.splitTextToSize(safeName, width) as string[];
    if (lines.length <= 2) {
      break;
    }
    fontSize -= 1;
  }

  const shown = lines.slice(0, 2);
  doc.text(shown, x, y, { lineHeightFactor: 1.1 });
}

function drawWatermarkPattern(doc: jsPDF, label: string, pageWidth: number, pageHeight: number): void {
  doc.setTextColor(206, 198, 198);
  doc.setFont('helvetica', 'normal');
  doc.setFontSize(14);

  const stepX = 130;
  const stepY = 90;
  for (let y = 30; y <= pageHeight + 40; y += stepY) {
    for (let x = -20; x <= pageWidth + 20; x += stepX) {
      doc.text(label, x, y, { angle: 330 });
    }
  }
}

function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '-';
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed);
}

function escapeHtml(value: string): string {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
