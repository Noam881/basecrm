<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nonprofit_projects', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->text('objective');
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 30)->default('planned');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['owner_id', 'status']);
        });
        Schema::create('nonprofit_project_budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('currency', 3);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('nonprofit_project_work', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->foreignId('work_item_id')->unique()->constrained('crm_work_items')->restrictOnDelete();
            $t->string('kind', 40);
        });
        Schema::create('nonprofit_project_meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->date('held_on');
            $t->text('summary');
            $t->text('decisions')->nullable();
            $t->foreignId('follow_up_work_item_id')->nullable()->unique()->constrained('crm_work_items')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('nonprofit_project_resources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->string('target_type', 30);
            $t->unsignedBigInteger('target_id');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX nonprofit_project_resources_active_unique ON nonprofit_project_resources(project_id,target_type,target_id) WHERE archived_at IS NULL');
        Schema::create('nonprofit_project_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->unsignedBigInteger('payment_id');
            $t->string('direction', 10);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX nonprofit_project_payments_active_unique ON nonprofit_project_payments(payment_id) WHERE archived_at IS NULL');
        Schema::create('nonprofit_project_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->string('name', 180);
            $t->text('url');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('nonprofit_project_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('nonprofit_projects')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 80);
            $t->jsonb('changed_fields');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
