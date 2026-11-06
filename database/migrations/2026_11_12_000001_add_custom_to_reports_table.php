<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reports') && ! Schema::hasColumn('reports', 'custom')) {
            Schema::table('reports', function (Blueprint $t) {
                $t->json('custom')->nullable()->after('notes'); // mise en page de l'enquêteur : ordre, sections masquées, textes libres…
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reports', 'custom')) {
            Schema::table('reports', fn (Blueprint $t) => $t->dropColumn('custom'));
        }
    }
};
