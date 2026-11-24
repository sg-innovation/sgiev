<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The audit log (activity_log) is append-only at the database level, like the other audit tables.
 * Retention: rows are kept; archiving old rows needs a DBA to drop the trigger deliberately (and that is itself auditable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['UPDATE', 'DELETE'] as $op) {
            $name = 'activity_log_no_'.strtolower($op);
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            DB::unprepared(<<<SQL
                CREATE TRIGGER `{$name}` BEFORE {$op} ON `activity_log` FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only'
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS `activity_log_no_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `activity_log_no_delete`');
    }
};
