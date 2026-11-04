<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enquêteurs affectés à une étude. Aucune ligne = tous les enquêteurs de l'espace peuvent collecter (comportement actuel).
        Schema::create('study_collectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['study_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_collectors');
    }
};
