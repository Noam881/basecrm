<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One retained row owns the reserved path and, after commit, the actual Finance attachment.
        Schema::create('finance_history_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('operation_id')->unique()->constrained('crm_import_operations')->restrictOnDelete();
            $table->foreignId('record_id')->constrained('finance_history_records')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('finance_history_entries')->restrictOnDelete();
            $table->foreignId('record_mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->foreignId('entry_mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->char('boundary', 64);
            $table->char('storage_boundary', 64);
            $table->string('private_path', 512)->unique();
            $table->string('original_name', 255);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->jsonb('source_metadata');
            $table->string('state', 16);
            $table->timestampTz('created_at');
            $table->timestampTz('committed_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reconciled_at')->nullable();
            $table->index(['record_id', 'state', 'created_at']);
        });
        DB::statement("ALTER TABLE finance_history_attachments ADD CONSTRAINT finance_attachment_state CHECK (state IN ('reserved','committed','cleaned')), ADD CONSTRAINT finance_attachment_size CHECK (size_bytes BETWEEN 1 AND 104857600), ADD CONSTRAINT finance_attachment_commit CHECK ((state = 'committed' AND committed_at IS NOT NULL) OR (state <> 'committed' AND committed_at IS NULL))");
    }

    public function down(): void
    {
        throw new RuntimeException('Finance attachment journals and committed files are retained.');
    }
};
