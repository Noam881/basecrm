<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', fn (Blueprint $t) => $t->string('external_reference')->nullable()->index());
    }

    public function down(): void
    {
        throw new RuntimeException('Use a tested recovery plan.');
    }
};
