<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_transport')) {
            return;
        }

        if (Schema::hasTable('student_transport_assignments')) {
            $hasUsers = (int) (DB::table('users')->count()) > 0;
            if ($hasUsers) {
                // Backfill legacy rows into enrollment-anchored assignments when possible.
                // We map (student_id, academic_year_id) -> enrollment_id.
                if (DB::getDriverName() === 'mysql') {
                    DB::statement("
                        INSERT INTO student_transport_assignments
                            (enrollment_id, student_id, route_id, stop_id, academic_year_id, start_date, end_date, status, assigned_by, created_at, updated_at)
                        SELECT
                            (
                                SELECT e.id FROM enrollments e
                                WHERE e.student_id = st.student_id
                                  AND e.academic_year_id = st.academic_year_id
                                  AND e.deleted_at IS NULL
                                ORDER BY e.id
                                LIMIT 1
                            ) AS enrollment_id,
                            st.student_id,
                            st.route_id,
                            st.stop_id,
                            st.academic_year_id,
                            COALESCE(ay.start_date, CURDATE()) AS start_date,
                            CASE WHEN st.status = 'inactive' THEN COALESCE(ay.end_date, CURDATE()) ELSE NULL END AS end_date,
                            CASE WHEN st.status = 'inactive' THEN 'stopped' ELSE 'active' END AS status,
                            (SELECT u.id FROM users u ORDER BY u.id LIMIT 1) AS assigned_by,
                            NOW(),
                            NOW()
                        FROM student_transport st
                        LEFT JOIN academic_years ay ON ay.id = st.academic_year_id
                        WHERE (
                            SELECT e.id FROM enrollments e
                            WHERE e.student_id = st.student_id
                              AND e.academic_year_id = st.academic_year_id
                              AND e.deleted_at IS NULL
                            ORDER BY e.id
                            LIMIT 1
                        ) IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM student_transport_assignments sta
                              WHERE sta.student_id = st.student_id
                                AND sta.academic_year_id = st.academic_year_id
                                AND sta.route_id = st.route_id
                                AND sta.stop_id = st.stop_id
                          )
                    ");
                }
            }
        }

        Schema::drop('student_transport');
    }

    public function down(): void
    {
        // Intentionally not recreating legacy table.
    }
};
