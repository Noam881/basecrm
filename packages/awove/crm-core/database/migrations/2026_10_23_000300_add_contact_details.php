<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('secondary_phone', 40)->nullable();
            $table->string('secondary_phone_label', 20)->nullable();
            $table->string('preferred_contact_method', 20)->nullable();
            $table->json('preferred_contact_times')->default('[]');
            $table->json('preferred_contact_days')->default('[]');
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('source', 40)->nullable();
        });
        Schema::create('crm_contact_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('crm_contacts');
            $table->foreignId('author_id')->constrained('users');
            $table->text('body');
            $table->timestampsTz();
            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
