<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 30);
            $t->string('title', 180);
            $t->string('category', 120)->nullable();
            $t->string('relevant_role', 160)->nullable();
            $t->text('purpose')->nullable();
            $t->string('use_classification', 120)->nullable();
            $t->string('revision', 120)->nullable();
            $t->date('revised_on')->nullable();
            $t->date('updated_on')->nullable();
            $t->string('status', 30)->default('draft');
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'kind', 'status']);
        });
        Schema::create('knowledge_renewals', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->string('provider', 180);
            $t->date('expires_on');
            $t->date('remind_on')->nullable();
            $t->string('status', 30)->default('pending');
            $t->text('notes')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'status', 'expires_on']);
        });
        Schema::create('knowledge_systems', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->text('login_url')->nullable();
            $t->string('password_manager_reference', 300)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index('owner_id');
        });
        Schema::create('knowledge_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->nullable()->constrained('knowledge_entries');
            $t->foreignId('renewal_id')->nullable()->constrained('knowledge_renewals');
            $t->string('name', 180);
            $t->text('url');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('knowledge_renewal_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('renewal_id')->constrained('knowledge_renewals');
            $t->date('previous_expiry_on');
            $t->date('renewed_on');
            $t->date('next_expiry_on')->nullable();
            $t->text('summary')->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('knowledge_instructions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('knowledge_entries');
            $t->foreignId('work_item_id')->nullable()->constrained('crm_work_items');
            $t->foreignId('routine_id')->nullable()->constrained('crm_routines');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->unique(['entry_id', 'work_item_id']);
            $t->unique(['entry_id', 'routine_id']);
        });
        Schema::create('knowledge_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->nullable()->constrained('knowledge_entries');
            $t->foreignId('renewal_id')->nullable()->constrained('knowledge_renewals');
            $t->foreignId('system_id')->nullable()->constrained('knowledge_systems');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('event', 80);
            $t->jsonb('changed_fields')->default('[]');
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE knowledge_documents ADD CONSTRAINT knowledge_document_parent CHECK ((entry_id IS NOT NULL)::integer + (renewal_id IS NOT NULL)::integer = 1)');
        DB::statement('ALTER TABLE knowledge_instructions ADD CONSTRAINT knowledge_instruction_parent CHECK ((work_item_id IS NOT NULL)::integer + (routine_id IS NOT NULL)::integer = 1)');
        DB::statement('ALTER TABLE knowledge_history ADD CONSTRAINT knowledge_history_parent CHECK ((entry_id IS NOT NULL)::integer + (renewal_id IS NOT NULL)::integer + (system_id IS NOT NULL)::integer = 1)');
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
