<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Section;
use App\Models\Study;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuestionnaireService
{
    /** Questions triées : sections (dans l'ordre), puis position. Les questions sans section passent en premier. */
    public function orderedQuestions(Study $study): Collection
    {
        return $study->questions()->with(['options', 'rules', 'section'])->get()
            ->sort(function ($a, $b) {
                $sa = $a->section?->position ?? -1;
                $sb = $b->section?->position ?? -1;

                return [$sa, $a->position, $a->id] <=> [$sb, $b->position, $b->id];
            })->values();
    }

    /** Représentation complète pour le constructeur (brouillon). */
    public function tree(Study $study): array
    {
        return [
            'sections' => $study->sections()->get()->map(fn (Section $s) => [
                'id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'position' => $s->position,
            ])->all(),
            'questions' => $this->orderedQuestions($study)->map(fn (Question $q) => $this->questionArray($q))->all(),
        ];
    }

    public function questionArray(Question $q): array
    {
        return [
            'id' => $q->id,
            'section_id' => $q->section_id,
            'type' => $q->type,
            'label' => $q->label,
            'description' => $q->description,
            'required' => (bool) $q->required,
            'position' => $q->position,
            'settings' => $q->settings ?: new \stdClass(),
            'options' => $q->options->map(fn ($o) => ['value' => $o->value, 'label' => $o->label])->values()->all(),
            'rules' => $q->rules->map(fn ($r) => [
                'source_question_id' => $r->source_question_id, 'operator' => $r->operator, 'value' => $r->value,
            ])->values()->all(),
        ];
    }

    /** Version figée du questionnaire, stockée dans study_versions.snapshot. */
    public function snapshot(Study $study): array
    {
        $tree = $this->tree($study);
        // Les settings vides restent des tableaux vides côté JSON stocké.
        foreach ($tree['questions'] as &$q) {
            $q['settings'] = (array) $q['settings'];
        }

        return $tree;
    }

    /** Vérifie qu'une étude peut être publiée. */
    public function assertPublishable(Study $study): void
    {
        $questions = $this->orderedQuestions($study);
        $errors = [];

        if ($questions->isEmpty()) {
            $errors['questions'][] = 'Ajoutez au moins une question avant de publier.';
        }

        $position = [];
        foreach ($questions as $index => $q) {
            $position[$q->id] = $index;
        }

        foreach ($questions as $index => $q) {
            if (in_array($q->type, Question::CHOICE_TYPES, true) && $q->options->count() < 2) {
                $errors['questions'][] = "La question « {$q->label} » doit avoir au moins 2 choix.";
            }
            foreach ($q->rules as $rule) {
                if (! isset($position[$rule->source_question_id]) || $position[$rule->source_question_id] >= $index) {
                    $errors['questions'][] = "La condition de la question « {$q->label} » doit dépendre d'une question située avant elle.";
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Copie une étude (brouillon) avec ses sections, questions, choix et conditions. */
    public function duplicate(Study $study, User $user, string $slug): Study
    {
        return DB::transaction(function () use ($study, $user, $slug) {
            $copy = $study->replicate(['current_version_id', 'published_at', 'slug', 'status']);
            $copy->title = Str::limit($study->title, 185, '').' (copie)';
            $copy->slug = $slug;
            $copy->status = 'draft';
            $copy->created_by = $user->id;
            $copy->save();

            $sectionMap = [];
            foreach ($study->sections()->get() as $section) {
                $sectionMap[$section->id] = $copy->sections()->create([
                    'title' => $section->title, 'description' => $section->description, 'position' => $section->position,
                ])->id;
            }

            $questionMap = [];
            $old = $study->questions()->with(['options', 'rules'])->orderBy('position')->orderBy('id')->get();
            foreach ($old as $q) {
                $new = $copy->questions()->create([
                    'section_id' => $q->section_id ? ($sectionMap[$q->section_id] ?? null) : null,
                    'type' => $q->type, 'label' => $q->label, 'description' => $q->description,
                    'required' => $q->required, 'position' => $q->position, 'settings' => $q->settings,
                ]);
                $questionMap[$q->id] = $new->id;
                foreach ($q->options as $o) {
                    $new->options()->create(['label' => $o->label, 'value' => $o->value, 'position' => $o->position]);
                }
            }
            foreach ($old as $q) {
                foreach ($q->rules as $r) {
                    if (isset($questionMap[$r->source_question_id])) {
                        $new = Question::find($questionMap[$q->id]);
                        $new->rules()->create([
                            'source_question_id' => $questionMap[$r->source_question_id],
                            'operator' => $r->operator, 'value' => $r->value,
                        ]);
                    }
                }
            }

            return $copy;
        });
    }
}
