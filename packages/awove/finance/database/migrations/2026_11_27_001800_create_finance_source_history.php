<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_history_records', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 24);
            $table->string('placement', 16);
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('operation_id')->unique()->constrained('crm_import_operations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['owner_id', 'placement']);
        });
        DB::statement("ALTER TABLE finance_history_records ADD CONSTRAINT finance_history_kind CHECK (kind IN ('collection','income','bonus','monthly-hours','ancillary-quote','reference')), ADD CONSTRAINT finance_history_placement CHECK (placement IN ('linked','internal','unresolved'))");
        Schema::create('finance_history_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('finance_history_records')->restrictOnDelete();
            $table->foreignId('source_mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['record_id', 'source_mapping_id']);
            $table->index(['contact_id', 'record_id']);
            $table->index(['company_id', 'record_id']);
        });
        DB::statement('ALTER TABLE finance_history_identities ADD CONSTRAINT finance_history_one_identity CHECK ((contact_id IS NOT NULL AND company_id IS NULL) OR (contact_id IS NULL AND company_id IS NOT NULL))');
        Schema::create('finance_history_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('finance_history_records')->restrictOnDelete();
            $table->string('entry_kind', 16);
            $table->char('family_key', 64);
            $table->unsignedInteger('source_revision');
            $table->foreignId('previous_entry_id')->nullable()->constrained('finance_history_entries')->restrictOnDelete();
            $table->foreignId('parent_entry_id')->nullable()->constrained('finance_history_entries')->restrictOnDelete();
            $table->foreignId('source_mapping_id')->constrained('crm_import_mappings')->restrictOnDelete();
            $table->foreignId('operation_id')->unique()->constrained('crm_import_operations')->restrictOnDelete();
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['record_id', 'family_key', 'source_revision']);
            $table->index(['record_id', 'id']);
        });
        DB::statement("ALTER TABLE finance_history_entries ADD CONSTRAINT finance_history_entry_kind CHECK (entry_kind IN ('facts','update','reply')), ADD CONSTRAINT finance_history_source_revision CHECK (source_revision BETWEEN 1 AND 1000000), ADD CONSTRAINT finance_history_reply_parent CHECK ((entry_kind = 'reply' AND parent_entry_id IS NOT NULL) OR (entry_kind <> 'reply' AND parent_entry_id IS NULL))");
    }

    public function down(): void
    {
        throw new RuntimeException('Finance source history is retained; destructive rollback is unsupported.');
    }
};
