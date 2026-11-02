<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('responses', 'client_token')) {
            // Jeton généré par l'appareil du participant : un renvoi (synchronisation hors ligne) ne crée jamais de doublon.
            Schema::table('responses', fn (Blueprint $t) => $t->string('client_token', 64)->nullable()->unique()->after('uuid'));
        }
    }

    public function down(): void
    {
        Schema::table('responses', fn (Blueprint $t) => $t->dropColumn('client_token'));
    }
};
