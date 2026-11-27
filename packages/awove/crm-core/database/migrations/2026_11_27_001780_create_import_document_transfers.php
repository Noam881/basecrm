<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_import_document_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('operation_id')->unique()->constrained('crm_import_operations')->restrictOnDelete();
            $table->char('boundary', 64);
            $table->char('storage_boundary', 64);
            $table->foreignId('identity_mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->foreignId('source_item_mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->foreignId('history_mapping_id')->nullable()->constrained('crm_import_mappings')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->restrictOnDelete();
            $table->string('private_path', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->jsonb('source_metadata');
            $table->string('state', 32)->default('reserved');
            $table->foreignId('document_id')->nullable()->unique()->constrained('crm_documents')->restrictOnDelete();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['state', 'created_at']);
        });
        DB::statement("ALTER TABLE crm_import_document_transfers ADD CHECK ((contact_id IS NULL) <> (company_id IS NULL)), ADD CHECK (size_bytes > 0 AND size_bytes <= 104857600), ADD CHECK (state IN ('reserved','committed','cleaned')), ADD CHECK ((state = 'committed') = (document_id IS NOT NULL)), ADD CHECK ((state = 'cleaned' AND reconciled_by IS NOT NULL AND reconciled_at IS NOT NULL) OR (state <> 'cleaned' AND reconciled_by IS NULL AND reconciled_at IS NULL))");
        foreach (['boundary', 'storage_boundary', 'sha256'] as $column) {
            DB::statement("ALTER TABLE crm_import_document_transfers ADD CHECK ({$column} ~ '^[a-f0-9]{64}$')");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; document transfer evidence is retained.');
    }
};
