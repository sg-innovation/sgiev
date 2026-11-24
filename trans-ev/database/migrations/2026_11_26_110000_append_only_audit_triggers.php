<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level append-only guarantee for audit tables (model events alone can be bypassed with
 * query-builder updates or raw SQL). Any UPDATE or DELETE is rejected by MySQL itself.
 * Note: creating triggers needs the TRIGGER privilege (and log_bin_trust_function_creators=1 when binary logging is on).
 */
return new class extends Migration
{
    private const TABLES = ['rule_acceptances', 'status_logs'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = "{$table}_no_".strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                DB::unprepared(<<<SQL
                    CREATE TRIGGER `{$name}` BEFORE {$op} ON `{$table}` FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'
                SQL);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $op) {
                DB::unprepared("DROP TRIGGER IF EXISTS `{$table}_no_{$op}`");
            }
        }
    }
};
