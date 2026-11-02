<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['employees', 'students'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->text('aadhaar_number')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['employees', 'students'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('aadhaar_number'));
        }
    }
};
