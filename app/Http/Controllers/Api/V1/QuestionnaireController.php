<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionRule;
use App\Models\Section;
use App\Models\Study;
use App\Services\QuestionnaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionnaireController extends Controller
{
    public function __construct(private QuestionnaireService $questionnaires)
    {
    }

    public function show(Study $study): JsonResponse
    {
        $this->authorize('view', $study);

        return response()->json($this->questionnaires->tree($study));
    }

    // ---------- Sections ----------

    public function storeSection(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);
        $this->guard($study);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $section = $study->sections()->create($data + ['position' => (int) $study->sections()->max('position') + 1]);

        return response()->json($section, 201);
    }

    public function updateSection(Request $request, Section $section): JsonResponse
    {
        $this->authorize('update', $section->study);
        $this->guard($section->study);

        $section->update($request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json($section);
    }

    public function destroySection(Section $section): JsonResponse
    {
        $this->authorize('update', $section->study);
        $this->guard($section->study);
        $section->delete(); // les questions restent, sans section

        return response()->json(['message' => 'Section supprimée.']);
    }

    // ---------- Questions ----------

    public function storeQuestion(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);
        $this->guard($study);
        $data = $request->validate($this->questionRules($study, null));

        $question = DB::transaction(function () use ($study, $data) {
            $question = $study->questions()->create([
                'section_id' => $data['section_id'] ?? null,
                'type' => $data['type'],
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'required' => $data['required'] ?? false,
                'settings' => $data['settings'] ?? null,
                'position' => (int) $study->questions()->max('position') + 1,
            ]);
            $this->syncOptionsAndRules($question, $data);

            return $question;
        });

        return response()->json($this->questionnaires->questionArray($question->load(['options', 'rules'])), 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        $study = $question->study;
        $this->authorize('update', $study);
        $this->guard($study);
        $data = $request->validate($this->questionRules($study, $question));

        DB::transaction(function () use ($question, $data) {
            $question->update(array_intersect_key($data, array_flip(['section_id', 'type', 'label', 'description', 'required', 'settings'])));
            $this->syncOptionsAndRules($question, $data);
        });

        return response()->json($this->questionnaires->questionArray($question->fresh()->load(['options', 'rules'])));
    }

    /** Copie une question (choix et conditions compris) juste après l'originale. */
    public function duplicateQuestion(Question $question): JsonResponse
    {
        $study = $question->study;
        $this->authorize('update', $study);
        $this->guard($study);

        $copy = DB::transaction(function () use ($question, $study) {
            Question::where('study_id', $study->id)->where('section_id', $question->section_id)
                ->where('position', '>', $question->position)->increment('position');

            $copy = $study->questions()->create([
                'section_id' => $question->section_id,
                'type' => $question->type,
                'label' => Str::limit($question->label, 485, '').' (copie)',
                'description' => $question->description,
                'required' => $question->required,
                'settings' => $question->settings,
                'position' => $question->position + 1,
            ]);
            foreach ($question->options as $o) {
                $copy->options()->create(['label' => $o->label, 'value' => $o->value, 'position' => $o->position]);
            }
            foreach ($question->rules as $r) {
                $copy->rules()->create(['source_question_id' => $r->source_question_id, 'operator' => $r->operator, 'value' => $r->value]);
            }

            return $copy;
        });

        return response()->json($this->questionnaires->questionArray($copy->load(['options', 'rules'])), 201);
    }

    public function destroyQuestion(Question $question): JsonResponse
    {
        $this->authorize('update', $question->study);
        $this->guard($question->study);
        $question->delete();

        return response()->json(['message' => 'Question supprimée.']);
    }

    /** Réorganise : { "questions": [ids dans l'ordre], "sections": [ids dans l'ordre] } (les deux sont optionnels). */
    public function reorder(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);
        $this->guard($study);

        $data = $request->validate([
            'questions' => ['sometimes', 'array'],
            'questions.*' => ['integer', Rule::exists('questions', 'id')->where('study_id', $study->id)],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['integer', Rule::exists('sections', 'id')->where('study_id', $study->id)],
        ]);

        DB::transaction(function () use ($data) {
            foreach (array_values($data['questions'] ?? []) as $i => $id) {
                Question::whereKey($id)->update(['position' => $i + 1]);
            }
            foreach (array_values($data['sections'] ?? []) as $i => $id) {
                Section::whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return response()->json($this->questionnaires->tree($study));
    }

    /** Une étude archivée est en lecture seule. */
    private function guard(Study $study): void
    {
        if ($study->status === 'archived') {
            abort(422, 'Cette étude est archivée : restaurez-la pour la modifier.');
        }
    }

    private function questionRules(Study $study, ?Question $question): array
    {
        $creating = $question === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'type' => [$req, Rule::in(Question::TYPES)],
            'label' => [$req, 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'required' => ['sometimes', 'boolean'],
            'section_id' => ['nullable', Rule::exists('sections', 'id')->where('study_id', $study->id)],
            'settings' => ['nullable', 'array'],
            'settings.min' => ['nullable', 'numeric'],
            'settings.max' => ['nullable', 'numeric'],
            'settings.max_length' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'settings.min_choices' => ['nullable', 'integer', 'min:1'],
            'settings.max_choices' => ['nullable', 'integer', 'min:1'],
            'options' => ['sometimes', 'array', 'max:50'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'options.*.value' => ['nullable', 'string', 'max:100'],
            'rules' => ['sometimes', 'array', 'max:10'],
            'rules.*.source_question_id' => ['required', 'integer', Rule::exists('questions', 'id')->where('study_id', $study->id)],
            'rules.*.operator' => ['required', Rule::in(QuestionRule::OPERATORS)],
            'rules.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Remplace les choix et conditions si ils sont fournis dans la requête. */
    private function syncOptionsAndRules(Question $question, array $data): void
    {
        if (array_key_exists('options', $data)) {
            $question->options()->delete();
            // Les choix conservés gardent leur valeur (les anciennes réponses restent valables) ;
            // un nouveau choix reçoit la première valeur « opt_N » libre.
            $taken = [];
            foreach ($data['options'] ?? [] as $o) {
                if (($o['value'] ?? '') !== '') {
                    $taken[$o['value']] = true;
                }
            }
            $seen = [];
            $n = 1;
            foreach (array_values($data['options'] ?? []) as $i => $option) {
                $value = $option['value'] ?? null;
                if ($value === null || $value === '') {
                    while (isset($taken['opt_'.$n])) {
                        $n++;
                    }
                    $value = 'opt_'.$n;
                    $taken[$value] = true;
                }
                if (isset($seen[$value])) {
                    throw ValidationException::withMessages(['options' => ["Valeur de choix en double : « $value »."]]);
                }
                $seen[$value] = true;
                $question->options()->create(['label' => $option['label'], 'value' => $value, 'position' => $i + 1]);
            }
        }

        if (array_key_exists('rules', $data)) {
            $question->rules()->delete();
            foreach ($data['rules'] ?? [] as $rule) {
                if ((int) $rule['source_question_id'] === $question->id) {
                    throw ValidationException::withMessages(['rules' => ['Une question ne peut pas dépendre d\'elle-même.']]);
                }
                $question->rules()->create([
                    'source_question_id' => $rule['source_question_id'],
                    'operator' => $rule['operator'],
                    'value' => $rule['value'] ?? null,
                ]);
            }
        }
    }
}
