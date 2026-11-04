<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Question;
use App\Models\QuestionRule;
use App\Models\Section;
use App\Models\SectionRule;
use App\Support\Notifier;
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

    // ---------- Étapes (sections) ----------

    public function storeSection(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);
        $this->guard($study);

        $data = $request->validate($this->sectionRules($study, true));

        $section = DB::transaction(function () use ($study, $data) {
            $section = $study->sections()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'position' => (int) $study->sections()->max('position') + 1,
            ]);
            $this->syncSectionRules($section, $data);

            return $section;
        });

        return response()->json($this->questionnaires->sectionArray($section->load('rules')), 201);
    }

    public function updateSection(Request $request, Section $section): JsonResponse
    {
        $study = $section->study;
        $this->authorize('update', $study);
        $this->guard($study);

        $data = $request->validate($this->sectionRules($study, false));

        DB::transaction(function () use ($section, $data) {
            $section->update(array_intersect_key($data, array_flip(['title', 'description'])));
            $this->syncSectionRules($section, $data);
        });

        return response()->json($this->questionnaires->sectionArray($section->fresh()->load('rules')));
    }

    public function destroySection(Section $section): JsonResponse
    {
        $this->authorize('update', $section->study);
        $this->guard($section->study);
        $section->delete(); // les questions restent, sans étape

        return response()->json(['message' => 'Étape supprimée.']);
    }

    /**
     * Enregistre d'un coup l'agencement complet (glisser-déposer) :
     * { "steps": [ { "id": 3, "questions": [12, 15] }, { "id": null, "questions": [20] } ] }
     * « id » null = questions sans étape. L'ordre des étapes et des questions suit l'ordre envoyé.
     */
    public function layout(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);
        $this->guard($study);

        $data = $request->validate([
            'steps' => ['required', 'array', 'max:100'],
            'steps.*.id' => ['present', 'nullable', 'integer', Rule::exists('sections', 'id')->where('study_id', $study->id)],
            'steps.*.questions' => ['present', 'array', 'max:500'],
            'steps.*.questions.*' => ['integer', 'distinct', Rule::exists('questions', 'id')->where('study_id', $study->id)],
        ]);

        $ids = array_column($data['steps'], 'id');
        $named = array_filter($ids, fn ($i) => $i !== null);
        if (count($named) !== count(array_unique($named)) || count($ids) - count($named) > 1) {
            return response()->json(['message' => 'Agencement invalide : une étape est en double.'], 422);
        }

        DB::transaction(function () use ($data) {
            $stepPosition = 0;
            foreach ($data['steps'] as $step) {
                if ($step['id'] !== null) {
                    Section::whereKey($step['id'])->update(['position' => ++$stepPosition]);
                }
                foreach (array_values($step['questions']) as $i => $questionId) {
                    Question::whereKey($questionId)->update(['section_id' => $step['id'], 'position' => $i + 1]);
                }
            }
        });

        return response()->json($this->questionnaires->tree($study));
    }

    private function sectionRules(Study $study, bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rules' => ['sometimes', 'array', 'max:10'],
            'rules.*.source_question_id' => ['required', 'integer', Rule::exists('questions', 'id')->where('study_id', $study->id)],
            'rules.*.operator' => ['required', Rule::in(QuestionRule::OPERATORS)],
            'rules.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function syncSectionRules(Section $section, array $data): void
    {
        if (! array_key_exists('rules', $data)) {
            return;
        }
        $this->assertRuleSources($data['rules'] ?? []);
        $section->rules()->delete();
        foreach ($data['rules'] ?? [] as $rule) {
            $section->rules()->create([
                'source_question_id' => $rule['source_question_id'],
                'operator' => $rule['operator'],
                'value' => $rule['value'] ?? null,
            ]);
        }
    }

    /** Une condition ne peut pas se baser sur un texte informatif ou une position. */
    private function assertRuleSources(array $rules): void
    {
        $ids = array_column($rules, 'source_question_id');
        if ($ids && Question::whereIn('id', $ids)->whereIn('type', Question::NON_REFERENCEABLE)->exists()) {
            throw ValidationException::withMessages(['rules' => ['Une condition ne peut pas dépendre d\'un texte informatif ou d\'une position.']]);
        }
    }

    /** Cohérence des réglages selon le type de question. */
    private function checkSettings(string $type, array $s): void
    {
        $fail = static function (string $m): void {
            throw ValidationException::withMessages(['settings' => [$m]]);
        };
        $numeric = ['integer', 'decimal', 'number', 'scale'];
        if (! empty($s['allow_other']) && ! in_array($type, ['single_choice', 'multiple_choice'], true)) {
            $fail('Le choix « Autre » est disponible uniquement pour les questions à choix unique ou multiple.');
        }
        $formats = ['date' => '/^\d{4}-\d{2}-\d{2}$/', 'time' => '/^\d{2}:\d{2}$/', 'datetime' => '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/'];

        foreach (['min', 'max'] as $k) {
            if (! isset($s[$k]) || $s[$k] === '') {
                continue;
            }
            if (in_array($type, $numeric, true) && ! is_numeric($s[$k])) {
                $fail("Le réglage « $k » doit être un nombre.");
            }
            if (isset($formats[$type]) && ! preg_match($formats[$type], (string) $s[$k])) {
                $fail("Le réglage « $k » n'a pas le bon format pour ce type de question.");
            }
        }
        if (isset($s['min'], $s['max']) && $s['min'] !== '' && $s['max'] !== '' && $s['min'] > $s['max']) {
            $fail('Le minimum ne peut pas dépasser le maximum.');
        }
        if (isset($s['min_length'], $s['max_length']) && $s['min_length'] > $s['max_length']) {
            $fail('La longueur minimale ne peut pas dépasser la longueur maximale.');
        }
        if (isset($s['min_choices'], $s['max_choices']) && $s['min_choices'] > $s['max_choices']) {
            $fail('Le nombre minimal de choix ne peut pas dépasser le maximum.');
        }
    }

    // ---------- Questions ----------

    public function storeQuestion(Request $request, Study $study): JsonResponse
    {
        $this->authorize('update', $study);
        $this->guard($study);
        $data = $request->validate($this->questionRules($study, null));
        $this->checkSettings($data['type'], $data['settings'] ?? []);

        $question = DB::transaction(function () use ($study, $data) {
            $question = $study->questions()->create([
                'section_id' => $data['section_id'] ?? null,
                'type' => $data['type'],
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'required' => $data['type'] === 'note' ? false : ($data['required'] ?? false),
                'settings' => $data['settings'] ?? null,
                'position' => (int) $study->questions()->max('position') + 1,
            ]);
            $this->syncOptionsAndRules($question, $data);

            return $question;
        });

        Notifier::notify($study->organization_id, $request->user(), 'question.created', $study);

        return response()->json($this->questionnaires->questionArray($question->load(['options', 'rules'])), 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        $study = $question->study;
        $this->authorize('update', $study);
        $this->guard($study);
        $data = $request->validate($this->questionRules($study, $question));
        $this->checkSettings($data['type'] ?? $question->type, $data['settings'] ?? []);
        $newType = $data['type'] ?? $question->type;
        if ($newType !== $question->type) {
            $this->assertTypeChangeAllowed($question, $newType);
        }
        if ($newType === 'note') {
            $data['required'] = false;
        }

        DB::transaction(function () use ($question, $data, $newType) {
            $question->update(array_intersect_key($data, array_flip(['section_id', 'type', 'label', 'description', 'required', 'settings'])));
            $this->syncOptionsAndRules($question, $data);
            if (! in_array($newType, Question::CHOICE_TYPES, true)) {
                $question->options()->delete(); // un type sans choix ne garde pas d'anciens choix
            }
        });

        Notifier::notify($study->organization_id, $request->user(), 'question.updated', $study);

        return response()->json($this->questionnaires->questionArray($question->fresh()->load(['options', 'rules'])));
    }

    /** Types dont les réponses déjà reçues restent lisibles après un changement de l'un vers l'autre. */
    private const COMPATIBLE_TYPES = [
        ['short_text', 'long_text'],
        ['integer', 'decimal', 'number'],
        ['single_choice', 'dropdown'],
    ];

    /** Changer le type est libre tant qu'aucune réponse n'existe ; ensuite, seulement entre types compatibles. */
    private function assertTypeChangeAllowed(Question $question, string $newType): void
    {
        if (in_array($newType, Question::NON_REFERENCEABLE, true)
            && (QuestionRule::where('source_question_id', $question->id)->exists()
                || SectionRule::where('source_question_id', $question->id)->exists())) {
            throw ValidationException::withMessages(['type' => ['Des conditions dépendent de cette question : retirez-les avant de la transformer en texte informatif ou en position.']]);
        }

        if (Answer::where('question_id', $question->id)->exists()) {
            foreach (self::COMPATIBLE_TYPES as $family) {
                if (in_array($question->type, $family, true) && in_array($newType, $family, true)) {
                    return;
                }
            }
            throw ValidationException::withMessages(['type' => ['Cette question a déjà reçu des réponses : son type ne peut être changé que vers un type compatible (texte court ou long, nombres, choix unique ou liste). Sinon, ajoutez une nouvelle question.']]);
        }
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

    public function destroyQuestion(Request $request, Question $question): JsonResponse
    {
        $study = $question->study;
        $this->authorize('update', $study);
        $this->guard($study);
        $question->delete();
        Notifier::notify($study->organization_id, $request->user(), 'question.deleted', $study);

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
            'settings.min' => ['nullable'],
            'settings.max' => ['nullable'],
            'settings.min_length' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'settings.format' => ['nullable', Rule::in(['email', 'phone', 'url'])],
            'settings.decimals' => ['nullable', 'integer', 'min:0', 'max:6'],
            'settings.precision' => ['nullable', 'integer', 'min:0', 'max:6'],
            'settings.max_length' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'settings.min_choices' => ['nullable', 'integer', 'min:1'],
            'settings.max_choices' => ['nullable', 'integer', 'min:1'],
            'settings.allow_other' => ['nullable', 'boolean'],
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
                if (str_starts_with($value, '__dobi_other__:')) {
                    throw ValidationException::withMessages(['options' => ['Cette valeur est réservée au fonctionnement du choix « Autre ».']]);
                }
                if (isset($seen[$value])) {
                    throw ValidationException::withMessages(['options' => ["Valeur de choix en double : « $value »."]]);
                }
                $seen[$value] = true;
                $question->options()->create(['label' => $option['label'], 'value' => $value, 'position' => $i + 1]);
            }
        }

        if (array_key_exists('rules', $data)) {
            $this->assertRuleSources($data['rules'] ?? []);
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
