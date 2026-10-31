<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nonprofit_constituents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('relationship_id')->unique()->constrained('crm_identity_relationships');
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->text('next_action')->nullable();
            $t->date('next_action_on')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['owner_id', 'next_action_on']);
        });
        Schema::create('nonprofit_pledges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('constituent_id')->constrained('nonprofit_constituents');
            $t->string('reference', 120)->nullable();
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->date('expected_on');
            $t->text('restrictions')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
            $t->index(['constituent_id', 'expected_on']);
        });
        Schema::create('nonprofit_pledge_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pledge_id')->constrained('nonprofit_pledges');
            // Optional Finance owns these records; this module also installs without its tables.
            $t->unsignedBigInteger('finance_record_id');
            $t->unsignedBigInteger('payment_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX nonprofit_one_active_pledge_receipt ON nonprofit_pledge_receipts (payment_id) WHERE archived_at IS NULL');
        Schema::create('nonprofit_fundraising_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('constituent_id')->constrained('nonprofit_constituents');
            $t->foreignId('pledge_id')->nullable()->constrained('nonprofit_pledges');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('event', 60);
            $t->jsonb('changed_fields');
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
