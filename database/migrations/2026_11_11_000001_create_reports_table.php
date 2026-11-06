<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reports')) {
            return;
        }
        Schema::create('reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('study_id')->constrained()->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title', 200);
            $t->string('status', 20)->default('draft'); // draft | final
            $t->unsignedInteger('revision')->default(1); // +1 à chaque enregistrement (protège contre les écrasements)
            $t->json('content');                          // données calculées, figées au moment de la génération
            $t->json('notes')->nullable();                // textes de l'enquêteur (contexte, objectifs, analyse, conclusions, recommandations)
            $t->timestamp('finalized_at')->nullable();
            $t->timestamps();
            $t->index(['study_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
