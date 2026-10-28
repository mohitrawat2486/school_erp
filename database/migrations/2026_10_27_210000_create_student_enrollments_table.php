<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['student_id' => 'students', 'academic_year_id' => 'academic_years', 'class_id' => 'classes'] as $field => $table) {
                $t->unsignedBigInteger($field);
                $t->foreign(['school_id', $field])->references(['school_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->unsignedBigInteger('section_id');
            $t->foreign(['school_id', 'class_id', 'section_id'], 'enrollment_section_fk')->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
            $t->unsignedInteger('roll_number');
            $t->date('joined_at');
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['school_id', 'academic_year_id', 'student_id'], 'enrollment_student_year_unique');
            $t->unique(['school_id', 'academic_year_id', 'class_id', 'section_id', 'roll_number'], 'enrollment_roll_unique');
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'academic_year_id', 'class_id', 'section_id', 'status'], 'enrollment_listing_index');
            $t->index(['school_id', 'student_id', 'academic_year_id'], 'enrollment_history_index');
        });
        DB::statement("ALTER TABLE student_enrollments ADD CONSTRAINT enrollment_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT enrollment_roll CHECK (roll_number > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
