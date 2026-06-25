<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuração runtime do AutoPostDispatcher. Singleton-ish: só existe 1 row
 * (id=1), seedada pela migration. Use ::current() pra ler/criar sob demanda.
 */
final class AutoPostSettings extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    protected $table = 'auto_post_settings';

    protected $fillable = ['youtube_enabled', 'tiktok_enabled', 'updated_by_user_id'];

    /**
     * Single row da configuração. Cria com defaults se ainda não existe
     * (proteção pra ambientes sem a seed da migration).
     */
    public static function current(): self
    {
        $instance = self::query()->first();
        if ($instance instanceof self) {
            return $instance;
        }

        return self::query()->create([
            'youtube_enabled' => (bool) config('services.youtube_shorts.posting.youtube_enabled', true),
            'tiktok_enabled' => (bool) config('services.youtube_shorts.posting.tiktok_enabled', true),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'youtube_enabled' => 'boolean',
            'tiktok_enabled' => 'boolean',
        ];
    }
}
