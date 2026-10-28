<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('context')->nullable();
            $table->index(['school_id', 'module', 'created_at'], 'audit_module_history');
            $table->index(['school_id', 'action', 'created_at'], 'audit_action_history');
        });
        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared('CREATE TRIGGER audit_logs_immutable_'.strtolower($operation)." BEFORE $operation ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only'");
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $operation) {
            DB::unprepared("DROP TRIGGER IF EXISTS audit_logs_immutable_$operation");
        }
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_module_history');
            $table->dropIndex('audit_action_history');
            $table->dropColumn('context');
        });
    }
};
