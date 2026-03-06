import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { AdminMarksService } from '../../core/services/admin-marks.service';
import { ClassesService } from '../../core/services/classes.service';
import { ExamConfigurationsService } from '../../core/services/exam-configurations.service';
import { SectionsService } from '../../core/services/sections.service';
import { SubjectsService } from '../../core/services/subjects.service';
import { AdminMarksRow, AdminMarksScope, AdminMarksTeacherColumn } from '../../models/admin-marks';
import { ClassModel } from '../../models/class';
import { ExamConfiguration } from '../../models/exam-configuration';
import { Section } from '../../models/section';
import { Subject } from '../../models/subject';

@Component({
  selector: 'app-admin-assign-marks',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './admin-assign-marks.component.html',
  styleUrl: './admin-assign-marks.component.scss'
})
export class AdminAssignMarksComponent {
  private readonly classesService = inject(ClassesService);
  private readonly sectionsService = inject(SectionsService);
  private readonly subjectsService = inject(SubjectsService);
  private readonly examConfigurationsService = inject(ExamConfigurationsService);
  private readonly adminMarksService = inject(AdminMarksService);

  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly subjects = signal<Subject[]>([]);
  readonly teachers = signal<AdminMarksTeacherColumn[]>([]);
  readonly rows = signal<AdminMarksRow[]>([]);
  readonly scope = signal<AdminMarksScope | null>(null);
  readonly examConfigurations = signal<ExamConfiguration[]>([]);
  readonly examConfigurationAcademicYearId = signal<number | null>(null);

