Bonjour,

Vous avez demandé la réinitialisation de votre mot de passe {{ $appName }}.

Ouvrez le lien ci-dessous pour choisir un nouveau mot de passe (valable
{{ $expireDansMinutes }} minutes, utilisable une seule fois) :

    {!! $lien !!}

{{-- Non échappé : ceci est un e-mail TEXTE, pas HTML — {{ }} appliquerait
     quand même htmlspecialchars() (Blade ne fait pas la distinction), ce qui
     transformait « & » en « &amp; » dans l'URL et cassait le lien (le
     paramètre `email` après le `&` disparaissait de la query string reçue). --}}

Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail : votre
mot de passe reste inchangé.

— L'équipe {{ $appName }}
