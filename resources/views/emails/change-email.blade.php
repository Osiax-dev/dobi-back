@extends('emails.layout')
@section('title', 'Confirmez votre nouvelle adresse e-mail')
@section('content')
<h1 style="margin:0 0 12px;font-size:20px;">Bonjour {{ $name }},</h1>
<p style="margin:0 0 16px;">Vous avez demandé à utiliser <strong>{{ $email }}</strong> comme adresse e-mail de votre compte DOBI. Confirmez ce changement :</p>
<p style="margin:24px 0;text-align:center;"><a href="{{ $url }}" style="display:inline-block;background:#d5f06a;color:#102f2b;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:8px;">Confirmer ma nouvelle adresse</a></p>
<p style="margin:0 0 8px;font-size:13px;color:#5b6f6b;">Ce lien est valable 1 heure. Après confirmation, vous devrez vous reconnecter avec la nouvelle adresse. Si le bouton ne fonctionne pas, copiez cette adresse dans votre navigateur :</p>
<p style="margin:0 0 16px;font-size:12px;word-break:break-all;color:#5b6f6b;">{{ $url }}</p>
<p style="margin:0;font-size:13px;color:#5b6f6b;">Si vous n'avez rien demandé, ignorez ce message : votre adresse actuelle reste inchangée.</p>
@endsection
