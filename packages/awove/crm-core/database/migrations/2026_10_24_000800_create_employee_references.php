<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_employee_references', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('contact_id')->nullable()->unique()->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['active', 'name']);
        });
        Schema::create('crm_employee_reference_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_reference_id')->constrained('crm_employee_references')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('event', 60);
            $table->jsonb('changed_fields');
            $table->jsonb('links')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['employee_reference_id', 'version'], 'crm_employee_reference_history_version_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
