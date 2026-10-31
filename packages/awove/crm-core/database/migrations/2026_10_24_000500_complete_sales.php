<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->text('needs')->nullable();
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->date('closing_date')->nullable()->index();
            $table->decimal('final_value', 14, 2)->nullable();
            $table->text('loss_reason')->nullable();
        });
        Schema::create('sales_communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads');
            $table->foreignId('staff_id')->constrained('users');
            $table->string('type', 20);
            $table->timestampTz('occurred_at');
            $table->text('summary');
            $table->timestampsTz();
            $table->index(['lead_id', 'occurred_at']);
        });
        Schema::create('sales_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads');
            $table->foreignId('created_by')->constrained('users');
            $table->string('number', 100);
            $table->decimal('amount', 14, 2);
            $table->date('sent_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('proposal_id')->nullable()->constrained('crm_proposals');
            $table->text('document_url')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
