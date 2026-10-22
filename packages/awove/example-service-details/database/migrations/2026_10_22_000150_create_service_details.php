<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_details_leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->unique()->constrained('crm_leads');
            $t->string('service_reference')->nullable();
            $t->text('requested_service')->nullable();
            $t->boolean('created_from_event')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve module data; use a tested backup for recovery.');
    }
};
