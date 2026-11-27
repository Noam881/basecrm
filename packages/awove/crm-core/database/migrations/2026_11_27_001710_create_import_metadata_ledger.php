<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('boundary', 64);
            $table->string('installation_id', 64);
            $table->string('provider', 32);
            $table->string('source_account', 255);
            $table->char('mapping_revision', 64);
            $table->string('code_revision', 64);
            $table->char('plan_revision', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('state', 16)->default('open');
            $table->timestampsTz();
        });
        Schema::create('crm_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->char('boundary', 64);
            $table->string('provider', 32);
            $table->string('source_account', 255);
            $table->string('board_id', 255);
            $table->string('entity_type', 64);
            $table->string('entity_id', 255);
            $table->string('target_role', 64);
            $table->string('relation_key', 255);
            $table->string('target_type', 64)->nullable();
            $table->string('target_id', 255)->nullable();
            $table->string('state', 32)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['boundary', 'provider', 'source_account', 'board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key'], 'crm_import_source_role_unique');
        });
        Schema::create('crm_import_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('batch_id')->constrained('crm_import_batches')->restrictOnDelete();
            $table->foreignId('mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->uuid('request_key');
            $table->unsignedInteger('revision');
            $table->char('source_fingerprint', 64);
            $table->char('observed_target_fingerprint', 64)->nullable();
            $table->foreignId('previous_receipt_id')->nullable()->constrained('crm_import_operations')->restrictOnDelete();
            $table->string('state', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('reservation_expires_at');
            $table->foreignId('finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('finished_at')->nullable();
            $table->string('result_target_type', 64)->nullable();
            $table->string('result_target_id', 255)->nullable();
            $table->char('result_fingerprint', 64)->nullable();
            $table->char('finish_intent_fingerprint', 64)->nullable();
            $table->foreignId('replayed_from_id')->nullable()->constrained('crm_import_operations')->restrictOnDelete();
            $table->string('error_code', 48)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['batch_id', 'request_key']);
            $table->index(['mapping_id', 'revision']);
        });
        DB::statement("CREATE UNIQUE INDEX crm_import_one_reservation ON crm_import_operations (mapping_id) WHERE state = 'claimed'");
        DB::statement("ALTER TABLE crm_import_batches ADD CONSTRAINT crm_import_batch_state CHECK (state IN ('open','paused'))");
        DB::statement("ALTER TABLE crm_import_mappings ADD CONSTRAINT crm_import_mapping_state CHECK (state IN ('pending','claimed','verified','failed','conflict','reconciliation_required')), ADD CONSTRAINT crm_import_mapping_target CHECK ((target_type IS NULL) = (target_id IS NULL))");
        DB::statement("ALTER TABLE crm_import_operations ADD CONSTRAINT crm_import_operation_revision CHECK (revision BETWEEN 1 AND 1000000), ADD CONSTRAINT crm_import_operation_state CHECK (state IN ('claimed','verified','failed','conflict','reconciliation_required')), ADD CONSTRAINT crm_import_operation_result CHECK (
            (state = 'claimed' AND finished_by IS NULL AND finished_at IS NULL AND result_target_type IS NULL AND result_target_id IS NULL AND result_fingerprint IS NULL AND finish_intent_fingerprint IS NULL AND replayed_from_id IS NULL AND error_code IS NULL)
            OR (state = 'verified' AND finished_by IS NOT NULL AND finished_at IS NOT NULL AND result_target_type IS NOT NULL AND result_target_id IS NOT NULL AND result_fingerprint IS NOT NULL AND error_code IS NULL)
            OR (state IN ('failed','conflict','reconciliation_required') AND finished_by IS NOT NULL AND finished_at IS NOT NULL AND result_target_type IS NULL AND result_target_id IS NULL AND result_fingerprint IS NULL AND replayed_from_id IS NULL AND error_code IS NOT NULL))");
        DB::statement("ALTER TABLE crm_import_operations ADD CONSTRAINT crm_import_operation_error CHECK (error_code IS NULL OR error_code IN ('source_changed','target_changed','revision_conflict','mapping_context_changed','target_identity_conflict','reservation_expired','prior_unresolved_operation','operation_failed','unresolved_dependency','access_denied','validation_failed','transient_failure'))");
        foreach (['crm_import_batches' => ['boundary', 'mapping_revision', 'plan_revision'], 'crm_import_mappings' => ['boundary'], 'crm_import_operations' => ['source_fingerprint', 'observed_target_fingerprint', 'result_fingerprint', 'finish_intent_fingerprint']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ADD CHECK ({$column} IS NULL OR {$column} ~ '^[a-f0-9]{64}$')");
            }
        }
        foreach (['crm_import_batches' => ['installation_id', 'source_account'], 'crm_import_mappings' => ['source_account', 'board_id', 'entity_id', 'relation_key', 'target_id'], 'crm_import_operations' => ['result_target_id']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ADD CHECK ({$column} IS NULL OR {$column} ~ '^[A-Za-z0-9_-]+$')");
            }
        }
        foreach (['crm_import_batches' => ['provider'], 'crm_import_mappings' => ['provider', 'entity_type', 'target_role']] as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE {$table} ADD CHECK ({$column} ~ '^[a-z][a-z0-9-]*$')");
            }
        }
        foreach (['crm_import_mappings' => 'target_type', 'crm_import_operations' => 'result_target_type'] as $table => $column) {
            DB::statement("ALTER TABLE {$table} ADD CHECK ({$column} IS NULL OR {$column} ~ '^[a-z][a-z0-9]*([.-][a-z0-9]+)*$')");
        }
        DB::statement("ALTER TABLE crm_import_batches ADD CHECK (code_revision ~ '^([a-f0-9]{40}|[a-f0-9]{64})$')");
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
