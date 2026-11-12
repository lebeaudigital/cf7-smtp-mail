# CF7 SMTP Mail

Configuration SMTP pour les e-mails de Contact Form 7. Le réglage se trouve dans **Contact > Configuration SMTP**.

Deux modes :

- **Compte e-mail** : hôte, port, chiffrement, identifiant et mot de passe. Des presets remplissent Gmail, Outlook, OVH et Infomaniak.
- **Connexion Google** : un compte Gmail autorise l’envoi. L’adresse connectée devient l’expéditeur.

Le plugin ne modifie que les e-mails de Contact Form 7 (courrier 1 et courrier 2).

## Prérequis

- WordPress 6.0 ou supérieur
- PHP 8.0 ou supérieur
- Contact Form 7

## Installation

1. Copiez ce dossier dans `wp-content/plugins/cf7-smtp-mail/`.
2. Activez **CF7 SMTP Mail** dans Extensions.
3. Ouvrez **Contact > Configuration SMTP**.

Installation par Git :

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/lebeaudigital/cf7-smtp-mail.git
```

## Compte e-mail

Choisissez un fournisseur ou saisissez l’hôte vous-même. Pour Gmail sans OAuth, utilisez un mot de passe d’application (validation en deux étapes activée) :

- Hôte : `smtp.gmail.com`
- Port : `587`
- Chiffrement : TLS

L’adresse d’expéditeur enregistrée est imposée. Si le formulaire en indique une autre, elle est placée en Reply-To.

## Connexion Google

Le bouton Google nécessite un projet [Google Cloud Console](https://console.cloud.google.com/). Le mode SMTP manuel n’en a pas besoin.

1. Créez un projet et activez **Gmail API**.
2. Configurez l’écran de consentement OAuth. Ajoutez les scopes `https://www.googleapis.com/auth/gmail.send` et `https://www.googleapis.com/auth/userinfo.email`.
3. En mode Test, ajoutez comme utilisateur test le compte Gmail qui enverra les formulaires. Tant que l’application n’est pas publiée, le jeton expire au bout de 7 jours.
4. Créez un ID client OAuth de type **Application Web**.
5. Ajoutez l’URI de redirection affichée par le plugin, de la forme `https://exemple.com/wp-admin/admin.php?page=cf7-smtp-mail`. Un même client peut lister plusieurs sites. `http://localhost` est accepté.
6. Collez le Client ID et le Client Secret dans le plugin, enregistrez, puis cliquez sur **Connecter avec Google**.

Publier l’application retire l’expiration de 7 jours. L’écran « Application non validée par Google » reste tant que le scope `gmail.send` n’est pas vérifié. Pour quelques boîtes, cet écran suffit.

## E-mail de test

Le bouton d’essai utilise les réglages enregistrés, même si l’envoi des formulaires n’est pas encore activé.

## Mises à jour

Le plugin interroge les [releases GitHub](https://github.com/lebeaudigital/cf7-smtp-mail/releases). WordPress propose la mise à jour lorsqu’une release a un tag plus récent que la version de l’en-tête.

Pour publier une version :

1. Montez `Version` dans `cf7-smtp-mail.php` et la constante `CF7_SMTP_VERSION`.
2. Commitez et poussez sur `main`.
3. Créez une release `vX.Y.Z` sur GitHub.

Le lien **Vérifier mise à jour** dans la liste des extensions force cette lecture.

## Structure

```
cf7-smtp-mail.php
includes/          Classes d'administration, de réglages, d'envoi et de mise à jour
api/               Appels Google et route REST de l'e-mail de test
templates/admin/   Page Configuration SMTP
assets/js/         Un fichier ES6 par écran
assets/css/        Styles d'administration
```

## Licence

GPL v2 ou ultérieure.
