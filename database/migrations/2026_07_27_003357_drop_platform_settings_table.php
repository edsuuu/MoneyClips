<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('platform_settings');
    }

    public function down(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32)->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }
};
