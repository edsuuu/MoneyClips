<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Limpeza da agenda de auto-postagem e do pipeline reencode/template: fica só
 * a postagem direta (ledger social_posts sem slot). Caem as tabelas
 * schedule_slots, processing_jobs e service_heartbeats e as colunas órfãs
 * social_posts.schedule_slot_id, users.auto_post_schedule e
 * youtube_shorts.dispatched_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No MySQL a FK usa o índice unique composto como índice de apoio —
        // a constraint tem que cair antes do índice (senão: erro 1553).
        Schema::table('social_posts', function (Blueprint $table): void {
            $table->dropForeign(['schedule_slot_id']);
            $table->dropUnique(['schedule_slot_id', 'platform']);
            $table->dropColumn('schedule_slot_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auto_post_schedule');
        });

        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->dropColumn('dispatched_at');
        });

        Schema::dropIfExists('schedule_slots');
        Schema::dropIfExists('processing_jobs');
        Schema::dropIfExists('service_heartbeats');
    }

    public function down(): void
    {
        Schema::create('service_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64)->unique();
            $table->string('hostname')->nullable();
            $table->string('version', 32)->nullable();
            $table->unsignedBigInteger('uptime_seconds')->default(0);
            $table->unsignedInteger('memory_mb')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });

        Schema::create('processing_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('youtube_short_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('queued');
            $table->json('options')->nullable();
            $table->string('remote_id')->nullable();
            $table->string('output_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['youtube_short_id', 'type']);
            $table->index('remote_id');
            $table->index('status');
        });

        Schema::create('schedule_slots', function (Blueprint $table): void {
            $table->id();
            $table->date('slot_date');
            $table->time('slot_time');
            $table->foreignId('youtube_short_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(['slot_date', 'slot_time']);
            $table->index('slot_date');
            $table->index(['dispatched_at', 'is_active']);
        });

        Schema::table('social_posts', function (Blueprint $table): void {
            $table->foreignId('schedule_slot_id')->nullable()->after('uuid')
                ->constrained('schedule_slots')->nullOnDelete();
            $table->unique(['schedule_slot_id', 'platform']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('auto_post_schedule')->nullable();
        });

        Schema::table('youtube_shorts', function (Blueprint $table): void {
            $table->timestamp('dispatched_at')->nullable()->after('posted_at');
        });
    }
};
