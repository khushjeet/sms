<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StudentDashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\AcademicYearExamConfigController;
use App\Http\Controllers\Api\FeeFinance\FeeStructureController;
use App\Http\Controllers\Api\FeeFinance\FeeHeadController;
use App\Http\Controllers\Api\FeeFinance\FeeInstallmentController;
use App\Http\Controllers\Api\FeeFinance\StudentFeeInstallmentController;
use App\Http\Controllers\Api\FeeFinance\OptionalServiceController;
use App\Http\Controllers\Api\FeeFinance\ReceiptController;
use App\Http\Controllers\Api\FeeFinance\LedgerController;
use App\Http\Controllers\Api\FeeFinance\FinancialHoldController;
use App\Http\Controllers\Api\Transport\TransportRouteController;
use App\Http\Controllers\Api\Transport\TransportStopController;
use App\Http\Controllers\Api\Transport\TransportAssignmentController;
use App\Http\Controllers\Api\Transport\TransportFeeCycleController;
use App\Http\Controllers\Api\FeeFinance\FeeAssignmentController;
use App\Http\Controllers\Api\FeeFinance\TransportChargeController;
use App\Http\Controllers\Api\FeeFinance\PaymentController;
use App\Http\Controllers\Api\FeeFinance\FinanceReportController;
use App\Http\Controllers\Api\FeeFinance\HostelFeeController;
use App\Http\Controllers\Api\Expenses\ExpenseController;
use App\Http\Controllers\Api\HrPayrollController;
use App\Http\Controllers\Api\TeacherAcademicController;
use App\Http\Controllers\Api\AdminMarksController;
use App\Http\Controllers\Api\PublicResultVerificationController;
use App\Http\Controllers\Api\ResultPublishingController;
use App\Http\Controllers\Api\AdmitCardController;
use App\Http\Controllers\Api\SchoolSignatureController;
use App\Http\Controllers\Api\SchoolDetailsController;
use App\Http\Controllers\Api\SchoolCredentialController;
use App\Http\Controllers\Api\MessageCenterController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TimetableController;
use App\Http\Controllers\Api\AuditDownloadController;
use App\Http\Controllers\Api\SchoolEventController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Public result verification endpoint (QR target)
    Route::get('/public/results/verify', [PublicResultVerificationController::class, 'verify']);
    Route::get('/public/admits/verify', [AdmitCardController::class, 'verifyPublic']);

    // Auth routes (public - no authentication required)
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store']);
    Route::post('/reset-password', [NewPasswordController::class, 'store']);

    // Protected auth routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/revoke-all-tokens', [AuthController::class, 'revokeAllTokens']);
    });

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {

        // User profile
        Route::get('/user', [AuthController::class, 'user']);

        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::get('/recent', [NotificationController::class, 'recent']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllRead']);
            Route::post('/{id}/read', [NotificationController::class, 'markRead']);
        });

        // Dashboards
        Route::prefix('dashboard')->group(function () {
            Route::get('/super-admin', [DashboardController::class, 'superAdmin'])
                ->middleware('permission:system.manage');
            Route::get('/school-admin', [DashboardController::class, 'schoolAdmin'])
                ->middleware('permission:reports.view');
            Route::get('/student', [StudentDashboardController::class, 'show']);
            Route::get('/notifications', [DashboardController::class, 'notifications']);
            Route::get('/self-attendance/status', [DashboardController::class, 'selfAttendanceStatus']);
            Route::post('/self-attendance/mark', [DashboardController::class, 'markSelfAttendance']);
        });

        // Student Management (SRS: Role-based access)
        Route::prefix('students')->group(function () {
            Route::get('/', [StudentController::class, 'index'])->middleware('module:students');
            Route::post('/', [StudentController::class, 'store'])->middleware('permission:students.manage');
            Route::get('/logo', [StudentController::class, 'logo'])->middleware('module:students');
            Route::get('/{id}', [StudentController::class, 'show'])->middleware('module:students');
            Route::get('/{id}/pdf', [StudentController::class, 'downloadPdf'])->middleware('module:students');
            Route::put('/{id}', [StudentController::class, 'update'])->middleware('permission:students.manage');
            Route::delete('/{id}', [StudentController::class, 'destroy'])->middleware('permission:students.manage');
            Route::get('/{id}/avatar', [StudentController::class, 'avatar'])->middleware('module:students');
            Route::get('/{id}/academic-history', [StudentController::class, 'academicHistory'])->middleware('module:students');
            Route::get('/{id}/financial-summary', [StudentController::class, 'financialSummary'])->middleware('module:finance');
        });

        Route::prefix('school')->group(function () {
            Route::get('/details', [SchoolDetailsController::class, 'show'])->middleware('permission:system.manage');
            Route::put('/details', [SchoolDetailsController::class, 'update'])->middleware('permission:system.manage');
            Route::get('/credentials', [SchoolCredentialController::class, 'show'])->middleware('permission:system.manage');
            Route::get('/credentials/status', [SchoolCredentialController::class, 'status'])->middleware('permission:system.manage');
            Route::put('/credentials', [SchoolCredentialController::class, 'update'])->middleware('permission:system.manage');
            Route::post('/credentials/test', [SchoolCredentialController::class, 'test'])->middleware('permission:system.manage');
            Route::get('/signatures', [SchoolSignatureController::class, 'show'])->middleware('permission:system.manage');
            Route::post('/signatures', [SchoolSignatureController::class, 'update'])->middleware('permission:system.manage');
            Route::delete('/signatures/{slot}', [SchoolSignatureController::class, 'destroy'])->middleware('permission:system.manage');
        });

        Route::prefix('message-center')->middleware('permission:system.manage')->group(function () {
            Route::post('/send', [MessageCenterController::class, 'send']);
            Route::get('/status/{batchId}', [MessageCenterController::class, 'status']);
            Route::get('/birthday-settings', [MessageCenterController::class, 'birthdaySettings']);
            Route::put('/birthday-settings', [MessageCenterController::class, 'saveBirthdaySettings']);
        });

        Route::prefix('events')->middleware('permission:system.manage')->group(function () {
            Route::get('/', [SchoolEventController::class, 'index']);
            Route::post('/', [SchoolEventController::class, 'store']);
            Route::get('/{id}', [SchoolEventController::class, 'show']);
            Route::put('/{id}', [SchoolEventController::class, 'update']);
            Route::delete('/{id}', [SchoolEventController::class, 'destroy']);
            Route::put('/{id}/participants', [SchoolEventController::class, 'syncParticipants']);
            Route::get('/participants/{participantId}/certificate', [SchoolEventController::class, 'certificatePdf']);
        });

        // Teacher Management
        Route::prefix('teachers')->group(function () {
            Route::get('/', [TeacherController::class, 'index'])->middleware('module:staff');
            Route::post('/', [TeacherController::class, 'store'])->middleware('permission:staff.manage');
            Route::get('/{id}', [TeacherController::class, 'show'])->middleware('module:staff');
            Route::put('/{id}', [TeacherController::class, 'update'])->middleware('permission:staff.manage');
            Route::delete('/{id}', [TeacherController::class, 'destroy'])->middleware('permission:staff.manage');
            Route::post('/{id}/documents', [TeacherController::class, 'uploadDocument'])->middleware('permission:staff.manage');
            Route::get('/{id}/documents/{documentId}/file', [TeacherController::class, 'documentFile'])->middleware('module:staff');
        });

        // Employee Management
        Route::prefix('employees')->group(function () {
            Route::get('/metadata', [EmployeeController::class, 'metadata'])->middleware('module:staff');
            Route::get('/', [EmployeeController::class, 'index'])->middleware('module:staff');
            Route::post('/', [EmployeeController::class, 'store'])->middleware('permission:staff.manage');
            Route::get('/{id}', [EmployeeController::class, 'show'])->middleware('module:staff');
            Route::put('/{id}', [EmployeeController::class, 'update'])->middleware('permission:staff.manage');
            Route::delete('/{id}', [EmployeeController::class, 'destroy'])->middleware('permission:staff.manage');
            Route::post('/{id}/documents', [EmployeeController::class, 'uploadDocument'])->middleware('permission:staff.manage');
            Route::get('/{id}/documents/{documentId}/file', [EmployeeController::class, 'documentFile'])->middleware('module:staff');
            Route::get('/{id}/attendance-history', [EmployeeController::class, 'attendanceHistory'])->middleware('module:staff');
            Route::get('/{id}/attendance-history/download', [EmployeeController::class, 'attendanceHistoryDownload'])->middleware('module:staff');
            Route::get('/{id}/payout-history', [EmployeeController::class, 'payoutHistory'])->middleware('module:staff');
            Route::get('/{id}/payout-history/download', [EmployeeController::class, 'payoutHistoryDownload'])->middleware('module:staff');
        });

        // HR + Payroll Durability APIs
        Route::prefix('hr')->group(function () {
            Route::post('/attendance/mark', [HrPayrollController::class, 'markAttendance'])->middleware('permission:attendance.mark');
            Route::post('/attendance/lock-month', [HrPayrollController::class, 'lockAttendanceMonth'])->middleware('permission:payroll.edit');
            Route::post('/attendance/unlock-month', [HrPayrollController::class, 'unlockAttendanceMonth'])->middleware('permission:payroll.edit');
            Route::get('/attendance/selfie-daily', [HrPayrollController::class, 'dailySelfieAttendance'])->middleware('module:staff');
            Route::post('/attendance/selfie/{sessionId}/approve', [HrPayrollController::class, 'approveSelfieAttendance'])->middleware('module:staff');

            Route::get('/leave/types', [HrPayrollController::class, 'leaveTypes'])->middleware('module:staff');
            Route::get('/leave/requests', [HrPayrollController::class, 'leaveRequests'])->middleware('module:staff');
            Route::post('/leave/requests', [HrPayrollController::class, 'createLeaveRequest'])->middleware('module:staff');
            Route::post('/leave/requests/{leaveId}/decision', [HrPayrollController::class, 'decideLeaveRequest'])->middleware('permission:staff.manage');
            Route::post('/leave/ledger', [HrPayrollController::class, 'postLeaveLedgerEntry'])->middleware('permission:staff.manage');
            Route::get('/leave/balance/{staffId}', [HrPayrollController::class, 'leaveBalance'])->middleware('module:staff');

            Route::get('/salary/templates', [HrPayrollController::class, 'listSalaryTemplates'])->middleware('module:staff');
            Route::post('/salary/templates', [HrPayrollController::class, 'createSalaryTemplate'])->middleware('permission:payroll.edit');
            Route::post('/salary/assignments', [HrPayrollController::class, 'assignSalaryStructure'])->middleware('permission:payroll.edit');

            Route::get('/payroll', [HrPayrollController::class, 'listPayrollBatches'])->middleware('permission:payroll.view');
            Route::get('/payroll/period-options', [HrPayrollController::class, 'payrollPeriodOptions'])->middleware('permission:payroll.view');
            Route::post('/payroll/generate', [HrPayrollController::class, 'generatePayroll'])->middleware('permission:payroll.edit');
            Route::post('/payroll/{batchId}/finalize', [HrPayrollController::class, 'finalizePayroll'])->middleware('permission:payroll.edit');
            Route::post('/payroll/{batchId}/mark-paid', [HrPayrollController::class, 'markPayrollPaid'])->middleware('permission:payroll.edit');
            Route::post('/payroll/{batchId}/items/{itemId}/adjustments', [HrPayrollController::class, 'addPayrollAdjustment'])->middleware('permission:payroll.edit');
            Route::get('/payroll/{batchId}', [HrPayrollController::class, 'showPayrollBatch'])->middleware('permission:payroll.view');
        });

        // Enrollment Management (SRS: Role-based access)
        Route::prefix('enrollments')->group(function () {
            Route::get('/', [EnrollmentController::class, 'index'])->middleware('module:academic');
            Route::post('/', [EnrollmentController::class, 'store'])->middleware('permission:academic.manage');
            Route::get('/{id}', [EnrollmentController::class, 'show'])->middleware('module:academic');
            Route::get('/{id}/academic-history', [EnrollmentController::class, 'academicHistory'])->middleware('module:academic'); // Get full academic history chain
            Route::put('/{id}', [EnrollmentController::class, 'update'])->middleware('permission:academic.manage');
            Route::post('/{id}/promote', [EnrollmentController::class, 'promote'])->middleware('permission:academic.manage');
            Route::post('/{id}/repeat', [EnrollmentController::class, 'repeat'])->middleware('permission:academic.manage');
            Route::post('/{id}/transfer', [EnrollmentController::class, 'transfer'])->middleware('permission:academic.manage');
        });

        // Attendance Management (SRS: Role-based access - Teachers can mark, Admin can view all)
        Route::prefix('attendance')->group(function () {
            Route::post('/mark', [AttendanceController::class, 'markAttendance'])->middleware('permission:attendance.mark');
            Route::get('/section', [AttendanceController::class, 'getSectionAttendance'])->middleware('module:attendance');
            Route::get('/student/{studentId}', [AttendanceController::class, 'getStudentAttendance'])->middleware('module:attendance');
            Route::get('/section/statistics', [AttendanceController::class, 'getSectionStatistics'])->middleware('module:attendance');
            Route::get('/reports/search', [AttendanceController::class, 'searchStudentsForReports'])->middleware('module:attendance');
            Route::get('/reports/live-search', [AttendanceController::class, 'liveSearchStudentsOrEnrollments'])->middleware('module:attendance');
            Route::get('/reports/monthly/download', [AttendanceController::class, 'downloadMonthlyAttendanceReport'])->middleware('module:attendance');
            Route::get('/reports/session/download', [AttendanceController::class, 'downloadSessionWiseAttendanceReport'])->middleware('module:attendance');
            Route::get('/reports/bulk/monthly', [AttendanceController::class, 'getBulkMonthlyAttendanceData'])->middleware('module:attendance');
            Route::get('/reports/bulk/monthly/download', [AttendanceController::class, 'downloadBulkMonthlyAttendanceExcel'])->middleware('module:attendance');
            Route::post('/lock', [AttendanceController::class, 'lockAttendance'])->middleware('permission:academic.manage');
        });

        // Academic Year Management (SRS Section 12.1: Academic Year Transition)
        Route::prefix('academic-years')->group(function () {
            Route::get('/current', [AcademicYearController::class, 'current']);
            Route::get('/', [AcademicYearController::class, 'index'])->middleware('module:academic');
            Route::post('/', [AcademicYearController::class, 'store'])->middleware('permission:academic.manage');
            Route::get('/{id}', [AcademicYearController::class, 'show'])->middleware('module:academic');
            Route::put('/{id}', [AcademicYearController::class, 'update'])->middleware('permission:academic.manage');
            Route::delete('/{id}', [AcademicYearController::class, 'destroy'])->middleware('permission:academic.manage');
            Route::post('/{id}/set-current', [AcademicYearController::class, 'setCurrent'])->middleware('permission:academic.manage');
            Route::post('/{id}/close', [AcademicYearController::class, 'close'])->middleware('permission:academic.manage');
        });

        Route::prefix('exam-configurations')->group(function () {
            Route::get('/', [AcademicYearExamConfigController::class, 'index'])->middleware('permission:system.manage');
            Route::post('/', [AcademicYearExamConfigController::class, 'store'])->middleware('permission:system.manage');
            Route::put('/{id}', [AcademicYearExamConfigController::class, 'update'])->middleware('permission:system.manage');
            Route::delete('/{id}', [AcademicYearExamConfigController::class, 'destroy'])->middleware('permission:system.manage');
        });

        // Class Management (SRS: Academic Structure)
        Route::prefix('classes')->group(function () {
            Route::get('/', [ClassController::class, 'index'])->middleware('module:academic');
            Route::post('/', [ClassController::class, 'store'])->middleware('permission:academic.manage');
            Route::get('/{id}', [ClassController::class, 'show'])->middleware('module:academic');
            Route::put('/{id}', [ClassController::class, 'update'])->middleware('permission:academic.manage');
            Route::delete('/{id}', [ClassController::class, 'destroy'])->middleware('permission:academic.manage');
        });

        // Section Management (SRS: Academic Structure)
        Route::prefix('sections')->group(function () {
            Route::get('/', [SectionController::class, 'index'])->middleware('module:academic');
            Route::post('/', [SectionController::class, 'store'])->middleware('permission:academic.manage');
            Route::get('/{id}', [SectionController::class, 'show'])->middleware('module:academic');
            Route::put('/{id}', [SectionController::class, 'update'])->middleware('permission:academic.manage');
            Route::delete('/{id}', [SectionController::class, 'destroy'])->middleware('permission:academic.manage');
        });

        // Subject Management (SRS: Academic Structure)
        Route::prefix('subjects')->group(function () {
            Route::get('/', [SubjectController::class, 'index'])->middleware('module:academic');
            Route::post('/', [SubjectController::class, 'store'])->middleware('permission:academic.manage');
            Route::get('/{id}', [SubjectController::class, 'show'])->middleware('module:academic');
            Route::put('/{id}', [SubjectController::class, 'update'])->middleware('permission:academic.manage');
            Route::delete('/{id}', [SubjectController::class, 'destroy'])->middleware('permission:academic.manage');
            Route::post('/{id}/class-mappings', [SubjectController::class, 'storeClassMapping'])->middleware('permission:academic.manage');
            Route::delete('/{id}/class-mappings/{classId}/{academicYearId}', [SubjectController::class, 'destroyClassMapping'])->middleware('permission:academic.manage');
            Route::get('/{id}/teacher-assignments', [SubjectController::class, 'teacherAssignments'])->middleware('module:academic');
            Route::post('/{id}/teacher-assignments', [SubjectController::class, 'storeTeacherAssignments'])->middleware('permission:academic.manage');
            Route::delete('/{id}/teacher-assignments/{assignmentId}', [SubjectController::class, 'destroyTeacherAssignment'])->middleware('permission:academic.manage');
        });

        Route::prefix('timetable')->middleware('permission:system.manage')->group(function () {
            Route::get('/time-slots', [TimetableController::class, 'timeSlots']);
            Route::post('/time-slots', [TimetableController::class, 'storeTimeSlot']);
            Route::put('/time-slots/{id}', [TimetableController::class, 'updateTimeSlot']);
            Route::delete('/time-slots/{id}', [TimetableController::class, 'destroyTimeSlot']);
            Route::get('/section', [TimetableController::class, 'getSectionTimetable']);
            Route::get('/section/download', [TimetableController::class, 'downloadSectionTimetablePdf']);
            Route::post('/section', [TimetableController::class, 'saveSectionTimetable']);
        });

        Route::prefix('timetable')->group(function () {
            Route::get('/student/me', [TimetableController::class, 'studentTimetable'])->middleware('permission:student.view_timetable');
            Route::get('/student/me/download', [TimetableController::class, 'downloadStudentTimetablePdf'])->middleware('permission:student.view_timetable');
        });

        // Teacher academic operations (assigned subject/section scoped)
        Route::prefix('teacher-academics')->group(function () {
            Route::get('/assignments', [TeacherAcademicController::class, 'assignments'])->middleware('module:academic');
            Route::get('/timetable', [TimetableController::class, 'teacherTimetable'])->middleware('module:academic');
            Route::get('/attendance-sheet', [TeacherAcademicController::class, 'attendanceSheet'])->middleware('module:attendance');
            Route::post('/attendance', [TeacherAcademicController::class, 'saveAttendance'])->middleware('module:attendance');
            Route::get('/marks-sheet', [TeacherAcademicController::class, 'marksSheet'])->middleware('module:academic');
            Route::post('/marks', [TeacherAcademicController::class, 'saveMarks'])->middleware('module:academic');
        });

        Route::prefix('admin-marks')->group(function () {
            Route::get('/filters', [AdminMarksController::class, 'filters'])->middleware('permission:system.manage');
            Route::get('/sheet', [AdminMarksController::class, 'sheet'])->middleware('permission:system.manage');
            Route::post('/compile', [AdminMarksController::class, 'compile'])->middleware('permission:system.manage');
            Route::post('/finalize', [AdminMarksController::class, 'finalize'])->middleware('permission:system.manage');
        });

        Route::prefix('audit-downloads')->group(function () {
            Route::get('/catalog', [AuditDownloadController::class, 'catalog']);
            Route::get('/logs', [AuditDownloadController::class, 'logs']);
            Route::get('/logs/export', [AuditDownloadController::class, 'exportCsv']);
            Route::get('/logs/archive', [AuditDownloadController::class, 'archive']);
            Route::post('/logs', [AuditDownloadController::class, 'store']);
        });

        Route::prefix('results')->group(function () {
            Route::get('/published/sessions', [ResultPublishingController::class, 'publishedSessionOptions'])
                ->middleware('permission:student.view_result,portal.parent.view,academic.view');
            Route::get('/sessions', [ResultPublishingController::class, 'sessions'])->middleware('permission:system.manage');
            Route::post('/sessions', [ResultPublishingController::class, 'createSession'])->middleware('permission:system.manage');
            Route::get('/published', [ResultPublishingController::class, 'publishedResults'])
                ->middleware('permission:student.view_result,portal.parent.view,academic.view');
            Route::get('/{studentResultId}/paper', [ResultPublishingController::class, 'resultPaper'])
                ->name('results.paper')
                ->middleware('permission:student.view_result,portal.parent.view,academic.view');
            Route::post('/publish', [ResultPublishingController::class, 'publish'])->middleware('permission:system.manage');
            Route::post('/publish/class-wise', [ResultPublishingController::class, 'publishClassWise'])->middleware('permission:system.manage');
            Route::post('/sessions/{sessionId}/lock', [ResultPublishingController::class, 'lockSession'])->middleware('permission:system.manage');
            Route::post('/sessions/{sessionId}/unlock', [ResultPublishingController::class, 'unlockSession'])->middleware('permission:system.manage');
            Route::post('/{studentResultId}/visibility', [ResultPublishingController::class, 'setVisibility'])->middleware('permission:system.manage');
            Route::post('/{studentResultId}/verification/revoke', [ResultPublishingController::class, 'revokeVerification'])->middleware('permission:system.manage');
        });

        Route::prefix('admits')->group(function () {
            Route::get('/me', [AdmitCardController::class, 'myLatest'])->middleware('permission:student.view_admit_card');
            Route::get('/{admitCardId}/paper', [AdmitCardController::class, 'paper'])
                ->name('admit.cards.paper')
                ->middleware('permission:student.view_admit_card,admit.view');

            Route::get('/sessions', [AdmitCardController::class, 'sessions'])->middleware('permission:admit.view');
            Route::get('/sessions/{sessionId}/cards', [AdmitCardController::class, 'sessionCards'])->middleware('permission:admit.view');
            Route::get('/sessions/{sessionId}/paper', [AdmitCardController::class, 'bulkPaper'])->middleware('permission:admit.view');
            Route::get('/{admitCardId}/paper/download', [AdmitCardController::class, 'paperPdf'])
                ->name('admit.cards.paper.download')
                ->middleware('permission:student.view_admit_card,admit.view');
            Route::post('/generate', [AdmitCardController::class, 'generate'])->middleware('permission:admit.generate');
            Route::post('/sessions/{sessionId}/publish', [AdmitCardController::class, 'publishSession'])->middleware('permission:admit.publish');
            Route::post('/{admitCardId}/visibility', [AdmitCardController::class, 'setVisibility'])->middleware('permission:admit.manage_visibility');
        });

        Route::prefix('transport')
            ->middleware('module:finance')
            ->group(function () {
                Route::get('routes', [TransportRouteController::class, 'index'])
                    ->middleware('permission:finance.view');
                Route::post('routes', [TransportRouteController::class, 'store'])
                    ->middleware('permission:finance.manage');

                Route::get('stops', [TransportStopController::class, 'index'])
                    ->middleware('permission:finance.view');
                Route::post('stops', [TransportStopController::class, 'store'])
                    ->middleware('permission:finance.manage');

                Route::post('assignments', [TransportAssignmentController::class, 'store'])
                    ->middleware('permission:finance.manage');
                Route::post('assignments/bulk', [TransportAssignmentController::class, 'bulkStore'])
                    ->middleware('permission:finance.manage');
                Route::get('assignments', [TransportAssignmentController::class, 'index'])
                    ->middleware('permission:finance.view');
                Route::post('assignments/{id}/stop', [TransportAssignmentController::class, 'stop'])
                    ->middleware('permission:finance.manage');

                Route::post('fee-cycles/generate', [TransportFeeCycleController::class, 'generate'])
                    ->middleware('permission:finance.manage');
            });



        Route::middleware(['auth:sanctum'])->group(function () {

            Route::prefix('finance')
                ->middleware('module:finance')
                ->group(function () {

                    /*
                    |--------------------------------------------------------------------------
                    | SUPER ADMIN ONLY
                    | System-level financial configuration
                    |--------------------------------------------------------------------------
                    */
                    Route::middleware('permission:system.manage')->group(function () {

                        Route::prefix('fee-structures')
                            ->controller(FeeStructureController::class)
                            ->group(function () {
                                Route::get('/', 'index');
                                Route::post('/', 'store');
                                Route::get('{id}', 'show');
                                Route::put('{id}', 'update');
                            });

                        Route::prefix('fee-heads')
                            ->controller(FeeHeadController::class)
                            ->group(function () {
                                Route::post('/', 'store');
                                Route::put('{id}', 'update');
                            });

                        Route::prefix('installments')
                            ->controller(FeeInstallmentController::class)
                            ->group(function () {
                                Route::post('/', 'store');
                                Route::put('{id}', 'update');
                            });

                        Route::prefix('optional-services')
                            ->controller(OptionalServiceController::class)
                            ->group(function () {
                                Route::get('/', 'index');
                                Route::post('/', 'store');
                                Route::put('{id}', 'update');
                            });

                        Route::prefix('hostel-fees')
                            ->controller(HostelFeeController::class)
                            ->group(function () {
                                Route::get('/', 'index');
                                Route::post('/', 'store');
                                Route::put('{id}', 'update');
                            });
                    });

                    /*
                    |--------------------------------------------------------------------------
                    | SUPER ADMIN + SCHOOL ADMIN
                    | Academic + operational finance control
                    |--------------------------------------------------------------------------
                    */
                    Route::middleware('permission:finance.manage')->group(function () {

                        Route::get(
                            'fee-assignments/enrollment/{id}',
                            [FeeAssignmentController::class, 'byEnrollment']
                        );

                        Route::get(
                            'fee-assignments/{id}/summary',
                            [FeeAssignmentController::class, 'summary']
                        );

                        Route::post(
                            'fee-assignments/enrollment/{id}/discount',
                            [FeeAssignmentController::class, 'applyDiscount']
                        );

                        Route::post(
                            'students/{id}/installments',
                            [StudentFeeInstallmentController::class, 'store']
                        );
                        Route::post(
                            'enrollments/{id}/installments',
                            [StudentFeeInstallmentController::class, 'storeByEnrollment']
                        );
                        Route::post(
                            'installments/assign-to-class',
                            [StudentFeeInstallmentController::class, 'assignToClass']
                        );

                        Route::post(
                            'fee-assignments/enrollment/{id}',
                            [FeeAssignmentController::class, 'assign']
                        );

                        Route::post(
                            'expenses',
                            [ExpenseController::class, 'store']
                        );

                        Route::post(
                            'expenses/{id}/reverse',
                            [ExpenseController::class, 'reverse']
                        );

                    });

                    /*
                    |--------------------------------------------------------------------------
                    | SUPER ADMIN + SCHOOL ADMIN + ACCOUNTANT
                    | Read-only finance + payments
                    |--------------------------------------------------------------------------
                    */
                    Route::middleware('permission:finance.view')->group(function () {

                        Route::get(
                            'transport-charges/enrollment/{id}',
                            [TransportChargeController::class, 'byEnrollment']
                        );

                        Route::get(
                            'fee-heads',
                            [FeeHeadController::class, 'index']
                        );

                        Route::get(
                            'installments',
                            [FeeInstallmentController::class, 'index']
                        );

                        Route::get(
                            'students/{id}/installments',
                            [StudentFeeInstallmentController::class, 'index']
                        );

                        Route::post(
                            'payments',
                            [PaymentController::class, 'store']
                        )->middleware('permission:finance.manage');

                        Route::get(
                            'payments/enrollment/{id}',
                            [PaymentController::class, 'byEnrollment']
                        );

                        Route::get(
                            'payments/{id}/receipt',
                            [PaymentController::class, 'receipt']
                        );
                        Route::get(
                            'payments/{id}/receipt-html',
                            [PaymentController::class, 'receiptHtml']
                        );

                        Route::post(
                            'payments/{id}/refund',
                            [PaymentController::class, 'refund']
                        )->middleware('permission:finance.manage');

                        Route::get(
                            'payments/enrollment/{id}/receipt',
                            [PaymentController::class, 'unifiedReceipt']
                        );

                        Route::post(
                            'receipts',
                            [ReceiptController::class, 'store']
                        )->middleware('permission:finance.manage');

                        Route::get(
                            'students/{id}/ledger',
                            [LedgerController::class, 'byStudent']
                        );
                        Route::get(
                            'students/{id}/ledger/download',
                            [LedgerController::class, 'downloadStudentLedger']
                        );
                        Route::get(
                            'classes/{id}/ledger',
                            [LedgerController::class, 'classLedger']
                        );
                        Route::get(
                            'classes/{id}/ledger/statements',
                            [LedgerController::class, 'classLedgerStatements']
                        );
                        Route::get(
                            'classes/{id}/ledger/download',
                            [LedgerController::class, 'downloadClassLedger']
                        );

                        Route::get(
                            'enrollments/{id}/ledger',
                            [LedgerController::class, 'byEnrollment']
                        );

                        Route::get(
                            'students/{id}/balance',
                            [LedgerController::class, 'balance']
                        );

                        Route::get(
                            'enrollments/{id}/balance',
                            [LedgerController::class, 'balanceByEnrollment']
                        );

                        Route::post(
                            'ledger/{id}/reverse',
                            [LedgerController::class, 'reverse']
                        )->middleware('permission:finance.manage');

                        Route::post(
                            'enrollments/{id}/special-fee',
                            [LedgerController::class, 'postSpecialFee']
                        )->middleware('permission:finance.manage');

                        Route::get(
                            'holds',
                            [FinancialHoldController::class, 'index']
                        );

                        Route::post(
                            'holds',
                            [FinancialHoldController::class, 'store']
                        )->middleware('permission:finance.manage');

                        Route::put(
                            'holds/{id}',
                            [FinancialHoldController::class, 'update']
                        )->middleware('permission:finance.manage');

                        Route::prefix('reports')->group(function () {
                            Route::get('fees/due', [FinanceReportController::class, 'due']);
                            Route::get('fees/collection', [FinanceReportController::class, 'collection']);
                            Route::get('transport/route-wise', [FinanceReportController::class, 'routeWise']);
                            Route::get('expenses/audit', [ExpenseController::class, 'report']);
                            Route::get('expenses/entries/download', [ExpenseController::class, 'downloadEntries']);
                        });

                        Route::get(
                            'expenses',
                            [ExpenseController::class, 'index']
                        );

                        Route::get(
                            'expenses/receipts/{id}/file',
                            [ExpenseController::class, 'file']
                        );

                        Route::post(
                            'expenses/{id}/receipts',
                            [ExpenseController::class, 'uploadReceipt']
                        )->middleware('permission:finance.manage');
                    });

                });
        });


        // Exam Management
        // Route::prefix('exams')->group(function () {
        //     Route::get('/', [ExamController::class, 'index']);
        //     Route::post('/', [ExamController::class, 'store']);
        //     Route::get('/{id}', [ExamController::class, 'show']);
        //     Route::put('/{id}', [ExamController::class, 'update']);
        //     Route::delete('/{id}', [ExamController::class, 'destroy']);
        //     Route::post('/{id}/schedule', [ExamController::class, 'createSchedule']);
        // });

        // Result Management
        // Route::prefix('results')->group(function () {
        //     Route::post('/enter', [ResultController::class, 'enterMarks']);
        //     Route::get('/exam/{examId}/class/{classId}', [ResultController::class, 'getClassResults']);
        //     Route::get('/student/{studentId}', [ResultController::class, 'getStudentResults']);
        //     Route::get('/enrollment/{enrollmentId}/report-card', [ResultController::class, 'generateReportCard']);
        // });

        // Fee Management
        // Route::prefix('fees')->group(function () {
        //     Route::apiResource('structures', FeeStructureController::class);
        //     Route::apiResource('optional-services', OptionalServiceController::class);
        //     Route::post('/assignments/{enrollmentId}', [FeeController::class, 'updateFeeAssignment']);
        //     Route::get('/assignments/{enrollmentId}', [FeeController::class, 'getFeeAssignment']);
        // });

        // Payment Management
        // Route::prefix('payments')->group(function () {
        //     Route::post('/', [PaymentController::class, 'store']);
        //     Route::get('/enrollment/{enrollmentId}', [PaymentController::class, 'getEnrollmentPayments']);
        //     Route::get('/receipt/{receiptNumber}', [PaymentController::class, 'getReceipt']);
        // });

        // Staff Management
        // Route::prefix('staff')->group(function () {
        //     Route::get('/', [StaffController::class, 'index']);
        //     Route::post('/', [StaffController::class, 'store']);
        //     Route::get('/{id}', [StaffController::class, 'show']);
        //     Route::put('/{id}', [StaffController::class, 'update']);
        //     Route::delete('/{id}', [StaffController::class, 'destroy']);
        //     Route::post('/{id}/attendance', [StaffController::class, 'markAttendance']);
        //     Route::post('/{id}/leave', [StaffController::class, 'applyLeave']);
        // });

        // Timetable Management
        // Route::prefix('timetable')->group(function () {
        //     Route::get('/section/{sectionId}', [TimetableController::class, 'getSectionTimetable']);
        //     Route::get('/teacher/{teacherId}', [TimetableController::class, 'getTeacherTimetable']);
        //     Route::post('/', [TimetableController::class, 'store']);
        //     Route::put('/{id}', [TimetableController::class, 'update']);
        //     Route::delete('/{id}', [TimetableController::class, 'destroy']);
        // });

        // Library Management
        // Route::prefix('library')->group(function () {
        //     Route::apiResource('books', BookController::class);
        //     Route::post('/issue', [LibraryController::class, 'issueBook']);
        //     Route::post('/return', [LibraryController::class, 'returnBook']);
        //     Route::get('/student/{studentId}/issued', [LibraryController::class, 'getStudentIssuedBooks']);
        // });

        // Transport Management
        // Route::prefix('transport')->group(function () {
        //     Route::apiResource('routes', TransportRouteController::class);
        //     Route::apiResource('stops', TransportStopController::class);
        //     Route::post('/assign', [TransportController::class, 'assignStudent']);
        //     Route::delete('/unassign/{studentId}', [TransportController::class, 'unassignStudent']);
        // });

        // Notifications
        // Route::prefix('notifications')->group(function () {
        //     Route::get('/', [NotificationController::class, 'index']);
        //     Route::post('/', [NotificationController::class, 'store']);
        //     Route::post('/{id}/send', [NotificationController::class, 'send']);
        //     Route::post('/{id}/mark-read', [NotificationController::class, 'markAsRead']);
        // });

        // Reports & Analytics
        // Route::prefix('reports')->group(function () {
        //     Route::get('/enrollment-statistics', [ReportController::class, 'enrollmentStatistics']);
        //     Route::get('/fee-collection', [ReportController::class, 'feeCollection']);
        //     Route::get('/attendance-summary', [ReportController::class, 'attendanceSummary']);
        //     Route::get('/academic-performance', [ReportController::class, 'academicPerformance']);
        // });
    });
});

