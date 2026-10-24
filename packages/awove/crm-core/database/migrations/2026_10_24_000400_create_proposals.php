<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads');
            $table->unsignedInteger('version');
            $table->string('original_name', 180);
            $table->string('private_path')->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['lead_id', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
