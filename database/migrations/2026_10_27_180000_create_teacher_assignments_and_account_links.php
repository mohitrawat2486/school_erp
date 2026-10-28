<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('employee_id')->nullable()->unique();
            $t->foreign(['school_id', 'employee_id'])->references(['school_id', 'id'])->on('employees')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT user_employee_school CHECK (employee_id IS NULL OR school_id IS NOT NULL)');
        Schema::table('class_subjects', fn (Blueprint $t) => $t->unique(['school_id', 'academic_year_id', 'class_id', 'subject_id', 'id'], 'class_subject_assignment_reference'));
        Schema::create('teacher_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['academic_year_id' => 'academic_years', 'teacher_id' => 'teacher_profiles', 'class_id' => 'classes'] as $field => $table) {
                $t->unsignedBigInteger($field);
                $t->foreign(['school_id', $field])->references(['school_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->unsignedBigInteger('section_id')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->unsignedBigInteger('class_subject_id')->nullable();
            $t->foreign(['school_id', 'class_id', 'section_id'], 'assignment_section_fk')->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'subject_id', 'class_subject_id'], 'assignment_curriculum_fk')->references(['school_id', 'academic_year_id', 'class_id', 'subject_id', 'id'])->on('class_subjects')->restrictOnDelete();
            $t->string('assignment_type', 20);
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unsignedBigInteger('section_key')->virtualAs('COALESCE(section_id,0)');
            $t->unsignedBigInteger('subject_key')->virtualAs('COALESCE(subject_id,0)');
            $t->unsignedBigInteger('active_class_teacher_section')->nullable()->virtualAs("CASE WHEN assignment_type = 'CLASS_TEACHER' AND status = 'ACTIVE' THEN COALESCE(section_id,0) ELSE NULL END");
            $t->unique(['school_id', 'academic_year_id', 'teacher_id', 'class_id', 'assignment_type', 'section_key', 'subject_key'], 'teacher_assignment_identity');
            $t->unique(['school_id', 'academic_year_id', 'class_id', 'active_class_teacher_section'], 'active_class_teacher_scope');
            $t->index(['school_id', 'teacher_id', 'status', 'academic_year_id', 'class_id'], 'assignment_access_index');
            $t->index(['school_id', 'academic_year_id', 'class_id', 'status'], 'assignment_listing_index');
        });
        DB::statement("ALTER TABLE teacher_assignments ADD CONSTRAINT assignment_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT assignment_shape CHECK ((assignment_type = 'CLASS_TEACHER' AND subject_id IS NULL AND class_subject_id IS NULL) OR (assignment_type = 'SUBJECT_TEACHER' AND subject_id IS NOT NULL AND class_subject_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_assignments');
        Schema::table('class_subjects', fn (Blueprint $t) => $t->dropUnique('class_subject_assignment_reference'));
        DB::statement('ALTER TABLE users DROP CHECK user_employee_school');
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['school_id', 'employee_id']);
            $t->dropUnique(['employee_id']);
            $t->dropColumn('employee_id');
        });
    }
};
