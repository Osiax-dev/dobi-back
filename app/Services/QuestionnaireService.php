<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Question;
use App\Models\Section;
use App\Models\SectionRule;
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
        $questions = $this->orderedQuestions($study);
        // Questions qui ont déjà reçu des réponses : leur type ne peut plus changer librement.
        $answered = Answer::whereIn('question_id', $questions->pluck('id'))->distinct()->pluck('question_id')->flip();

        return [
            'sections' => $study->sections()->with('rules')->get()->map(fn (Section $s) => $this->sectionArray($s))->all(),
            'questions' => $questions->map(fn (Question $q) => $this->questionArray($q) + ['has_answers' => $answered->has($q->id)])->all(),
        ];
    }

    public function sectionArray(Section $s): array
    {
        return [
            'id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'position' => $s->position,
            'rules' => $s->rules->map(fn ($r) => [
                'source_question_id' => $r->source_question_id, 'operator' => $r->operator, 'value' => $r->value,
            ])->values()->all(),
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
        } elseif ($questions->every(fn ($q) => $q->type === 'note')) {
            $errors['questions'][] = 'Ajoutez au moins une question à laquelle le participant peut répondre.';
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

        // Une condition sur une étape doit dépendre d'une question située avant la première question de l'étape.
        foreach ($study->sections()->with('rules')->get() as $section) {
            $first = $questions->search(fn ($q) => $q->section_id === $section->id);
            foreach ($section->rules as $rule) {
                if ($first !== false && (! isset($position[$rule->source_question_id]) || $position[$rule->source_question_id] >= $first)) {
                    $errors['sections'][] = "La condition de l'étape « {$section->title} » doit dépendre d'une question située avant cette étape.";
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

            foreach ($study->sections()->with('rules')->get() as $section) {
                foreach ($section->rules as $r) {
                    if (isset($sectionMap[$section->id], $questionMap[$r->source_question_id])) {
                        SectionRule::create([
                            'section_id' => $sectionMap[$section->id],
                            'source_question_id' => $questionMap[$r->source_question_id],
                            'operator' => $r->operator, 'value' => $r->value,
                        ]);
                    }
                }
            }

            return $copy;
        });
    }

    /** Questionnaire sous forme de modèle : étapes, questions, choix et conditions (identifiants locaux, aucune réponse). */
    public function exportTree(Study $study): array
    {
        return $this->snapshot($study);
    }

    /** Crée les étapes, questions, choix et conditions d'un modèle dans une étude (identifiants recréés). */
    public function applyTree(Study $study, array $tree): void
    {
        DB::transaction(function () use ($study, $tree) {
            $sectionMap = [];
            foreach ($tree['sections'] ?? [] as $s) {
                $sectionMap[$s['id']] = $study->sections()->create([
                    'title' => $s['title'], 'description' => $s['description'] ?? null, 'position' => $s['position'] ?? 0,
                ])->id;
            }

            $questionMap = [];
            foreach ($tree['questions'] ?? [] as $q) {
                $new = $study->questions()->create([
                    'section_id' => ! empty($q['section_id']) ? ($sectionMap[$q['section_id']] ?? null) : null,
                    'type' => $q['type'], 'label' => $q['label'], 'description' => $q['description'] ?? null,
                    'required' => (bool) ($q['required'] ?? false), 'position' => $q['position'] ?? 0,
                    'settings' => (array) ($q['settings'] ?? []),
                ]);
                $questionMap[$q['id']] = $new->id;
                foreach (array_values($q['options'] ?? []) as $i => $o) {
                    $new->options()->create(['label' => $o['label'], 'value' => $o['value'], 'position' => $i + 1]);
                }
            }

            foreach ($tree['questions'] ?? [] as $q) {
                foreach ($q['rules'] ?? [] as $r) {
                    if (isset($questionMap[$r['source_question_id']])) {
                        Question::find($questionMap[$q['id']])->rules()->create([
                            'source_question_id' => $questionMap[$r['source_question_id']],
                            'operator' => $r['operator'], 'value' => $r['value'] ?? null,
                        ]);
                    }
                }
            }

            foreach ($tree['sections'] ?? [] as $s) {
                foreach ($s['rules'] ?? [] as $r) {
                    if (isset($sectionMap[$s['id']], $questionMap[$r['source_question_id']])) {
                        SectionRule::create([
                            'section_id' => $sectionMap[$s['id']],
                            'source_question_id' => $questionMap[$r['source_question_id']],
                            'operator' => $r['operator'], 'value' => $r['value'] ?? null,
                        ]);
                    }
                }
            }
        });
    }
}
