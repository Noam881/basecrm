<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // JSONB is copied by PostgreSQL without PHP casts, ID conversions, normalization or name matching.
        DB::transaction(function () {
            DB::statement('INSERT INTO marketing_attribution_snapshots (lead_id,payload,lead_version,lead_created_at,lead_updated_at) SELECT id,attribution,version,created_at,updated_at FROM crm_leads WHERE attribution IS NOT NULL ON CONFLICT (lead_id) DO NOTHING');
            DB::statement('INSERT INTO marketing_attributions (lead_id,payload,version,created_at,updated_at) SELECT lead_id,payload,1,lead_created_at,lead_updated_at FROM marketing_attribution_snapshots ON CONFLICT (lead_id) DO NOTHING');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy snapshots and the frozen source column must be retained.');
    }
};
