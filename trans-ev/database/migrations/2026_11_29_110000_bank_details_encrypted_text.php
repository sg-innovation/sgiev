<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** bank_details is encrypted at rest (encrypted:array cast), so it must be TEXT, not JSON. */
return new class extends Migration
{
    public function up(): void
    {
        // Any plain JSON rows written before encryption are re-written encrypted.
        $rows = DB::table('companies')->whereNotNull('bank_details')->get(['id', 'bank_details']);
        Schema::table('companies', fn ($t) => $t->text('bank_details')->nullable()->change());
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->bank_details, true);
            if (is_array($decoded)) {
                DB::table('companies')->where('id', $row->id)->update(['bank_details' => encrypt(json_encode($decoded), false)]);
            }
        }
    }

    public function down(): void
    {
        DB::table('companies')->update(['bank_details' => null]);
        Schema::table('companies', fn ($t) => $t->json('bank_details')->nullable()->change());
    }
};
