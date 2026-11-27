<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_import_automation_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('batch_id')->constrained('crm_import_batches')->restrictOnDelete();
            $table->char('boundary', 64);
            $table->string('module', 32);
            $table->string('source', 64);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->string('state', 16)->default('held');
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason', 32)->nullable();
            $table->unique(['batch_id', 'module', 'source', 'record_id'], 'crm_import_hold_batch_resource_unique');
            $table->index(['module', 'source', 'record_id', 'state'], 'crm_import_hold_resource_state');
        });
        DB::statement("ALTER TABLE crm_import_automation_holds ADD CHECK (boundary ~ '^[a-f0-9]{64}$'), ADD CHECK (module ~ '^[a-z][a-z0-9-]*$'), ADD CHECK (source ~ '^[a-z][a-z0-9-]*$'), ADD CHECK (record_id > 0), ADD CHECK ((state = 'held' AND released_by IS NULL AND released_at IS NULL AND release_reason IS NULL) OR (state = 'released' AND released_by IS NOT NULL AND released_at IS NOT NULL AND release_reason IS NOT NULL AND release_reason IN ('reviewed_activation','cancelled_import','incorrect_scope')))");
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
