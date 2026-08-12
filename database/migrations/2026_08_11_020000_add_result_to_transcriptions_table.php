<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->longText('text')->nullable()->after('error_message');
            $table->string('language', 20)->nullable()->after('text');
            $table->json('segments')->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn(['text', 'language', 'segments']);
        });
    }
};
