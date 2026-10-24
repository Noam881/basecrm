<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_work_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('module', 80);
            $table->string('source', 80);
            $table->unsignedBigInteger('record_id');
            $table->unsignedInteger('record_version');
            $table->foreignId('user_id')->constrained('users');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->unique(['module', 'source', 'record_id', 'record_version', 'user_id'], 'crm_work_reminders_delivery_unique');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
