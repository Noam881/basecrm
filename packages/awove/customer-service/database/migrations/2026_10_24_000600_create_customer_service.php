<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts');
            $table->foreignId('company_id')->nullable()->constrained('crm_companies');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->string('subject', 180);
            $table->text('description');
            $table->date('received_on');
            $table->string('channel', 40);
            $table->string('status', 40)->default('new');
            $table->string('priority', 20)->default('normal');
            $table->text('next_action')->nullable();
            $table->date('due_on')->nullable();
            $table->text('resolution')->nullable();
            $table->date('resolved_on')->nullable();
            $table->text('document_url')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_id', 'status', 'due_on']);
        });
        Schema::create('service_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->nullable()->constrained('service_requests');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 60);
            $table->string('kind', 20)->nullable();
            $table->timestampTz('occurred_at');
            $table->text('summary');
            $table->json('details')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['request_id', 'occurred_at']);
        });
        Schema::create('service_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts');
            $table->foreignId('company_id')->nullable()->constrained('crm_companies');
            $table->foreignId('request_id')->nullable()->constrained('service_requests');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->date('received_on');
            $table->unsignedSmallInteger('rating');
            $table->text('comments');
            $table->boolean('action_required')->default(false);
            $table->date('follow_up_on')->nullable();
            $table->date('followed_up_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_id', 'action_required', 'follow_up_on']);
        });
        Schema::table('service_history', function (Blueprint $table) {
            $table->foreignId('feedback_id')->nullable()->constrained('service_feedback');
            $table->index(['feedback_id', 'occurred_at']);
        });
        DB::statement('ALTER TABLE service_history ADD CONSTRAINT service_history_one_parent CHECK ((request_id IS NOT NULL) <> (feedback_id IS NOT NULL))');

    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
