<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('private_path', 255)->unique();
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['contact_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
        DB::statement('ALTER TABLE crm_documents ADD CONSTRAINT crm_documents_one_identity CHECK ((contact_id IS NOT NULL AND company_id IS NULL) OR (contact_id IS NULL AND company_id IS NOT NULL))');
        DB::statement('ALTER TABLE crm_documents ADD CONSTRAINT crm_documents_nonempty CHECK (size_bytes > 0)');
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
