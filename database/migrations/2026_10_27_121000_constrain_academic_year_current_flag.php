<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE academic_years ADD CONSTRAINT academic_year_boolean_current CHECK (is_current IN (0, 1))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE academic_years DROP CHECK academic_year_boolean_current');
    }
};
