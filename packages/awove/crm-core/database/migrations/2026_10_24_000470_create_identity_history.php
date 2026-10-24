<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_identity_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts');
            $table->foreignId('company_id')->nullable()->constrained('crm_companies');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('event', 50);
            $table->json('changed_fields');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['contact_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
        DB::statement('ALTER TABLE crm_identity_activity ADD CONSTRAINT crm_identity_activity_one_identity CHECK ((contact_id IS NOT NULL AND company_id IS NULL) OR (contact_id IS NULL AND company_id IS NOT NULL))');
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
