<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->date('entered_on')->nullable();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('LOCK TABLE crm_leads IN ACCESS EXCLUSIVE MODE');
            if (DB::table('crm_leads')->whereNotNull('entered_on')->exists()) {
                throw new RuntimeException('Retained lead-entry dates prevent destructive rollback.');
            }
            Schema::table('crm_leads', fn (Blueprint $table) => $table->dropColumn('entered_on'));
        });
    }
};
