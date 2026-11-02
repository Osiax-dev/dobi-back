<?php

namespace App\Support;

final class Roles
{
    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';
    public const SURVEYOR = 'surveyor';
    public const ANALYST = 'analyst';

    /** Rôles attribuables à un membre (le propriétaire est unique). */
    public const ASSIGNABLE = ['admin', 'editor', 'surveyor', 'analyst'];
}
