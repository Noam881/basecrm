<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->text('objective');
            $t->string('channel', 80);
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->decimal('planned_budget', 14, 2);
            $t->char('currency', 3);
            $t->text('materials_url')->nullable();
            $t->text('landing_url')->nullable();
            $t->string('status', 20)->default('planned');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['owner_id', 'status']);
        });
        DB::statement("ALTER TABLE marketing_campaigns ADD CONSTRAINT marketing_campaign_dates CHECK (ends_on >= starts_on), ADD CONSTRAINT marketing_campaign_budget CHECK (planned_budget >= 0), ADD CONSTRAINT marketing_campaign_status CHECK(status IN ('planned','active','completed','paused'))");
        Schema::create('marketing_performance_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->constrained('marketing_campaigns')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->decimal('actual_spend', 14, 2);
            $t->char('currency', 3);
            $t->unsignedInteger('reported_leads');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['campaign_id', 'starts_on', 'ends_on']);
        });
        DB::statement('ALTER TABLE marketing_performance_reports ADD CONSTRAINT marketing_report_dates CHECK(ends_on >= starts_on), ADD CONSTRAINT marketing_report_spend CHECK(actual_spend >= 0), ADD CONSTRAINT marketing_report_leads CHECK(reported_leads >= 0)');
        Schema::create('marketing_content', function (Blueprint $t) {
            $t->id();
            $t->string('title', 160);
            $t->string('channel', 80);
            $t->string('format', 80);
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->date('planned_on');
            $t->text('draft')->nullable();
            $t->text('asset_url')->nullable();
            $t->text('publication_url')->nullable();
            $t->date('published_on')->nullable();
            $t->string('status', 20)->default('idea');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['owner_id', 'planned_on']);
        });
        DB::statement("ALTER TABLE marketing_content ADD CONSTRAINT marketing_content_status CHECK(status IN ('idea','draft','ready','published')), ADD CONSTRAINT marketing_publication CHECK((status='published' AND published_on IS NOT NULL AND publication_url IS NOT NULL) OR status <> 'published')");
        Schema::create('marketing_attributions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->unique()->constrained('crm_leads')->restrictOnDelete();
            $t->jsonb('payload')->nullable();
            $t->foreignId('linked_campaign_id')->nullable()->constrained('marketing_campaigns')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestampsTz();
        });
        Schema::create('marketing_attribution_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->unique()->constrained('crm_leads')->restrictOnDelete();
            $t->jsonb('payload')->nullable();
            $t->unsignedInteger('lead_version');
            $t->timestampTz('lead_created_at')->nullable();
            $t->timestampTz('lead_updated_at')->nullable();
            $t->timestampTz('extracted_at')->useCurrent();
        });
        Schema::create('marketing_history', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->unsignedBigInteger('record_id');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('event', 50);
            $t->unsignedInteger('version');
            $t->jsonb('details')->default('{}');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['kind', 'record_id', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a tested backup; destructive rollback is unsupported.');
    }
};
