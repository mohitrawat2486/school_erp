<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('employee_number', 50);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('employee_type', 30)->default('OTHER');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('gender', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('joining_date');
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['school_id', 'employee_number']);
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status', 'last_name', 'first_name']);
            $table->index(['school_id', 'employee_type']);
        });
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employee_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT employee_type CHECK (employee_type IN ('PRINCIPAL','TEACHER','ADMINISTRATOR','ACCOUNTANT','RECEPTIONIST','CLERK','LIBRARIAN','OTHER')), ADD CONSTRAINT employee_dates CHECK (date_of_birth IS NULL OR joining_date > date_of_birth), ADD CONSTRAINT employee_gender CHECK (gender IS NULL OR gender IN ('MALE','FEMALE','OTHER','PREFER_NOT_TO_SAY'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
