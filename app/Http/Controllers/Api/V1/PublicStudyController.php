<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Answer;
use App\Models\Consent;
use App\Models\Organization;
use App\Models\ParticipantIdentifier;
use App\Models\Study;
use App\Models\Submission;
use App\Support\Notifier;
use App\Support\SubmissionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicStudyController extends Controller
{
    /** Page publique d'une organisation : présentation + études publiées et ouvertes uniquement. */
    public function organization(string $slug): JsonResponse
    {
        $organization = Organization::where('slug', $slug)->firstOrFail();

        $studies = $organization->studies()
            ->where('status', 'published')
            ->whereNotNull('current_version_id')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->latest()->get()
            ->map(fn (Study $s) => [
                'title' => $s->title, 'slug' => $s->slug, 'description' => $s->description,
                'category' => $s->category, 'public_url' => $s->public_url,
            ]);

        return response()->json([
            'organization' => ['name' => $organization->display_name, 'slug' => $organization->slug, 'description' => $organization->description],
            'studies' => $studies,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $study = $this->findOpenStudy($slug);
        $version = $study->currentVersion;

        return response()->json([
            'study' => [
                'title' => $study->title,
                'slug' => $study->slug,
                'description' => $study->description,
                'objective' => $study->objective,
                'category' => $study->category,
                'target_population' => $study->target_population,
                'geographic_zone' => $study->geographic_zone,
                'collection_mode' => $study->collection_mode,
                'privacy_mode' => $study->privacy_mode,
                'one_response_per_person' => (bool) $study->one_response_per_person,
                'ends_at' => $study->ends_at,
                'organization' => ['name' => $study->organization->display_name, 'slug' => $study->organization->slug],
            ],
            'privacy_notice' => $this->privacyNotice($study),
            'version' => $version->version_number,
            'questionnaire' => $version->snapshot,
        ]);
    }

    /** Collecte par un enquêteur connecté : même validation que le public, réponse rattachée à l'enquêteur. */
    public function collect(Request $request, string $slug): JsonResponse
    {
        $this->authorize('collect', $this->findOpenStudy($slug));
        $request->attributes->set('collected_by', $request->user()->id);

        return $this->submit($request, $slug);
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        // Champ piège (anti-robots) : un humain ne le remplit jamais.
        if ($request->filled('website')) {
            return response()->json(['message' => 'Merci, votre réponse a été enregistrée.'], 201);
        }

        $study = $this->findOpenStudy($slug);
        $identified = $study->privacy_mode === 'identified';

        $request->validate([
            'answers' => ['required', 'array', 'max:200'],
            'client_token' => ['nullable', 'string', 'max:64'],
            'version' => ['nullable', 'integer', 'min:1'],
            'consent' => $identified ? ['accepted'] : ['sometimes'],
            'participant' => $identified ? ['required', 'array'] : ['prohibited'],
            'participant.full_name' => [$identified ? 'required' : 'nullable', 'string', 'max:150'],
            'participant.email' => [$identified ? 'required' : 'nullable', 'email', 'max:190'],
            'participant.phone' => ['nullable', 'string', 'max:30'],
        ], [
            'answers.required' => 'Aucune réponse reçue.',
            'consent.accepted' => 'Vous devez accepter le traitement de vos données pour participer.',
            'participant.required' => 'Vos informations sont nécessaires pour cette étude.',
            'participant.prohibited' => "Cette étude est anonyme : n'envoyez aucune information personnelle.",
            'participant.full_name.required' => 'Indiquez votre nom.',
            'participant.email.required' => 'Indiquez votre adresse e-mail.',
            'participant.email.email' => 'Adresse e-mail invalide.',
        ]);

        // Renvoi d'une réponse déjà reçue (synchronisation hors ligne) : on renvoie le même reçu, sans doublon.
        $token = $request->input('client_token');
        if ($token && ($existing = Submission::where('study_id', $study->id)->where('client_token', $token)->first())) {
            return response()->json(['message' => 'Réponse déjà enregistrée.', 'receipt' => $existing->uuid], 200);
        }

        // Une seule réponse par personne : fiable en mode identifié (même adresse e-mail), impossible à garantir en mode anonyme.
        if ($identified && $study->one_response_per_person) {
            $already = DB::table('participant_identifiers as p')
                ->join('responses as r', 'r.id', '=', 'p.response_id')
                ->where('r.study_id', $study->id)
                ->whereRaw('LOWER(p.email) = ?', [mb_strtolower(trim((string) $request->input('participant.email')))])
                ->exists();
            if ($already) {
                return response()->json(['message' => 'Cette adresse e-mail a déjà répondu à cette étude. Une seule réponse par personne est acceptée.', 'code' => 'already_responded'], 409);
            }
        }

        // Le participant répond à la version qu'il a vue, même si une nouvelle a été publiée depuis.
        $version = $study->currentVersion;
        if ($request->filled('version')) {
            $version = $study->versions()->where('version_number', (int) $request->input('version'))->first();
            if (! $version) {
                return response()->json(['message' => 'Version du questionnaire inconnue.'], 422);
            }
        }
        $result = SubmissionValidator::validate($version->snapshot, $request->input('answers'));

        if ($result['errors']) {
            $errors = [];
            foreach ($result['errors'] as $questionId => $messages) {
                $errors["answers.$questionId"] = $messages;
            }

            return response()->json(['message' => 'Certaines réponses sont invalides.', 'errors' => $errors], 422);
        }

        $submission = DB::transaction(function () use ($study, $version, $result, $identified, $request, $token) {
            $now = now();
            $submission = Submission::create([
                'uuid' => (string) Str::uuid(),
                'study_id' => $study->id,
                'study_version_id' => $version->id,
                'client_token' => $token ?: null,
                'collected_by' => $request->attributes->get('collected_by'),
                'status' => 'complete',
                'submitted_at' => $now,
            ]);

            $rows = [];
            foreach ($result['clean'] as $questionId => $value) {
                $rows[] = [
                    'response_id' => $submission->id,
                    'question_id' => (int) $questionId,
                    'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Answer::insert($rows);

            if ($identified) {
                ParticipantIdentifier::create([
                    'response_id' => $submission->id,
                    'full_name' => $request->input('participant.full_name'),
                    'email' => $request->input('participant.email'),
                    'phone' => $request->input('participant.phone'),
                ]);
                Consent::create([
                    'response_id' => $submission->id,
                    'accepted' => true,
                    'notice_version' => config('dobi.privacy_notice_version'),
                    'accepted_at' => $now,
                ]);
            }

            return $submission;
        });

        Notifier::notify($study->organization_id, null, 'response.received', $study);

        return response()->json([
            'message' => 'Merci, votre réponse a été enregistrée.',
            'receipt' => $submission->uuid,
        ], 201);
    }

    private function findOpenStudy(string $slug): Study
    {
        $study = Study::with(['currentVersion', 'organization'])->where('slug', $slug)->firstOrFail();

        if ($study->status === 'closed') {
            abort(410, 'Cette étude est clôturée.');
        }
        if (! $study->isOpenForCollection()) {
            abort(404, 'Étude introuvable ou non ouverte.');
        }

        return $study;
    }

    /** Texte de transparence — à faire valider (cadre légal béninois) avant la mise en production. */
    private function privacyNotice(Study $study): array
    {
        if ($study->privacy_mode === 'anonymous') {
            return [
                'version' => config('dobi.privacy_notice_version'),
                'text' => "Cette étude est anonyme : aucun nom, e-mail ni numéro de téléphone n'est demandé. Vos réponses sont utilisées par l'organisation « {$study->organization->display_name} » dans le but décrit dans l'étude.",
            ];
        }

        return [
            'version' => config('dobi.privacy_notice_version'),
            'text' => "Cette étude est identifiée : votre nom, votre e-mail et éventuellement votre téléphone sont demandés. Ils sont conservés séparément de vos réponses et accessibles uniquement aux responsables de l'étude de « {$study->organization->display_name} ». En participant, vous acceptez ce traitement.",
        ];
    }
}
