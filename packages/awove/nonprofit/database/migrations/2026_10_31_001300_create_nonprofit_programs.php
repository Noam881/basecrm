<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nonprofit_locations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('nonprofit_groups', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->foreignId('location_id')->nullable()->constrained('nonprofit_locations')->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('nonprofit_programs', function (Blueprint $t) {
            $t->id();
            $t->string('name', 180);
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->foreignId('location_id')->nullable()->constrained('nonprofit_locations')->restrictOnDelete();
            $t->foreignId('group_id')->nullable()->constrained('nonprofit_groups')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('nonprofit_participants', function (Blueprint $t) {
            $t->id();
            $t->string('participant_code', 80);
            $t->string('name', 180);
            $t->string('email', 254)->nullable();
            $t->string('phone', 80)->nullable();
            $t->foreignId('contact_id')->nullable()->constrained('crm_contacts')->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('program_id')->nullable()->constrained('nonprofit_programs')->restrictOnDelete();
            $t->foreignId('location_id')->nullable()->constrained('nonprofit_locations')->restrictOnDelete();
            $t->foreignId('group_id')->nullable()->constrained('nonprofit_groups')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX nonprofit_participant_code_unique ON nonprofit_participants (lower(participant_code))');
        Schema::create('nonprofit_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('program_id')->constrained('nonprofit_programs')->restrictOnDelete();
            $t->string('name', 180);
            $t->date('session_on');
            $t->foreignId('location_id')->nullable()->constrained('nonprofit_locations')->restrictOnDelete();
            $t->foreignId('group_id')->nullable()->constrained('nonprofit_groups')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['program_id', 'session_on']);
        });
        Schema::create('nonprofit_attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('nonprofit_sessions')->restrictOnDelete();
            $t->foreignId('participant_id')->constrained('nonprofit_participants')->restrictOnDelete();
            $t->string('status', 20);
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['session_id', 'participant_id']);
        });
        DB::statement("ALTER TABLE nonprofit_attendance ADD CONSTRAINT nonprofit_attendance_status CHECK(status IN ('present','absent','excused'))");
        Schema::create('nonprofit_program_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('program_id')->constrained('nonprofit_programs')->restrictOnDelete();
            $t->date('feedback_on');
            $t->text('comments');
            $t->foreignId('follow_up_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('next_action', 500)->nullable();
            $t->date('due_on')->nullable();
            $t->date('completed_on')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('nonprofit_program_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('program_id')->constrained('nonprofit_programs')->restrictOnDelete();
            $t->string('name', 180);
            $t->text('url');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('nonprofit_program_history', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 30);
            $t->unsignedBigInteger('record_id');
            $t->foreignId('program_id')->nullable()->constrained('nonprofit_programs')->restrictOnDelete();
            $t->foreignId('participant_id')->nullable()->constrained('nonprofit_participants')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 80);
            $t->jsonb('changed_fields');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['kind', 'record_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Nonprofit Programs data is retained. Restore a tested backup instead.');
    }
};
