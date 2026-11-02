<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('studies', 'status_before_archive')) {
            Schema::table('studies', fn (Blueprint $t) => $t->string('status_before_archive', 20)->nullable()->after('status'));
        }
    }

    public function down(): void
    {
        Schema::table('studies', fn (Blueprint $t) => $t->dropColumn('status_before_archive'));
    }
};
