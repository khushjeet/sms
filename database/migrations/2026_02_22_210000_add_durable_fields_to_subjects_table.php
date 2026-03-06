<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            if (!Schema::hasColumn('subjects', 'subject_code')) {
                $table->string('subject_code')->nullable()->after('code');
            }
            if (!Schema::hasColumn('subjects', 'short_name')) {
                $table->string('short_name', 50)->nullable()->after('name');
            }
            if (!Schema::hasColumn('subjects', 'category')) {
                $table->string('category', 30)->nullable()->after('type');
            }
            if (!Schema::hasColumn('subjects', 'credits')) {
                $table->unsignedTinyInteger('credits')->nullable()->after('description');
            }
            if (!Schema::hasColumn('subjects', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('credits');
            }
            if (!Schema::hasColumn('subjects', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }
            if (!Schema::hasColumn('subjects', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('status');
            }
            if (!Schema::hasColumn('subjects', 'board_code')) {
                $table->string('board_code')->nullable()->after('effective_to');
            }
            if (!Schema::hasColumn('subjects', 'lms_code')) {
                $table->string('lms_code')->nullable()->after('board_code');
            }
            if (!Schema::hasColumn('subjects', 'erp_code')) {
                $table->string('erp_code')->nullable()->after('lms_code');
            }
            if (!Schema::hasColumn('subjects', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('erp_code');
            }
        });

        DB::table('subjects')
            ->whereNull('subject_code')
            ->update(['subject_code' => DB::raw('code')]);

        DB::table('subjects')
            ->whereNull('category')
            ->update(['category' => DB::raw('type')]);

        DB::table('subjects')
            ->where('status', 'active')
            ->update(['is_active' => true]);

        DB::table('subjects')
            ->where('status', 'inactive')
            ->update(['is_active' => false]);

        Schema::table('subjects', function (Blueprint $table) {
            if (!$this->hasUniqueIndex('subjects', 'subjects_subject_code_unique')) {
                $table->unique('subject_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            if ($this->hasUniqueIndex('subjects', 'subjects_subject_code_unique')) {
                $table->dropUnique('subjects_subject_code_unique');
            }
            $table->dropColumn([
                'subject_code',
                'short_name',
                'category',
                'credits',
                'effective_from',
                'effective_to',
                'is_active',
                'board_code',
                'lms_code',
                'erp_code',
                'archived_at',
            ]);
        });
    }

    private function hasUniqueIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'mysql') {
            $dbName = $connection->getDatabaseName();
            $rows = $connection->select(
                'SELECT COUNT(*) as aggregate
                 FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                [$dbName, $table, $indexName]
            );

            return ((int) ($rows[0]->aggregate ?? 0)) > 0;
        }

        return false;
    }
};

