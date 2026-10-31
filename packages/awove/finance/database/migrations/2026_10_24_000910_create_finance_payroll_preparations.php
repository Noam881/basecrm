<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payroll_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_reference_id')->constrained('crm_employee_references')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->date('period_start_on');
            $table->date('period_end_on');
            $table->string('status', 30)->default('pending-input');
            $table->char('currency', 3);
            $table->jsonb('input_schema');
            $table->jsonb('inputs')->default('{}');
            $table->text('notes')->nullable();
            $table->date('sent_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['employee_reference_id', 'period_start_on', 'period_end_on'], 'finance_payroll_employee_period_unique');
            $table->index(['owner_id', 'status', 'period_end_on']);
        });
        DB::statement('ALTER TABLE finance_payroll_preparations ADD CONSTRAINT finance_payroll_period CHECK (period_end_on >= period_start_on)');
        DB::statement("ALTER TABLE finance_payroll_preparations ADD CONSTRAINT finance_payroll_status CHECK (status IN ('pending-input','ready-for-review','sent-to-payroll'))");
        Schema::create('finance_payroll_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preparation_id')->constrained('finance_payroll_preparations')->restrictOnDelete();
            $table->string('label', 180);
            $table->text('url');
            $table->foreignId('added_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('finance_payroll_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preparation_id')->constrained('finance_payroll_preparations')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 60);
            $table->unsignedInteger('version');
            $table->jsonb('details');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['preparation_id', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
