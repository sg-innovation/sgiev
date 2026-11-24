<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Electrical specification of each charger (shown on the station page, receipt and invoice) and the
 * station's grid connection. Existing chargers get typical values for their type and power; edit them in admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargers', function (Blueprint $table) {
            $table->string('model', 80)->nullable()->after('oem');
            $table->unsignedSmallInteger('input_voltage_v')->nullable()->after('power_kw');   // supply side: 230 / 415
            $table->unsignedTinyInteger('phases')->nullable()->after('input_voltage_v');       // 1 or 3
            $table->unsignedSmallInteger('output_voltage_min_v')->nullable()->after('phases');  // DC output range
            $table->unsignedSmallInteger('output_voltage_max_v')->nullable()->after('output_voltage_min_v');
            $table->decimal('max_current_a', 6, 1)->nullable()->after('output_voltage_max_v');
            $table->string('standard', 60)->nullable()->after('max_current_a');                // e.g. IEC 61851-23, Bharat DC-001
            $table->string('meter_serial', 60)->nullable()->after('serial_no');
            $table->string('meter_accuracy_class', 20)->nullable()->after('meter_serial');      // e.g. Class 1, Class 0.5S
            $table->string('firmware_version', 40)->nullable()->after('meter_accuracy_class');
            $table->date('commissioned_on')->nullable()->after('firmware_version');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->string('electricity_provider', 120)->nullable()->after('gstin');   // DISCOM
            $table->string('electricity_consumer_no', 40)->nullable()->after('electricity_provider');
            $table->string('supply_type', 2)->nullable()->after('electricity_consumer_no');  // LT | HT
            $table->string('supply_voltage', 30)->nullable()->after('supply_type');          // e.g. 415 V 3-phase, 11 kV
            $table->decimal('sanctioned_load_kw', 8, 2)->nullable()->after('supply_voltage');
        });

        // Typical values for existing chargers.
        foreach (DB::table('chargers')->get(['id', 'type', 'power_kw']) as $c) {
            $kw = (float) $c->power_kw;
            if ($c->type === 'AC') {
                $single = $kw <= 7.4;
                $data = [
                    'input_voltage_v' => $single ? 230 : 415, 'phases' => $single ? 1 : 3,
                    'max_current_a' => $single ? round($kw * 1000 / 230, 1) : round($kw * 1000 / (415 * 1.732), 1),
                    'standard' => 'IEC 61851-1 / IEC 62196-2',
                ];
            } else {
                $max = $kw >= 60 ? 1000 : 500;
                $data = [
                    'input_voltage_v' => 415, 'phases' => 3, 'output_voltage_min_v' => 150, 'output_voltage_max_v' => $max,
                    'max_current_a' => min(500, round($kw * 1000 / ($max * 0.8), 1)),
                    'standard' => 'IEC 61851-23 / IEC 62196-3 (CCS2)',
                ];
            }
            DB::table('chargers')->where('id', $c->id)->update($data + ['meter_accuracy_class' => 'Class 1']);
        }
        DB::table('stations')->update(['supply_type' => 'LT', 'supply_voltage' => '415 V 3-phase']);
    }

    public function down(): void
    {
        Schema::table('chargers', fn (Blueprint $t) => $t->dropColumn(['model', 'input_voltage_v', 'phases', 'output_voltage_min_v', 'output_voltage_max_v', 'max_current_a', 'standard', 'meter_serial', 'meter_accuracy_class', 'firmware_version', 'commissioned_on']));
        Schema::table('stations', fn (Blueprint $t) => $t->dropColumn(['electricity_provider', 'electricity_consumer_no', 'supply_type', 'supply_voltage', 'sanctioned_load_kw']));
    }
};
