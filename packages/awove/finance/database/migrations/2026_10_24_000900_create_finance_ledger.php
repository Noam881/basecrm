<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_records', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('relationship_id')->constrained('crm_identity_relationships')->restrictOnDelete();
            $table->string('reference', 120);
            $table->text('description');
            $table->string('category', 120)->nullable();
            $table->decimal('total', 14, 2);
            $table->char('currency', 3);
            $table->date('due_on');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->text('next_action')->nullable();
            $table->date('next_action_on')->nullable();
            $table->text('document_url')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['kind', 'owner_id', 'due_on']);
        });
        DB::statement("ALTER TABLE finance_records ADD CONSTRAINT finance_record_kind CHECK (kind IN ('charge','expense'))");
        DB::statement('ALTER TABLE finance_records ADD CONSTRAINT finance_record_total CHECK (total > 0)');
        Schema::create('finance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('finance_records')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('actor_id')->constrained('users');
            $table->uuid('submission_key');
            $table->string('submission_fingerprint', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['record_id', 'submission_key']);
            $table->index('paid_on');
        });
        DB::statement('ALTER TABLE finance_payments ADD CONSTRAINT finance_payment_amount CHECK (amount > 0)');
        Schema::create('finance_record_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained('finance_records')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 50);
            $table->unsignedInteger('version');
            $table->jsonb('details')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['record_id', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
