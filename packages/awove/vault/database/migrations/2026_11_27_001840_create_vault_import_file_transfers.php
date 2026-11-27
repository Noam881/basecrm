<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_import_file_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_key')->unique();
            $table->string('installation_id', 64);
            $table->char('plan_hash', 64);
            $table->uuid('entry_id');
            $table->foreign('entry_id')->references('id')->on('vault_import_entries')->restrictOnDelete();
            $table->string('asset_id', 255);
            $table->string('column_id', 255);
            $table->unsignedInteger('source_index');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('operation_id')->nullable()->unique()->constrained('crm_import_operations')->restrictOnDelete();
            $table->uuid('item_id');
            $table->foreign('item_id')->references('id')->on('vault_items')->restrictOnDelete();
            $table->uuid('file_id')->unique();
            $table->uuid('attachment_id')->nullable()->unique();
            $table->foreign('attachment_id')->references('id')->on('vault_files')->restrictOnDelete();
            $table->string('private_path', 255)->unique();
            $table->char('storage_boundary', 64);
            $table->unsignedInteger('item_version');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('content_hmac', 64)->nullable();
            $table->char('source_hmac', 64)->nullable();
            $table->char('target_hmac', 64)->nullable();
            $table->string('state', 32)->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reconciled_at')->nullable();
            $table->unique(['entry_id', 'column_id', 'source_index']);
        });
        DB::statement("ALTER TABLE vault_import_file_transfers ADD CHECK (state IN ('pending','reserved','committed','cleaned')), ADD CHECK (size_bytes IS NULL OR (size_bytes > 0 AND size_bytes <= 10485760)), ADD CHECK ((state = 'committed') = (attachment_id IS NOT NULL)), ADD CHECK (attachment_id IS NULL OR attachment_id = file_id), ADD CHECK (state = 'pending' OR (operation_id IS NOT NULL AND content_hmac IS NOT NULL AND source_hmac IS NOT NULL AND size_bytes IS NOT NULL)), ADD CHECK ((state = 'committed') = (target_hmac IS NOT NULL)), ADD CHECK ((state = 'cleaned') = (reconciled_at IS NOT NULL AND reconciled_by IS NOT NULL))");
        DB::statement("ALTER TABLE vault_import_file_transfers ADD CHECK (item_version > 0 AND source_index BETWEEN 0 AND 10000), ADD CHECK (state <> 'pending' OR (operation_id IS NULL AND content_hmac IS NULL AND source_hmac IS NULL AND size_bytes IS NULL)), ADD CHECK ((state = 'cleaned' AND reconciled_by IS NOT NULL AND reconciled_at IS NOT NULL) OR (state <> 'cleaned' AND reconciled_by IS NULL AND reconciled_at IS NULL))");
        foreach (['plan_hash', 'storage_boundary', 'content_hmac', 'source_hmac', 'target_hmac'] as $column) {
            DB::statement("ALTER TABLE vault_import_file_transfers ADD CHECK ({$column} IS NULL OR {$column} ~ '^[a-f0-9]{64}$')");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; Vault transfer evidence is retained.');
    }
};
