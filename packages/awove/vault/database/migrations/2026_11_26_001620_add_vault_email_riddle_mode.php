<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_riddle_settings', function (Blueprint $table) {
            $table->string('installation_id', 80)->primary();
            $table->string('question', 240);
            $table->text('answer_hash');
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestampsTz(6);
        });
        foreach (['vault_challenges', 'vault_unlocks'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('auth_mode', 32)->default('password_email_totp');
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_auth_mode CHECK (auth_mode IN ('password_email_totp','email_riddle'))");
        }
        Schema::table('vault_challenges', function (Blueprint $table) {
            $table->timestampTz('password_verified_at', 6)->nullable()->change();
        });
        Schema::table('vault_members', function (Blueprint $table) {
            $table->timestampTz('riddle_approved_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Vault authentication state is retained. Restore a tested backup instead of destructive rollback.');
    }
};
