<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'type')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $t) {
            // personal | company | ngo | association | institution
            $t->string('type', 20)->default('company')->after('slug');
        });

        // Les anciens « Espace de {nom} » deviennent des espaces personnels.
        DB::table('organizations')->where('name', 'like', 'Espace de %')->update(['type' => 'personal', 'name' => 'Espace personnel']);
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('type'));
    }
};
