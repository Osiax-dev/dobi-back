<?php

return [
    // Première URL de FRONTEND_URL = URL publique utilisée pour les liens (études, QR code, reset mot de passe).
    'frontend_url' => trim(explode(',', env('FRONTEND_URL', 'http://localhost:5173'))[0]),

    // Nombre minimum de réponses pour afficher une statistique (protection des petits groupes).
    'min_group_size' => (int) env('DOBI_MIN_GROUP', 5),

    // Durée de vie des tokens d'API (jours).
    'token_days' => (int) env('DOBI_TOKEN_DAYS', 7),

    // Assistance IA des rapports (facultative). Sans clé, la fonction reste désactivée.
    // Fournisseur : « groq » (gratuit avec limites) ou « anthropic ». Par défaut : groq si GROQ_API_KEY existe.
    'ai' => [
        'provider' => env('DOBI_AI_PROVIDER') ?: (env('GROQ_API_KEY') ? 'groq' : 'anthropic'),
        'groq_key' => env('GROQ_API_KEY'),
        'groq_model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('DOBI_AI_MODEL', 'claude-sonnet-5-5'),
        'timeout' => (int) env('DOBI_AI_TIMEOUT', 40),
    ],

    'privacy_notice_version' => '1.0',

    // Compte administrateur créé par le seeder.
    'admin_email' => env('DOBI_ADMIN_EMAIL'),
    'admin_password' => env('DOBI_ADMIN_PASSWORD'),
];
