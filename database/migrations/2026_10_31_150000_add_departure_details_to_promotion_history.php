<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_outcomes', function (Blueprint $table) {
            $table->date('departure_date')->nullable();
            $table->string('destination_school')->nullable();
        });
        Schema::table('promotion_batches', fn (Blueprint $table) => $table->char('student_form_hash', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('promotion_batches', fn (Blueprint $table) => $table->dropColumn('student_form_hash'));
        Schema::table('promotion_outcomes', fn (Blueprint $table) => $table->dropColumn(['departure_date', 'destination_school']));
    }
};
