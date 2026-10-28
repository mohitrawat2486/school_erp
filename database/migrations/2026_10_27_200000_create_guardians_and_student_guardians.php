<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 30);
            $table->string('alternate_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('occupation')->nullable();
            $table->text('address')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status', 'first_name', 'last_name']);
            $table->index(['school_id', 'phone']);
        });
        DB::statement("ALTER TABLE guardians ADD CONSTRAINT guardian_status CHECK (status IN ('ACTIVE','INACTIVE'))");
        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('guardian_id');
            $table->string('relationship', 20);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_emergency_contact')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->foreign(['school_id', 'student_id'])->references(['school_id', 'id'])->on('students')->restrictOnDelete();
            $table->foreign(['school_id', 'guardian_id'])->references(['school_id', 'id'])->on('guardians')->restrictOnDelete();
            $table->unique(['school_id', 'student_id', 'guardian_id']);
            $table->index(['school_id', 'guardian_id', 'status']);
            $table->unsignedBigInteger('primary_student_id')->nullable()->storedAs("CASE WHEN status = 'ACTIVE' AND is_primary = 1 THEN student_id ELSE NULL END");
            $table->unique(['school_id', 'primary_student_id']);
        });
        DB::statement("ALTER TABLE student_guardians ADD CONSTRAINT student_guardian_relationship CHECK (relationship IN ('FATHER','MOTHER','GUARDIAN')), ADD CONSTRAINT student_guardian_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT student_guardian_flags CHECK (is_primary IN (0,1) AND is_emergency_contact IN (0,1) AND (status = 'ACTIVE' OR is_primary = 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('guardians');
    }
};
