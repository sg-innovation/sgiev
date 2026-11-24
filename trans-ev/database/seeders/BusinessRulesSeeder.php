<?php

namespace Database\Seeders;

use App\Support\BusinessRules;
use Illuminate\Database\Seeder;

/** Writes the first-install business rules to the database (existing values are never overwritten). */
class BusinessRulesSeeder extends Seeder
{
    public function run(): void
    {
        BusinessRules::seedDefaults();
    }
}
