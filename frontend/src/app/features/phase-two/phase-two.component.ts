import { Component } from '@angular/core';
import { NgFor } from '@angular/common';

@Component({
  selector: 'app-phase-two',
  standalone: true,
  imports: [NgFor],
  templateUrl: './phase-two.component.html',
  styleUrl: './phase-two.component.scss'
})
export class PhaseTwoComponent {
  readonly items = [
    'Yearly audit archive packs for academic, finance, attendance, HR, and admissions',
    'Server-side signed PDF or ZIP bundles for long-term evidence retention',
    'Checksum-backed export verification for more modules',
    'Extended audit coverage for attendance, expenses, HR payroll, and documents',
    'Retention-oriented archive workflows for 20-30 year preservation',
  ];
}
