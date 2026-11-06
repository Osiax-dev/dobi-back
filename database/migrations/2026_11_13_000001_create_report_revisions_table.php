<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('report_revisions')) {
            Schema::create('report_revisions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('report_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('revision');                 // numéro de révision du rapport au moment de cet état
                $t->string('title', 200);
                $t->json('notes')->nullable();
                $t->json('custom')->nullable();
                $t->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete(); // auteur de cet état
                $t->timestamp('created_at')->nullable();         // date de remplacement par un état plus récent
                $t->index(['report_id', 'revision']);
            });
        }

        if (Schema::hasTable('reports') && ! Schema::hasColumn('reports', 'updated_by')) {
            Schema::table('reports', function (Blueprint $t) {
                $t->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_revisions');
        if (Schema::hasColumn('reports', 'updated_by')) {
            Schema::table('reports', function (Blueprint $t) {
                $t->dropConstrainedForeignId('updated_by');
            });
        }
    }
};
