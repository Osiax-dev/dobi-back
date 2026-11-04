@extends('emails.layout')
@section('title', 'Confirmez votre adresse e-mail')
@section('content')
<h1 style="margin:0 0 12px;font-size:20px;">Bonjour {{ $name }},</h1>
<p style="margin:0 0 16px;">Merci de vous être inscrit sur DOBI. Confirmez votre adresse e-mail pour activer votre compte.</p>
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:#d5f06a;color:#102f2b;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;">Confirmer mon adresse e-mail</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6f6b;">Ce lien est valable 24 heures. Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :</p>
<p style="margin:0 0 16px;font-size:12px;word-break:break-all;color:#5b6f6b;">{{ $url }}</p>
<p style="margin:0;font-size:13px;color:#5b6f6b;">Si vous n'êtes pas à l'origine de cette inscription, ignorez ce message : aucun compte ne sera activé.</p>
@endsection
