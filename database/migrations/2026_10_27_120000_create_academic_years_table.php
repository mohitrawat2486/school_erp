<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->string('status', 20)->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('current_school_id')->nullable()->virtualAs('CASE WHEN is_current = 1 THEN school_id ELSE NULL END');
            $table->unique('current_school_id');
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status', 'start_date']);
        });
        DB::statement("ALTER TABLE academic_years ADD CONSTRAINT academic_year_dates CHECK (end_date > start_date), ADD CONSTRAINT academic_year_status CHECK (status IN ('DRAFT','ACTIVE','CLOSED','ARCHIVED')), ADD CONSTRAINT academic_year_current CHECK (is_current = 0 OR (status = 'ACTIVE' AND deleted_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
