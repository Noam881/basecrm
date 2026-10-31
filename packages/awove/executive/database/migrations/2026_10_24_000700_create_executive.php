<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_goals', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->date('target_on');
            $table->text('success_criterion');
            $table->string('unit', 80);
            $table->decimal('target_value', 18, 4);
            $table->decimal('current_value', 18, 4);
            $table->string('status', 30)->default('not-started');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_id', 'status', 'target_on']);
        });
        Schema::create('executive_goal_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('executive_goals');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 30);
            $table->json('details');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['goal_id', 'created_at']);
        });
        Schema::create('executive_task_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->unique()->constrained('crm_work_items');
            $table->foreignId('goal_id')->constrained('executive_goals');
            $table->timestampsTz();
        });
        Schema::create('executive_task_goal_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->constrained('crm_work_items');
            $table->foreignId('from_goal_id')->nullable()->constrained('executive_goals');
            $table->foreignId('to_goal_id')->nullable()->constrained('executive_goals');
            $table->foreignId('actor_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
