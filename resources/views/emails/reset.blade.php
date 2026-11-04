@extends('emails.layout')
@section('title', 'Réinitialisation du mot de passe')
@section('content')
<h1 style="margin:0 0 12px;font-size:20px;">Bonjour {{ $name }},</h1>
<p style="margin:0 0 16px;">Vous avez demandé à réinitialiser votre mot de passe DOBI.</p>
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:#d5f06a;color:#102f2b;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;">Choisir un nouveau mot de passe</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6f6b;">Ce lien est valable {{ $minutes }} minutes. Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :</p>
<p style="margin:0 0 16px;font-size:12px;word-break:break-all;color:#5b6f6b;">{{ $url }}</p>
<p style="margin:0;font-size:13px;color:#5b6f6b;">Si vous n'avez rien demandé, ignorez ce message : votre mot de passe reste inchangé.</p>
@endsection
