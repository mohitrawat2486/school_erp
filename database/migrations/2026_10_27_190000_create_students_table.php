<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('admission_number', 50);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('gender', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('admission_date');
            $table->string('blood_group', 3)->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['school_id', 'admission_number']);
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status', 'last_name', 'first_name']);
            $table->index(['school_id', 'admission_date']);
        });
        DB::statement("ALTER TABLE students ADD CONSTRAINT student_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT student_dates CHECK (date_of_birth IS NULL OR admission_date > date_of_birth), ADD CONSTRAINT student_blood_group CHECK (blood_group IS NULL OR blood_group IN ('A+','A-','B+','B-','AB+','AB-','O+','O-')), ADD CONSTRAINT student_gender CHECK (gender IS NULL OR gender IN ('MALE','FEMALE','OTHER','PREFER_NOT_TO_SAY'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
