<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_request_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_item_id')->unique()->constrained('crm_work_items');
            $table->foreignId('customer_relationship_id')->nullable()->constrained('crm_identity_relationships');
            $table->text('notes')->nullable();
            $table->text('document_url')->nullable();
            $table->timestampsTz();
        });
        Schema::create('operations_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_relationship_id')->constrained('crm_identity_relationships');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->string('reference', 120);
            $table->text('description');
            $table->decimal('quantity', 14, 3)->nullable();
            $table->decimal('agreed_total', 14, 2);
            $table->char('currency', 3);
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            $table->date('received_on')->nullable();
            $table->text('document_url')->nullable();
            $table->text('outstanding_items')->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_id', 'status', 'expected_on']);
        });
        Schema::create('operations_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('operations_orders');
            $table->foreignId('actor_id')->constrained('users');
            $table->date('received_on');
            $table->decimal('quantity', 14, 3)->nullable();
            $table->boolean('complete')->default(false);
            $table->text('description');
            $table->uuid('submission_key');
            $table->string('submission_fingerprint', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['order_id', 'submission_key']);
        });
        Schema::create('operations_order_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('operations_orders');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 60);
            $table->json('details')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
