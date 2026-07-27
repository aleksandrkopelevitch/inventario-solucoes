<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `agent` role (`UserRole::Agent`) is removed: it was reserved for a
 * programmatic/API access path that was never built, so it had no reachable
 * use anywhere in the app. `users.role` is a plain string column (no DB
 * enum constraint), so removing the PHP enum case alone would leave any
 * existing `role = 'agent'` row unable to cast via `UserRole::class` —
 * reassign them to `viewer`, the least-privileged remaining role, before
 * that case disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'agent')->update(['role' => 'viewer']);
    }

    public function down(): void
    {
        // Not reversible: which of these rows were originally `agent` is lost.
    }
};
