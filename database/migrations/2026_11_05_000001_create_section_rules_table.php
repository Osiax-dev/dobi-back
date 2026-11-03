<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Conditions d'affichage d'une étape entière (migration additive : aucune table existante n'est modifiée). */
    public function up(): void
    {
        if (Schema::hasTable('section_rules')) {
            return;
        }

        Schema::create('section_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('section_id')->constrained('sections')->cascadeOnDelete();         // étape à afficher
            $t->foreignId('source_question_id')->constrained('questions')->cascadeOnDelete(); // question qui déclenche
            $t->string('operator', 20);
            $t->string('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_rules');
    }
};
