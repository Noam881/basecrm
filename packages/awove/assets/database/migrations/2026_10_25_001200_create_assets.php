<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets_register', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80);
            $t->string('name', 180);
            $t->string('type', 120);
            $t->string('location', 180);
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->text('notes')->nullable();
            $t->date('purchased_on')->nullable();
            $t->string('serial_number', 180)->nullable();
            $t->string('status', 30)->default('available');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX assets_register_code_unique ON assets_register (lower(code))');
        Schema::create('assets_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained('assets_register');
            $t->string('target_type', 30);
            $t->foreignId('employee_reference_id')->nullable()->constrained('crm_employee_references');
            $t->foreignId('customer_relationship_id')->nullable()->constrained('crm_identity_relationships');
            $t->string('location', 180)->nullable();
            $t->date('assigned_on');
            $t->date('expected_return_on')->nullable();
            $t->date('returned_on')->nullable();
            $t->text('return_condition')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('returned_by')->nullable()->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['asset_id', 'expected_return_on']);
        });
        DB::statement('CREATE UNIQUE INDEX assets_one_open_assignment ON assets_assignments (asset_id) WHERE returned_on IS NULL');
        DB::statement("ALTER TABLE assets_assignments ADD CONSTRAINT assets_assignment_target CHECK ((target_type='employee' AND employee_reference_id IS NOT NULL AND customer_relationship_id IS NULL AND location IS NULL) OR (target_type='customer' AND employee_reference_id IS NULL AND customer_relationship_id IS NOT NULL AND location IS NULL) OR (target_type='location' AND employee_reference_id IS NULL AND customer_relationship_id IS NULL AND location IS NOT NULL))");
        Schema::create('assets_maintenance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained('assets_register');
            $t->string('action', 180);
            $t->text('description')->nullable();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('supplier_relationship_id')->nullable()->constrained('crm_identity_relationships');
            $t->date('due_on');
            $t->string('status', 30)->default('planned');
            $t->date('completed_on')->nullable();
            $t->date('next_due_on')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['asset_id', 'status', 'due_on']);
        });
        Schema::create('assets_maintenance_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('maintenance_id')->constrained('assets_maintenance');
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->foreignId('actor_id')->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['maintenance_id', 'id']);
        });
        Schema::create('assets_maintenance_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('maintenance_id')->unique()->constrained('assets_maintenance');
            $t->foreignId('cost_id')->nullable()->constrained('assets_maintenance_costs');
            $t->date('completed_on');
            $t->date('next_due_on')->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->timestampTz('created_at')->useCurrent();
        });
        Schema::create('assets_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained('assets_register');
            $t->string('name', 180);
            $t->text('url');
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('assets_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained('assets_register');
            $t->foreignId('assignment_id')->nullable()->constrained('assets_assignments');
            $t->foreignId('maintenance_id')->nullable()->constrained('assets_maintenance');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('event', 80);
            $t->jsonb('changed_fields')->default('[]');
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
