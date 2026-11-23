<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_locks', static function (Blueprint $table): void {
            $table->string('lock_key', 191)->primary();
            $table->char('owner', 32);
            $table->unsignedBigInteger('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_locks');
    }
};
