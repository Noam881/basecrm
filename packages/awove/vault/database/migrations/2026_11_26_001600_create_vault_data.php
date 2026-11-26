<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('installation_id', 80)->index();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('crm_companies')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('type', 30);
            $table->unsignedSmallInteger('level');
            $table->unsignedBigInteger('version')->default(1);
            $table->text('encrypted_payload');
            $table->string('key_version', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);
            $table->index(['installation_id', 'contact_id']);
            $table->index(['installation_id', 'company_id']);
        });
        DB::statement('ALTER TABLE vault_items ADD CONSTRAINT vault_items_one_customer CHECK ((contact_id IS NOT NULL)::int + (company_id IS NOT NULL)::int = 1)');
        DB::statement('ALTER TABLE vault_items ADD CONSTRAINT vault_items_level CHECK (level IN (1,2))');
        DB::statement("ALTER TABLE vault_items ADD CONSTRAINT vault_items_type CHECK (type IN ('login','server','card','ssh','recovery','wallet','document','note'))");
        Schema::create('vault_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('installation_id', 80)->index();
            $table->foreignUuid('item_id')->constrained('vault_items')->restrictOnDelete();
            $table->string('private_path')->unique();
            $table->text('encrypted_name');
            $table->string('name_key_version', 64);
            $table->string('content_key_version', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('deleted_at', 6)->nullable();
        });
        Schema::create('vault_audits', function (Blueprint $table) {
            $table->id();
            $table->string('installation_id', 80)->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->uuid('item_id')->nullable()->index();
            $table->string('action', 64);
            $table->string('result', 16);
            $table->timestampTz('created_at', 6)->useCurrent();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Vault data is retained. Restore a tested backup instead of destructive rollback.');
    }
};
