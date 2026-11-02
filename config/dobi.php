<?php

return [
    // Première URL de FRONTEND_URL = URL publique utilisée pour les liens (études, QR code, reset mot de passe).
    'frontend_url' => trim(explode(',', env('FRONTEND_URL', 'http://localhost:5173'))[0]),

    // Nombre minimum de réponses pour afficher une statistique (protection des petits groupes).
    'min_group_size' => (int) env('DOBI_MIN_GROUP', 5),

    // Durée de vie des tokens d'API (jours).
    'token_days' => (int) env('DOBI_TOKEN_DAYS', 7),

    'privacy_notice_version' => '1.0',

    // Compte administrateur créé par le seeder.
    'admin_email' => env('DOBI_ADMIN_EMAIL'),
    'admin_password' => env('DOBI_ADMIN_PASSWORD'),
];
