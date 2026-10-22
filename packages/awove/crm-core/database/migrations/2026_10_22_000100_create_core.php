<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
            $t->json('display_preferences')->nullable();
        });
        Schema::create('crm_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value');
            $t->timestamps();
        });
        foreach (['companies', 'contacts'] as $table) {
            Schema::create('crm_'.$table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->string('name');
                $t->string('email')->nullable()->index();
                $t->string('phone')->nullable();
                $t->string('search_key')->index();
                $t->foreignId('created_by')->constrained('users');
                if ($table === 'contacts') {
                    $t->foreignId('company_id')->nullable()->constrained('crm_companies');
                }$t->timestamps();
            });
        }
        Schema::create('crm_statuses', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('position');
            $t->enum('kind', ['active', 'won', 'lost']);
            $t->timestamps();
        });
        Schema::create('crm_leads', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->foreignId('contact_id')->nullable()->constrained('crm_contacts');
            $t->foreignId('company_id')->nullable()->constrained('crm_companies');
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->string('status_id');
            $t->foreign('status_id')->references('id')->on('crm_statuses');
            $t->string('source')->default('Direct');
            $t->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $t->json('custom_fields')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'archived_at', 'status_id']);
        });
        Schema::create('crm_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('crm_leads');
            $t->foreignId('author_id')->constrained('users');
            $t->text('body');
            $t->timestampsTz();
        });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('crm_leads');
            $t->foreignId('assignee_id')->constrained('users');
            $t->string('title');
            $t->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $t->timestampTz('due_at');
            $t->timestampTz('remind_at');
            $t->enum('status', ['open', 'completed', 'cancelled'])->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['assignee_id', 'status', 'due_at']);
        });
        Schema::create('crm_activity', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained('crm_leads');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('event');
            $t->json('details')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('crm_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained('crm_tasks');
            $t->unsignedInteger('task_version');
            $t->foreignId('user_id')->constrained('users');
            $t->timestampTz('read_at')->nullable();
            $t->timestampsTz();
            $t->unique(['task_id', 'task_version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
