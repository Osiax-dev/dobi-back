<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->string('slug', 100)->unique();
                $t->text('description')->nullable();
                $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('organization_members')) {
            Schema::create('organization_members', function (Blueprint $t) {
                $t->id();
                $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('role', 20);
                $t->timestamps();
                $t->unique(['organization_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('studies')) {
            Schema::create('studies', function (Blueprint $t) {
                $t->id();
                $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('title', 200);
                $t->string('slug', 100)->unique();
                $t->text('description')->nullable();
                $t->text('objective')->nullable();
                $t->string('category', 100)->nullable();
                $t->string('target_population')->nullable();
                $t->string('geographic_zone')->nullable();
                $t->timestamp('starts_at')->nullable();
                $t->timestamp('ends_at')->nullable();
                $t->string('collection_mode', 20)->default('classic');   // classic | conversational
                $t->string('privacy_mode', 20)->default('anonymous');    // anonymous | identified
                $t->string('status', 20)->default('draft');             // draft|published|suspended|closed|archived
                $t->unsignedBigInteger('current_version_id')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->timestamps();
                $t->index(['organization_id', 'status']);
            });
        }

        if (! Schema::hasTable('study_versions')) {
            Schema::create('study_versions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('study_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('version_number');
                $t->json('snapshot');
                $t->timestamp('published_at');
                $t->timestamps();
                $t->unique(['study_id', 'version_number']);
            });
        }

        if (! Schema::hasTable('sections')) {
            Schema::create('sections', function (Blueprint $t) {
                $t->id();
                $t->foreignId('study_id')->constrained()->cascadeOnDelete();
                $t->string('title', 200);
                $t->text('description')->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('questions')) {
            Schema::create('questions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('study_id')->constrained()->cascadeOnDelete();
                $t->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
                $t->string('type', 30);
                $t->string('label', 500);
                $t->text('description')->nullable();
                $t->boolean('required')->default(false);
                $t->unsignedInteger('position')->default(0);
                $t->json('settings')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('question_options')) {
            Schema::create('question_options', function (Blueprint $t) {
                $t->id();
                $t->foreignId('question_id')->constrained()->cascadeOnDelete();
                $t->string('label');
                $t->string('value', 100);
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('question_rules')) {
            Schema::create('question_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('question_id')->constrained('questions')->cascadeOnDelete();        // question à afficher
                $t->foreignId('source_question_id')->constrained('questions')->cascadeOnDelete(); // question qui déclenche
                $t->string('operator', 20);
                $t->string('value')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('responses')) {
            Schema::create('responses', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('study_id')->constrained()->cascadeOnDelete();
                $t->foreignId('study_version_id')->constrained('study_versions');
                $t->string('status', 20)->default('complete');
                $t->timestamp('submitted_at');
                $t->timestamps();
                $t->index(['study_id', 'submitted_at']);
            });
        }

        if (! Schema::hasTable('answers')) {
            Schema::create('answers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('response_id')->constrained('responses')->cascadeOnDelete();
                $t->unsignedBigInteger('question_id')->index(); // pas de FK : la question peut être supprimée plus tard
                $t->json('value');
                $t->timestamps();
            });
        }

        // Identité séparée des réponses, uniquement pour les études "identifiées".
        if (! Schema::hasTable('participant_identifiers')) {
            Schema::create('participant_identifiers', function (Blueprint $t) {
                $t->id();
                $t->foreignId('response_id')->unique()->constrained('responses')->cascadeOnDelete();
                $t->string('full_name', 150);
                $t->string('email', 190);
                $t->string('phone', 30)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('consents')) {
            Schema::create('consents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('response_id')->constrained('responses')->cascadeOnDelete();
                $t->boolean('accepted');
                $t->string('notice_version', 20);
                $t->timestamp('accepted_at');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->unsignedBigInteger('organization_id')->nullable()->index();
                $t->string('action', 60);
                $t->string('entity_type', 60)->nullable();
                $t->unsignedBigInteger('entity_id')->nullable();
                $t->json('metadata')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'consents', 'participant_identifiers', 'answers', 'responses', 'question_rules', 'question_options', 'questions', 'sections', 'study_versions', 'studies', 'organization_members', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
