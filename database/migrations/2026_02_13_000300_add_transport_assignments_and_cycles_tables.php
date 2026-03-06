<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_routes', function (Blueprint $table) {
            if (!Schema::hasColumn('transport_routes', 'vehicle_number')) {
                $table->string('vehicle_number')->nullable()->after('route_name');
            }
            if (!Schema::hasColumn('transport_routes', 'driver_name')) {
                $table->string('driver_name')->nullable()->after('vehicle_number');
            }
            if (!Schema::hasColumn('transport_routes', 'active')) {
                $table->boolean('active')->default(true)->after('status');
            }
        });

        Schema::table('transport_stops', function (Blueprint $table) {
            if (!Schema::hasColumn('transport_stops', 'distance_km')) {
                $table->decimal('distance_km', 8, 2)->nullable()->after('stop_name');
            }
            if (!Schema::hasColumn('transport_stops', 'active')) {
                $table->boolean('active')->default(true)->after('stop_order');
            }
        });

        if (!Schema::hasTable('student_transport_assignments')) {
            Schema::create('student_transport_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
                $table->foreignId('route_id')->constrained('transport_routes')->restrictOnDelete();
                $table->foreignId('stop_id')->constrained('transport_stops')->restrictOnDelete();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->enum('status', ['active', 'stopped'])->default('active');
                $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();

                $table->index(['enrollment_id', 'status'], 'sta_enrollment_status_idx');
            });
        } else {
            if (!Schema::hasColumn('student_transport_assignments', 'enrollment_id')) {
                Schema::table('student_transport_assignments', function (Blueprint $table) {
                    $table->foreignId('enrollment_id')->nullable()->after('id')->constrained()->restrictOnDelete();
                });
            }

            // Backfill enrollment_id if legacy columns exist.
            if (
                Schema::hasColumn('student_transport_assignments', 'student_id')
                && Schema::hasColumn('student_transport_assignments', 'academic_year_id')
                && Schema::hasColumn('student_transport_assignments', 'enrollment_id')
            ) {
                $studentColumn = Schema::hasColumn('enrollments', 'student_id') ? 'student_id' : (Schema::hasColumn('enrollments', 'enrollment_id') ? 'enrollment_id' : null);
                if (!$studentColumn) {
                    return;
                }

                if (DB::getDriverName() === 'mysql') {
                    DB::statement("
                        UPDATE student_transport_assignments sta
                        INNER JOIN enrollments e
                            ON e.{$studentColumn} = sta.student_id
                            AND e.academic_year_id = sta.academic_year_id
                        SET sta.enrollment_id = e.id
                        WHERE sta.enrollment_id IS NULL
                    ");
                }
            }
        }

        if (!Schema::hasTable('transport_fee_cycles')) {
            Schema::create('transport_fee_cycles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_id')->constrained('student_transport_assignments')->restrictOnDelete();
                $table->unsignedTinyInteger('month');
                $table->unsignedSmallInteger('year');
                $table->decimal('amount', 12, 2);
                $table->timestamp('generated_at');
                $table->timestamps();

                $table->unique(['assignment_id', 'month', 'year']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_fee_cycles');
        Schema::dropIfExists('student_transport_assignments');

        Schema::table('transport_stops', function (Blueprint $table) {
            $table->dropColumn(['distance_km', 'active']);
        });

        Schema::table('transport_routes', function (Blueprint $table) {
            $table->dropColumn(['vehicle_number', 'driver_name', 'active']);
        });
    }
};
