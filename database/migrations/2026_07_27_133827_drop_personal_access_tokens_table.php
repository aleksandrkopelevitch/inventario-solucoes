<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes Laravel Sanctum entirely: it was installed for a future `agent`
 * role API access path that was never built (no `routes/api.php`, no
 * `auth:sanctum` guard anywhere), and that role itself is now removed too.
 * Drops the token table the package created; `laravel/sanctum` is removed
 * from composer.json in the same change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }

    public function down(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
