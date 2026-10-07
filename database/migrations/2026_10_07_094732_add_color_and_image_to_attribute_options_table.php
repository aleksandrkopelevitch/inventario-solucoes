<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A colour and a picture for the hosting attributes (Hospedagem and Cloud).
 *
 * The ecosystem map's "Por hospedagem" view draws one container per hosting
 * value; its background is `color` and its badge is `image_path` (a plain
 * public-disk path, like a solution's logo — a picture needs no conversions).
 * Both stay null for every other attribute group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_options', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('icon');
            $table->string('image_path')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('attribute_options', function (Blueprint $table) {
            $table->dropColumn(['color', 'image_path']);
        });
    }
};
