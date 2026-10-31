<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employment_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_reference_id')->unique()->constrained('crm_employee_references');
            $t->string('email', 254)->nullable();
            $t->string('phone', 80)->nullable();
            $t->string('role_name', 180);
            $t->text('role_description')->nullable();
            $t->text('responsibilities');
            $t->foreignId('manager_employee_reference_id')->nullable()->constrained('crm_employee_references');
            $t->date('start_on');
            $t->string('employment_status', 30)->default('active');
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'employment_status']);
        });
        Schema::create('hr_vacancies', function (Blueprint $t) {
            $t->id();
            $t->string('role_name', 180);
            $t->text('description');
            $t->date('opened_on');
            $t->string('status', 30)->default('open');
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'status']);
        });
        Schema::create('hr_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vacancy_id')->constrained('hr_vacancies');
            $t->string('name', 180);
            $t->string('email', 254)->nullable();
            $t->string('phone', 80)->nullable();
            $t->text('notes')->nullable();
            $t->string('stage', 30)->default('new');
            $t->date('next_action_on')->nullable();
            $t->foreignId('employee_reference_id')->nullable()->constrained('crm_employee_references');
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'stage', 'next_action_on']);
        });
        Schema::create('hr_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_reference_id')->constrained('crm_employee_references');
            $t->string('kind', 30);
            $t->string('name', 180);
            $t->date('due_on');
            $t->date('final_work_on')->nullable();
            $t->text('final_pay_info')->nullable();
            $t->date('completed_on')->nullable();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'kind', 'due_on']);
        });
        Schema::create('hr_case_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('hr_cases');
            $t->foreignId('work_item_id')->unique()->constrained('crm_work_items');
            $t->string('category', 30)->default('other');
            $t->boolean('required')->default(true);
            $t->timestampsTz();
        });
        Schema::create('hr_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employment_id')->nullable()->constrained('hr_employment_profiles');
            $t->foreignId('candidate_id')->nullable()->constrained('hr_candidates');
            $t->foreignId('case_id')->nullable()->constrained('hr_cases');
            $t->string('name', 180);
            $t->text('url');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('hr_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employment_id')->nullable()->constrained('hr_employment_profiles');
            $t->foreignId('vacancy_id')->nullable()->constrained('hr_vacancies');
            $t->foreignId('candidate_id')->nullable()->constrained('hr_candidates');
            $t->foreignId('case_id')->nullable()->constrained('hr_cases');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('event', 80);
            $t->jsonb('changed_fields')->default('[]');
            $t->jsonb('details')->default('{}');
            $t->timestampTz('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE hr_documents ADD CONSTRAINT hr_document_parent CHECK ((employment_id IS NOT NULL)::integer + (candidate_id IS NOT NULL)::integer + (case_id IS NOT NULL)::integer = 1)');
        DB::statement('ALTER TABLE hr_history ADD CONSTRAINT hr_history_parent CHECK ((employment_id IS NOT NULL)::integer + (vacancy_id IS NOT NULL)::integer + (candidate_id IS NOT NULL)::integer + (case_id IS NOT NULL)::integer = 1)');
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