  readonly classId = signal<string>('');
  readonly sectionId = signal<string>('');
  readonly subjectId = signal<string>('');
  readonly subjectCode = signal<string>('');
  readonly examConfigurationId = signal<string>('');
  readonly markedOn = signal<string>(new Date().toISOString().slice(0, 10));

  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly finalizing = signal(false);
  readonly downloadingPdf = signal(false);
  readonly downloadingExcel = signal(false);
  readonly isFinalized = signal(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  readonly filteredSubjects = computed(() => {
    const code = this.subjectCode().trim().toLowerCase();
    if (!code) {
      return this.subjects();
    }

    return this.subjects().filter((subject) => {
      const subjectCode = (subject.subject_code || subject.code || '').toLowerCase();
      return subjectCode.includes(code);
    });
  });

  readonly canDownload = computed(() => this.isFinalized() && this.rows().length > 0 && !!this.scope());

  ngOnInit() {
    this.loading.set(true);
    forkJoin({
      classes: this.classesService.list({ per_page: 200 }),
      subjects: this.subjectsService.list({ per_page: 200 })
    }).subscribe({
      next: ({ classes, subjects }) => {
        this.classes.set(classes.data || []);
        this.subjects.set(subjects.data || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load filters.');
      }
    });
  }

  onClassChange(classIdRaw: string) {
    this.classId.set(classIdRaw);
    this.sectionId.set('');
    this.sections.set([]);
    this.rows.set([]);
    this.scope.set(null);
    this.teachers.set([]);
    this.isFinalized.set(false);
    this.examConfigurations.set([]);
    this.examConfigurationAcademicYearId.set(null);
    this.examConfigurationId.set('');

    const classId = Number(classIdRaw);
    if (!classId) {
      return;
    }

    this.sectionsService.list({ class_id: classId, per_page: 200 }).subscribe({
      next: (response) => this.sections.set(response.data || []),
      error: (err) => this.error.set(err?.error?.message || 'Unable to load sections.')
    });
  }

  onSectionChange(sectionIdRaw: string) {
    this.sectionId.set(sectionIdRaw);
    this.rows.set([]);
    this.scope.set(null);
    this.teachers.set([]);
    this.isFinalized.set(false);
    this.examConfigurations.set([]);
    this.examConfigurationAcademicYearId.set(null);
    this.examConfigurationId.set('');
    this.error.set(null);
    this.message.set(null);

    const sectionId = Number(sectionIdRaw);
    if (!sectionId) {
      return;
    }

    const section = this.sections().find((item) => item.id === sectionId);
    const academicYearId = Number(section?.academic_year_id || section?.academicYear?.id || 0);
    if (!academicYearId) {
      this.sectionsService.getById(sectionId).subscribe({
        next: (sectionDetail) => {
          const detailAcademicYearId = Number(sectionDetail?.academic_year_id || sectionDetail?.academicYear?.id || 0);
          if (!detailAcademicYearId) {
            this.error.set('Selected section is missing academic year. Please update section mapping.');
            return;
          }

          this.loadExamConfigurations(detailAcademicYearId);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to resolve section academic year.');
        }
      });
      return;
    }

    this.loadExamConfigurations(academicYearId);
  }

  loadSheet() {
    const sectionId = Number(this.sectionId());
    const subjectId = Number(this.subjectId());
    const subjectCode = this.subjectCode().trim();
    const examConfigurationId = Number(this.examConfigurationId());

    if (!sectionId || (!subjectId && !subjectCode) || !examConfigurationId) {
      this.error.set('Select section, subject, and configured exam.');
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.adminMarksService
      .sheet({
        section_id: sectionId,
        subject_id: subjectId || undefined,
        subject_code: subjectCode || undefined,
        marked_on: this.markedOn() || undefined,
        exam_configuration_id: examConfigurationId
      })
      .subscribe({
        next: (response) => {
          this.scope.set(response.scope);
          this.loadExamConfigurations(Number(response.scope?.academic_year_id || 0));
          this.markedOn.set(response.marked_on);
          this.subjectId.set(String(response.scope.subject_id));
          this.subjectCode.set(response.scope.subject_code || '');
          if (response.scope.exam_configuration_id) {
            this.examConfigurationId.set(String(response.scope.exam_configuration_id));
          }
          this.teachers.set(response.teachers || []);
          this.rows.set((response.rows || []).map((row) => ({ ...row })));
          this.isFinalized.set(!!response.is_finalized);
          this.loading.set(false);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to load marks sheet.');
        }
      });
  }

  saveCompiled() {
    const sectionId = Number(this.sectionId());
    const subjectId = Number(this.subjectId());
    const subjectCode = this.subjectCode().trim();
    const examConfigurationId = Number(this.examConfigurationId());
    if (!sectionId || (!subjectId && !subjectCode) || !examConfigurationId) {
      this.error.set('Select section, subject, and configured exam.');
      return;
    }
    if (this.isFinalized()) {
      this.error.set('This sheet is already finalized.');
      return;
    }
    if (!this.rows().length) {
      this.error.set('No student rows available.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.adminMarksService
      .compile({
        section_id: sectionId,
        subject_id: subjectId || undefined,
        subject_code: subjectCode || undefined,
        marked_on: this.markedOn(),
        exam_configuration_id: examConfigurationId,
        rows: this.rows().map((row) => ({
          enrollment_id: row.enrollment_id,
          marks_obtained: row.compiled_marks_obtained ?? null,
          max_marks: row.compiled_max_marks ?? null,
          remarks: row.compiled_remarks || undefined
        }))
      })
      .subscribe({
        next: (response) => {
          this.saving.set(false);
          this.message.set(response.message || 'Compiled marks saved.');
          this.loadSheet();
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(err?.error?.message || 'Unable to save compiled marks.');
        }
      });
  }

  finalize() {
    const sectionId = Number(this.sectionId());
    const subjectId = Number(this.subjectId());
    const subjectCode = this.subjectCode().trim();
    if (!sectionId || (!subjectId && !subjectCode)) {
      this.error.set('Select section and subject.');
      return;
    }
    const examConfigurationId = Number(this.examConfigurationId());
    if (!examConfigurationId) {
      this.error.set('Select exam from configured exam list.');
      return;
    }

    this.finalizing.set(true);
    this.error.set(null);
    this.message.set(null);

    this.adminMarksService
      .finalize({
        section_id: sectionId,
        subject_id: subjectId || undefined,
        subject_code: subjectCode || undefined,
        marked_on: this.markedOn(),
        exam_configuration_id: examConfigurationId
      })
      .subscribe({
        next: (response) => {
          this.finalizing.set(false);
          this.message.set(response.message || 'Marks finalized.');
          this.loadSheet();
        },
        error: (err) => {
          this.finalizing.set(false);
          this.error.set(err?.error?.message || 'Unable to finalize marks.');
        }
      });
  }

  private loadExamConfigurations(academicYearId: number) {
    if (!academicYearId) {
      this.examConfigurations.set([]);
      this.examConfigurationAcademicYearId.set(null);
      this.examConfigurationId.set('');
      return;
    }

    if (this.examConfigurationAcademicYearId() === academicYearId && this.examConfigurations().length > 0) {
      return;
    }

    const selectedExamId = Number(this.examConfigurationId() || 0);

    this.examConfigurationsService
      .list({ academic_year_id: academicYearId, active_only: true })
      .subscribe({
        next: (response) => {
          const exams = response.data || [];
          this.examConfigurations.set(exams);
          this.examConfigurationAcademicYearId.set(academicYearId);

          const selectedStillExists = selectedExamId > 0 && exams.some((exam) => Number(exam.id) === selectedExamId);
          if (selectedStillExists) {
            return;
          }

          this.examConfigurationId.set(exams.length > 0 ? String(exams[0].id) : '');
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load configured exams.');
          this.examConfigurations.set([]);
          this.examConfigurationAcademicYearId.set(null);
          this.examConfigurationId.set('');
        }
      });
  }

  onCompiledMarksChange(row: AdminMarksRow, value: string | number | null) {
    row.compiled_marks_obtained = this.toNullableNumber(value);
    this.rows.set([...this.rows()]);
  }

  onCompiledMaxChange(row: AdminMarksRow, value: string | number | null) {
    row.compiled_max_marks = this.toNullableNumber(value);
    this.rows.set([...this.rows()]);
  }

  onCompiledRemarksChange(row: AdminMarksRow, value: string) {
    row.compiled_remarks = value;
    this.rows.set([...this.rows()]);
  }

  downloadExcel() {
    if (!this.canDownload()) {
      this.error.set('Finalize marks first to download exports.');
      return;
    }

    const scope = this.scope();
    if (!scope) {
      this.error.set('No marks scope available for export.');
      return;
    }

    this.downloadingExcel.set(true);
    this.error.set(null);

    try {
      const teacherColumns = this.teachers();
      const headers = [
        'Roll Number',
        'Student Name',
        ...teacherColumns.map((teacher) => `${teacher.name} Marks`),
        'Final Marks',
        'Final Max',
        'Percentage',
        'Remarks'
      ];

      const csvRows = this.rows().map((row) => {
        const percentage =
          row.compiled_marks_obtained !== null &&
          row.compiled_marks_obtained !== undefined &&
          row.compiled_max_marks !== null &&
          row.compiled_max_marks !== undefined &&
          row.compiled_max_marks > 0
            ? ((row.compiled_marks_obtained / row.compiled_max_marks) * 100).toFixed(2)
            : '';

        return [
          row.roll_number ?? '',
          row.student_name ?? '',
          ...teacherColumns.map((teacher) => row.teacher_marks[String(teacher.id)]?.marks_obtained ?? ''),
          row.compiled_marks_obtained ?? '',
          row.compiled_max_marks ?? '',
          percentage,
          row.compiled_remarks ?? ''
        ];
      });

      const csv = [headers, ...csvRows]
        .map((line) => line.map((value) => this.escapeCsv(value)).join(','))
        .join('\n');

      const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
      this.downloadBlob(blob, this.buildFileName(scope, 'csv'));
      this.message.set('Marks Excel download started.');
    } catch {
      this.error.set('Unable to generate Excel export.');
    } finally {
      this.downloadingExcel.set(false);
    }
  }

  async downloadPdf() {
    if (!this.canDownload()) {
      this.error.set('Finalize marks first to download exports.');
      return;
    }

    const scope = this.scope();
    if (!scope) {
      this.error.set('No marks scope available for export.');
      return;
    }

    this.downloadingPdf.set(true);
    this.error.set(null);

    try {
      const { jsPDF } = await import('jspdf');
      const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
      const margin = 24;
      const pageWidth = doc.internal.pageSize.getWidth();
      const pageHeight = doc.internal.pageSize.getHeight();
      const usableWidth = pageWidth - margin * 2;
      let y = 28;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(13);
      doc.text('Finalized Marks Sheet', margin, y);
      y += 16;

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9);
      doc.text(
        `Class: ${scope.class_name} | Section: ${scope.section_name} | Subject: ${scope.subject_name} (${scope.subject_code || '-'}) | Date: ${this.markedOn()}`,
        margin,
        y
      );
      y += 10;
      doc.text(`Academic Year: ${scope.academic_year_name}`, margin, y);
      y += 18;

      const columns = [
        { label: 'Roll', width: 48 },
        { label: 'Student', width: 210 },
        { label: 'Final', width: 52 },
        { label: 'Max', width: 52 },
        { label: '%', width: 46 },
        { label: 'Remarks', width: usableWidth - (48 + 210 + 52 + 52 + 46) }
      ];

      const drawHeader = () => {
        let x = margin;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.5);
        columns.forEach((column) => {
          doc.rect(x, y - 10, column.width, 16);
          doc.text(column.label, x + 3, y);
          x += column.width;
        });
        y += 16;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
      };

      drawHeader();

      this.rows().forEach((row) => {
        if (y > pageHeight - 26) {
          doc.addPage();
          y = 30;
          drawHeader();
        }

        const percentage =
          row.compiled_marks_obtained !== null &&
          row.compiled_marks_obtained !== undefined &&
          row.compiled_max_marks !== null &&
          row.compiled_max_marks !== undefined &&
          row.compiled_max_marks > 0
            ? ((row.compiled_marks_obtained / row.compiled_max_marks) * 100).toFixed(2)
            : '-';

        const values = [
          String(row.roll_number ?? '-'),
          String(row.student_name ?? '-').slice(0, 46),
          row.compiled_marks_obtained !== null && row.compiled_marks_obtained !== undefined ? String(row.compiled_marks_obtained) : '-',
          row.compiled_max_marks !== null && row.compiled_max_marks !== undefined ? String(row.compiled_max_marks) : '-',
          percentage,
          String(row.compiled_remarks ?? '-').slice(0, 60)
        ];

        let x = margin;
        values.forEach((value, index) => {
          const width = columns[index].width;
          doc.rect(x, y - 10, width, 16);
          doc.text(value, x + 3, y);
          x += width;
        });

        y += 16;
      });

      doc.save(this.buildFileName(scope, 'pdf'));
      this.message.set('Marks PDF download started.');
    } catch {
      this.error.set('Unable to generate PDF export.');
    } finally {
      this.downloadingPdf.set(false);
    }
  }

  private toNullableNumber(value: string | number | null): number | null {
    if (value === null || value === '') {
      return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }

  private escapeCsv(value: unknown): string {
    const text = String(value ?? '');
    const escaped = text.replace(/"/g, '""');
    return `"${escaped}"`;
  }

  private downloadBlob(blob: Blob, filename: string): void {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    window.URL.revokeObjectURL(url);
  }

  private buildFileName(scope: AdminMarksScope, extension: 'pdf' | 'csv'): string {
    const parts = [
      'marks',
      scope.class_name,
      scope.section_name,
      scope.subject_code || scope.subject_name,
      this.markedOn()
    ];
    const safe = parts
      .join('_')
      .replace(/[^a-zA-Z0-9_-]+/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_|_$/g, '')
      .toLowerCase();

    return `${safe}.${extension}`;
  }
}
