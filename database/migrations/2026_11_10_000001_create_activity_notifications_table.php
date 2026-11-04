<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();   // destinataire
            $t->unsignedBigInteger('organization_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();                // qui a agi (null = participant ou système)
            $t->string('event', 60);
            $t->unsignedBigInteger('study_id')->nullable();
            $t->string('message', 400);
            $t->unsignedInteger('count')->default(1);                      // actions regroupées
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_notifications');
    }
};
