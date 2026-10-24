<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_identity_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->restrictOnDelete();
            $table->string('role', 30);
            $table->foreignId('responsible_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('main_contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['contact_id', 'role']);
            $table->unique(['company_id', 'role']);
            $table->index(['role', 'active', 'archived_at']);
        });
        DB::statement('ALTER TABLE crm_identity_relationships ADD CONSTRAINT crm_relationship_exact_identity CHECK ((contact_id IS NOT NULL)::int + (company_id IS NOT NULL)::int = 1)');
        DB::statement("ALTER TABLE crm_identity_relationships ADD CONSTRAINT crm_relationship_role CHECK (role IN ('customer', 'supplier', 'donor', 'partner', 'prospect'))");
        DB::statement('ALTER TABLE crm_identity_relationships ADD CONSTRAINT crm_relationship_main_contact CHECK (main_contact_id IS NULL OR company_id IS NOT NULL)');
        Schema::create('crm_identity_relationship_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained('crm_identity_relationships')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->unsignedInteger('version');
            $table->jsonb('changed_fields');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['relationship_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_identity_relationship_history');
        Schema::dropIfExists('crm_identity_relationships');
    }
};
