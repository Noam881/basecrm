<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_work_items', function (Blueprint $table) {
            $table->id();
            $table->string('module', 80);
            $table->string('kind', 64);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('priority', 20)->default('normal');
            $table->string('status', 64);
            $table->date('due_on');
            $table->date('completed_on')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['module', 'kind', 'owner_id', 'status', 'due_on'], 'crm_work_items_action_index');
        });
        Schema::create('crm_routines', function (Blueprint $table) {
            $table->id();
            $table->string('module', 80);
            $table->string('kind', 64);
            $table->string('name', 180);
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('frequency', 120);
            $table->date('next_due_on')->nullable();
            $table->jsonb('checklist')->default('[]');
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['module', 'kind', 'owner_id', 'next_due_on'], 'crm_routines_action_index');
        });
        Schema::create('crm_routine_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained('crm_routines');
            $table->unsignedInteger('routine_version');
            $table->date('occurrence_due_on')->nullable();
            $table->date('completed_on');
            $table->foreignId('completed_by')->constrained('users');
            $table->jsonb('checklist_snapshot')->default('[]');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['routine_id', 'routine_version']);
        });
        Schema::create('crm_work_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->nullable()->constrained('crm_work_items');
            $table->foreignId('routine_id')->nullable()->constrained('crm_routines');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 80);
            $table->jsonb('changed_fields')->default('[]');
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE crm_work_history ADD CONSTRAINT crm_work_history_parent_check CHECK ((work_item_id IS NOT NULL)::integer + (routine_id IS NOT NULL)::integer = 1)');
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
