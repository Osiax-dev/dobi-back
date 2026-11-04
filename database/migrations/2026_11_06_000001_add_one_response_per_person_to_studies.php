<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('studies', function (Blueprint $table) {
            // Désactivé par défaut : les études existantes ne changent pas de comportement.
            $table->boolean('one_response_per_person')->default(false)->after('privacy_mode');
        });
    }

    public function down(): void
    {
        Schema::table('studies', function (Blueprint $table) {
            $table->dropColumn('one_response_per_person');
        });
    }
};
