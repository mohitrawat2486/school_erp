<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('timezone')->default('Asia/Kolkata');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('school_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unique(['school_id', 'id']);
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->timestamps();
            $t->unique(['school_id', 'name']);
            $t->unique(['school_id', 'id']);
        });
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->restrictOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::create('user_roles', function (Blueprint $t) {
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['user_id', 'role_id']);
            $t->foreign(['school_id', 'user_id'])->references(['school_id', 'id'])->on('users')->restrictOnDelete();
            $t->foreign(['school_id', 'role_id'])->references(['school_id', 'id'])->on('roles')->restrictOnDelete();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->foreign(['school_id', 'user_id'])->references(['school_id', 'id'])->on('users')->restrictOnDelete();
            $t->string('module');
            $t->string('action');
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->ipAddress('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['school_id', 'created_at']);
            $t->index(['school_id', 'entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['school_id', 'id']);
            $t->dropConstrainedForeignId('school_id');
            $t->dropColumn('is_active');
        });
        Schema::dropIfExists('schools');
    }
};
