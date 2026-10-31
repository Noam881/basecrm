<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_company_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('crm_companies');
            $table->foreignId('author_id')->constrained('users');
            $table->text('body');
            $table->timestampsTz();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
