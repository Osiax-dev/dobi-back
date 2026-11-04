<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /** Crée le compte administrateur initial (DOBI_ADMIN_EMAIL / DOBI_ADMIN_PASSWORD dans .env). */
    public function run(): void
    {
        $email = config('dobi.admin_email');
        if (! $email) {
            $this->command?->warn('DOBI_ADMIN_EMAIL absent du .env : aucun compte créé.');

            return;
        }

        if (User::where('email', $email)->exists()) {
            $this->command?->info("Le compte $email existe déjà.");

            return;
        }

        $generated = ! config('dobi.admin_password');
        $password = config('dobi.admin_password') ?: Str::password(14, symbols: false);

        $user = User::create(['name' => 'Administrateur DOBI', 'email' => $email, 'password' => $password]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Organization::createFor($user, 'Espace personnel', null, 'personal', $user->name);

        $this->command?->info("Compte créé : $email");
        if ($generated) {
            $this->command?->warn("Mot de passe généré (notez-le, il ne sera plus affiché) : $password");
        }
    }
}
