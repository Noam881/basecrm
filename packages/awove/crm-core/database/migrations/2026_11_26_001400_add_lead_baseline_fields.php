<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('business_sector', 120)->nullable();
            $table->text('product_sold')->nullable();
            $table->date('contract_sent_on')->nullable();
            $table->date('invoice_sent_on')->nullable();
            $table->date('payment_received_on')->nullable();
            $table->date('process_started_on')->nullable();
            $table->text('contract_url')->nullable();
            $table->boolean('privacy_policy_accepted')->nullable();
        });
        Schema::table('crm_companies', function (Blueprint $table) {
            $table->string('registration_number', 80)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; automatic destructive rollback is unsupported.');
    }
};
