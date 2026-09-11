Bonjour,

Votre compte {{ $appName }} a bien été créé.

Vous pouvez dès à présent vous connecter pour compléter et soumettre votre
candidature depuis votre espace candidat :

    {!! $lien !!}

{{-- Non échappé : e-mail TEXTE — cf. leçon Lot 13 (Blade échappe même en
     texte brut). Pas de « & » ici, mais convention uniforme sur tout lien. --}}

— L'équipe {{ $appName }}
