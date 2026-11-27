<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_import_workdocs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('installation_id', 64);
            $table->char('plan_hash', 64);
            $table->char('main_plan_hash', 64);
            $table->string('doc_id', 255);
            $table->uuid('parent_entry_id');
            $table->foreign('parent_entry_id')->references('id')->on('vault_import_entries')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->unique()->constrained('crm_import_operations')->restrictOnDelete();
            $table->uuid('item_id')->nullable()->unique();
            $table->foreign('item_id')->references('id')->on('vault_items')->restrictOnDelete();
            $table->char('source_fingerprint', 64)->nullable();
            $table->char('target_fingerprint', 64)->nullable();
            $table->string('state', 32)->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['installation_id', 'plan_hash', 'doc_id']);
        });
        DB::statement("ALTER TABLE vault_import_workdocs ADD CHECK (plan_hash ~ '^[a-f0-9]{64}$' AND main_plan_hash ~ '^[a-f0-9]{64}$'), ADD CHECK (state IN ('pending','committed')), ADD CHECK ((state = 'committed') = (item_id IS NOT NULL AND operation_id IS NOT NULL AND source_fingerprint IS NOT NULL AND target_fingerprint IS NOT NULL)), ADD CHECK (source_fingerprint IS NULL OR source_fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (target_fingerprint IS NULL OR target_fingerprint ~ '^[a-f0-9]{64}$'), ADD CHECK (state <> 'pending' OR (item_id IS NULL AND operation_id IS NULL AND source_fingerprint IS NULL AND target_fingerprint IS NULL))");
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; protected workdoc import evidence is retained.');
    }
};
