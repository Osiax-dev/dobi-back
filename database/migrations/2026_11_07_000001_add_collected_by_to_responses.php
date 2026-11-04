<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            // Enquêteur qui a collecté la réponse (null = lien public). Sans clé étrangère : on garde la réponse si le compte disparaît.
            $table->unsignedBigInteger('collected_by')->nullable()->index()->after('study_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->dropColumn('collected_by');
        });
    }
};
