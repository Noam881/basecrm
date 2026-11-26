<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_members', function (Blueprint $table) {
            $table->id();
            $table->string('installation_id', 80);
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at', 6);
            $table->string('enrollment_token_hash', 64)->nullable();
            $table->string('enrollment_identity_hash', 64)->nullable();
            $table->timestampTz('enrollment_expires_at', 6)->nullable();
            $table->text('password_hash')->nullable();
            $table->text('pending_password_hash')->nullable();
            $table->text('encrypted_totp')->nullable();
            $table->string('totp_key_version', 80)->nullable();
            $table->text('pending_encrypted_totp')->nullable();
            $table->string('pending_totp_key_version', 80)->nullable();
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->unsignedBigInteger('factor_generation')->default(1);
            $table->unsignedBigInteger('last_totp_counter')->nullable();
            $table->text('dev_question_hash')->nullable();
            $table->timestampsTz(6);
            $table->unique(['installation_id', 'user_id']);
        });
        Schema::create('vault_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('installation_id', 80);
            $table->foreignId('user_id')->constrained('users');
            $table->string('session_hash', 64);
            $table->unsignedBigInteger('factor_generation');
            $table->string('credential_fingerprint', 64);
            $table->string('token_hash', 64)->nullable();
            $table->timestampTz('password_verified_at', 6);
            $table->timestampTz('email_verified_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6);
            $table->index(['installation_id', 'user_id', 'session_hash']);
        });
        Schema::create('vault_unlocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('installation_id', 80);
            $table->foreignId('user_id')->constrained('users');
            $table->string('session_hash', 64);
            $table->unsignedBigInteger('factor_generation');
            $table->string('credential_fingerprint', 64);
            $table->timestampTz('issued_at', 6);
            $table->timestampTz('expires_at', 6);
            $table->unique(['installation_id', 'user_id', 'session_hash']);
        });
        DB::statement("ALTER TABLE vault_unlocks ADD CONSTRAINT vault_unlock_fixed_window CHECK (expires_at = issued_at + INTERVAL '300 seconds')");
        Schema::create('vault_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('vault_members');
            $table->text('code_hash');
            $table->timestampTz('used_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive automatic rollback is unsupported.');
    }
};
