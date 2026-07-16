<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slots da agenda de auto-postagem, 100% em banco. Cada linha é um horário
 * concreto (data + hora, semântica America/Sao_Paulo) com um vídeo atribuído
 * (ou vazio). Substitui o antigo users.auto_post_schedule + sorteio na hora:
 * o dispatcher agora posta O VÍDEO DO SLOT no horário do slot.
 *
 * dispatched_at é o claim atômico do dispatcher (UPDATE ... WHERE
 * dispatched_at IS NULL) — substitui o lock por Cache::add da janela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_slots', function (Blueprint $table): void {
            $table->id();
            // Dia calendário no fuso de negócio (America/Sao_Paulo).
            $table->date('slot_date');
            // Horário do disparo ("HH:MM:SS"; a UI trabalha com HH:MM).
            $table->time('slot_time');
            // Vídeo atribuído ao slot (null = slot vazio, nada a postar).
            $table->foreignId('youtube_short_id')->nullable()
                ->constrained()->nullOnDelete();
            // Toggle por slot — desativado = "pulado de propósito".
            $table->boolean('is_active')->default(true);
            // Claim atômico do dispatcher — preenchido 1x, nunca reprocessado.
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->unique(['slot_date', 'slot_time']);
            $table->index('slot_date');
            $table->index(['dispatched_at', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_slots');
    }
};
