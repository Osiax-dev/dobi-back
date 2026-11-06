<?php

namespace App\Services;

use App\Models\Report;
use App\Support\ReportAiPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Suggestion de texte par l'IA (Groq ou Anthropic). La clé est lue dans l'environnement du serveur, jamais côté navigateur. */
class ReportAssistant
{
    public static function enabled(): bool
    {
        return self::apiKey() !== '';
    }

    private static function groq(): bool
    {
        return config('dobi.ai.provider') === 'groq';
    }

    private static function apiKey(): string
    {
        return (string) config(self::groq() ? 'dobi.ai.groq_key' : 'dobi.ai.key');
    }

    /** @throws \RuntimeException si l'IA est injoignable ou répond de travers (le détail va dans les journaux, sans contenu). */
    public function suggest(Report $report, string $section, ?string $instructions = null, ?string $current = null): string
    {
        $system = ReportAiPrompt::system($section);
        $user = ReportAiPrompt::user($report->content, $report->notes ?? [], $section, $instructions, $current);
        $http = Http::timeout((int) config('dobi.ai.timeout', 40))->connectTimeout(15)->acceptJson();
        // Certains réseaux ont un IPv6 cassé : PHP y reste bloqué jusqu'au délai. On force l'IPv4.
        $http = $http->withOptions(['force_ip_resolve' => 'v4']);

        $response = self::groq()
            ? $http->withToken(self::apiKey())->post('https://api.groq.com/openai/v1/chat/completions', [
                'model' => (string) config('dobi.ai.groq_model'),
                'max_completion_tokens' => 3500, // les modèles « gpt-oss » réfléchissent avant de répondre : on leur laisse la place
                'temperature' => 0.4,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ] + (str_contains((string) config('dobi.ai.groq_model'), 'gpt-oss') ? ['reasoning_effort' => 'low'] : []))
            : $http->withHeaders(['x-api-key' => self::apiKey(), 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) config('dobi.ai.model'),
                'max_tokens' => 1000,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ]);

        if ($response->failed()) {
            Log::warning('Assistance IA des rapports : échec HTTP '.$response->status());
            throw new \RuntimeException('ai_http_'.$response->status());
        }

        $text = self::groq()
            ? trim((string) $response->json('choices.0.message.content', ''))
            : trim(collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n"));
        if ($text === '') {
            Log::warning('Assistance IA des rapports : réponse vide');
            throw new \RuntimeException('ai_empty');
        }

        return mb_substr($text, 0, 4000);
    }
}
