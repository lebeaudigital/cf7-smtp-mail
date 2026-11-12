<?php
/**
 * Template: Contact > Configuration SMTP.
 *
 * Variables fournies par CF7_SMTP_Admin::render_page().
 *
 * @var array<string, mixed> $settings
 * @var array{type: string, message: string}|null $notice
 * @var string $redirect_uri
 * @var array<string, array{label: string, host: string, port: int, encryption: string}> $presets
 * @var string $connect_url
 * @var string $disconnect_url
 */

if (!defined('ABSPATH')) {
    exit;
}

$mode = (string) $settings['mode'];
?>
<div class="wrap cf7-smtp-wrap">
    <h1>
        <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
        <?php esc_html_e('Configuration SMTP', 'cf7-smtp-mail'); ?>
    </h1>
    <p class="cf7-smtp-intro">
        <?php esc_html_e('Ces réglages s’appliquent aux e-mails envoyés par Contact Form 7.', 'cf7-smtp-mail'); ?>
    </p>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo esc_attr($notice['type'] === 'success' ? 'success' : 'error'); ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <?php if ((string) $settings['last_error'] !== '') : ?>
        <div class="notice notice-error">
            <p>
                <?php
                echo esc_html(sprintf(
                    /* translators: %s: message d'erreur d'envoi */
                    __('Dernier échec d’envoi : %s', 'cf7-smtp-mail'),
                    (string) $settings['last_error']
                ));
                ?>
            </p>
        </div>
    <?php elseif ((string) $settings['last_success_at'] !== '') : ?>
        <div class="notice notice-success">
            <p>
                <?php
                echo esc_html(sprintf(
                    /* translators: %s: date du dernier envoi réussi */
                    __('Dernier envoi réussi : %s', 'cf7-smtp-mail'),
                    (string) $settings['last_success_at']
                ));
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=cf7-smtp-mail')); ?>" id="cf7-smtp-settings">
        <?php wp_nonce_field('cf7_smtp_save_settings', 'cf7_smtp_nonce'); ?>

        <div class="cf7-smtp-card">
            <label class="cf7-smtp-enable" for="cf7-smtp-enabled">
                <input type="checkbox" id="cf7-smtp-enabled" name="enabled" value="1" <?php checked($settings['enabled']); ?>>
                <span>
                    <strong><?php esc_html_e('Activer l’envoi pour Contact Form 7', 'cf7-smtp-mail'); ?></strong>
                    <span class="description"><?php esc_html_e('Le courrier 1 et le courrier 2 des formulaires passent par la connexion choisie.', 'cf7-smtp-mail'); ?></span>
                </span>
            </label>
        </div>

        <div class="cf7-smtp-card">
            <h2><?php esc_html_e('Mode de connexion', 'cf7-smtp-mail'); ?></h2>
            <div class="cf7-smtp-modes">
                <label class="cf7-smtp-mode<?php echo $mode === 'manual' ? ' is-active' : ''; ?>" data-mode="manual">
                    <input type="radio" name="mode" value="manual" <?php checked($mode, 'manual'); ?>>
                    <span class="cf7-smtp-mode-title"><?php esc_html_e('Compte e-mail', 'cf7-smtp-mail'); ?></span>
                    <span class="description"><?php esc_html_e('Serveur SMTP, identifiant et mot de passe.', 'cf7-smtp-mail'); ?></span>
                </label>
                <label class="cf7-smtp-mode<?php echo $mode === 'google' ? ' is-active' : ''; ?>" data-mode="google">
                    <input type="radio" name="mode" value="google" <?php checked($mode, 'google'); ?>>
                    <span class="cf7-smtp-mode-title"><?php esc_html_e('Connexion Google', 'cf7-smtp-mail'); ?></span>
                    <span class="description"><?php esc_html_e('Le compte Gmail fournit l’expéditeur et l’autorisation d’envoi.', 'cf7-smtp-mail'); ?></span>
                </label>
            </div>
        </div>

        <div class="cf7-smtp-card cf7-smtp-panel" data-smtp-panel="manual" <?php echo $mode === 'manual' ? '' : 'hidden'; ?>>
            <h2><?php esc_html_e('Compte e-mail', 'cf7-smtp-mail'); ?></h2>
            <p class="description">
                <?php esc_html_e('Pour Gmail sans connexion Google, créez un mot de passe d’application sur un compte protégé par la validation en deux étapes.', 'cf7-smtp-mail'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-preset"><?php esc_html_e('Fournisseur', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <select id="cf7-smtp-preset">
                            <option value="custom" <?php selected($settings['preset'], 'custom'); ?>>
                                <?php esc_html_e('Personnalisé', 'cf7-smtp-mail'); ?>
                            </option>
                            <?php foreach ($presets as $key => $preset) : ?>
                                <option
                                    value="<?php echo esc_attr($key); ?>"
                                    data-host="<?php echo esc_attr($preset['host']); ?>"
                                    data-port="<?php echo esc_attr((string) $preset['port']); ?>"
                                    data-encryption="<?php echo esc_attr($preset['encryption']); ?>"
                                    <?php selected($settings['preset'], $key); ?>
                                >
                                    <?php echo esc_html($preset['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-host"><?php esc_html_e('Hôte SMTP', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="regular-text" id="cf7-smtp-host" name="smtp_host" value="<?php echo esc_attr((string) $settings['smtp_host']); ?>" placeholder="smtp.example.com">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-port"><?php esc_html_e('Port', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="number" class="small-text" id="cf7-smtp-port" name="smtp_port" min="1" max="65535" value="<?php echo esc_attr((string) $settings['smtp_port']); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-encryption"><?php esc_html_e('Chiffrement', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <select id="cf7-smtp-encryption" name="smtp_encryption">
                            <option value="tls" <?php selected($settings['smtp_encryption'], 'tls'); ?>><?php esc_html_e('TLS', 'cf7-smtp-mail'); ?></option>
                            <option value="ssl" <?php selected($settings['smtp_encryption'], 'ssl'); ?>><?php esc_html_e('SSL', 'cf7-smtp-mail'); ?></option>
                            <option value="" <?php selected($settings['smtp_encryption'], ''); ?>><?php esc_html_e('Aucun', 'cf7-smtp-mail'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-username"><?php esc_html_e('Identifiant', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="regular-text" id="cf7-smtp-username" name="smtp_username" value="<?php echo esc_attr((string) $settings['smtp_username']); ?>" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-password"><?php esc_html_e('Mot de passe', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="password" class="regular-text" id="cf7-smtp-password" name="smtp_password" value="" autocomplete="new-password">
                        <?php if (!empty($settings['has_smtp_password'])) : ?>
                            <p class="description"><?php esc_html_e('Un mot de passe est déjà enregistré. Laissez le champ vide pour le conserver.', 'cf7-smtp-mail'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-from-name"><?php esc_html_e('Nom d’expéditeur', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="regular-text" id="cf7-smtp-from-name" name="from_name" value="<?php echo esc_attr((string) $settings['from_name']); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-from-email"><?php esc_html_e('E-mail d’expéditeur', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="email" class="regular-text" id="cf7-smtp-from-email" name="from_email" value="<?php echo esc_attr((string) $settings['from_email']); ?>">
                        <p class="description"><?php esc_html_e('Cette adresse est imposée à l’envoi. Si le formulaire en indique une autre, elle est placée en Reply-To.', 'cf7-smtp-mail'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="cf7-smtp-card cf7-smtp-panel" data-smtp-panel="google" <?php echo $mode === 'google' ? '' : 'hidden'; ?>>
            <h2><?php esc_html_e('Connexion Google', 'cf7-smtp-mail'); ?></h2>
            <ol class="cf7-smtp-steps">
                <li><?php esc_html_e('Dans Google Cloud Console, activez Gmail API et créez un ID client OAuth de type Application Web.', 'cf7-smtp-mail'); ?></li>
                <li><?php esc_html_e('Ajoutez l’URI de redirection ci-dessous, puis collez ici le Client ID et le Client Secret.', 'cf7-smtp-mail'); ?></li>
                <li><?php esc_html_e('Enregistrez, puis cliquez sur Connecter avec Google.', 'cf7-smtp-mail'); ?></li>
            </ol>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-redirect-uri"><?php esc_html_e('URI de redirection', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <div class="cf7-smtp-copy-row">
                            <input type="text" class="large-text" id="cf7-smtp-redirect-uri" readonly value="<?php echo esc_attr($redirect_uri); ?>">
                            <button type="button" class="button" id="cf7-smtp-copy-uri"><?php esc_html_e('Copier', 'cf7-smtp-mail'); ?></button>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-client-id"><?php esc_html_e('Client ID', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="large-text" id="cf7-smtp-client-id" name="google_client_id" value="<?php echo esc_attr((string) $settings['google_client_id']); ?>" autocomplete="off">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-client-secret"><?php esc_html_e('Client Secret', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="password" class="large-text" id="cf7-smtp-client-secret" name="google_client_secret" value="" autocomplete="new-password">
                        <?php if (!empty($settings['has_google_client_secret'])) : ?>
                            <p class="description"><?php esc_html_e('Un secret est déjà enregistré. Laissez le champ vide pour le conserver.', 'cf7-smtp-mail'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cf7-smtp-google-from-name"><?php esc_html_e('Nom d’expéditeur', 'cf7-smtp-mail'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="regular-text" id="cf7-smtp-google-from-name" name="google_from_name" value="<?php echo esc_attr((string) $settings['google_from_name']); ?>">
                        <p class="description"><?php esc_html_e('L’adresse d’expéditeur reste celle du compte Google. L’expéditeur du formulaire passe en Reply-To s’il est différent.', 'cf7-smtp-mail'); ?></p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($settings['google_connected'])) : ?>
                <div class="cf7-smtp-status is-ok">
                    <strong>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %s: adresse Gmail connectée */
                            __('Connecté en tant que %s', 'cf7-smtp-mail'),
                            (string) $settings['google_email']
                        ));
                        ?>
                    </strong>
                    <dl class="cf7-smtp-applied">
                        <div>
                            <dt><?php esc_html_e('Hôte', 'cf7-smtp-mail'); ?></dt>
                            <dd>smtp.gmail.com</dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Port', 'cf7-smtp-mail'); ?></dt>
                            <dd>587</dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Chiffrement', 'cf7-smtp-mail'); ?></dt>
                            <dd>TLS</dd>
                        </div>
                        <div>
                            <dt><?php esc_html_e('Compte', 'cf7-smtp-mail'); ?></dt>
                            <dd><?php echo esc_html((string) $settings['google_email']); ?></dd>
                        </div>
                    </dl>
                    <p>
                        <a class="button button-secondary" id="cf7-smtp-disconnect" href="<?php echo esc_url($disconnect_url); ?>">
                            <?php esc_html_e('Déconnecter', 'cf7-smtp-mail'); ?>
                        </a>
                    </p>
                </div>
            <?php elseif (!empty($settings['google_client_id']) && !empty($settings['has_google_client_secret'])) : ?>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url($connect_url); ?>">
                        <?php esc_html_e('Connecter avec Google', 'cf7-smtp-mail'); ?>
                    </a>
                </p>
                <p class="description"><?php esc_html_e('Le bouton utilise le Client ID et le Client Secret déjà enregistrés.', 'cf7-smtp-mail'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Enregistrez le Client ID et le Client Secret pour afficher le bouton de connexion.', 'cf7-smtp-mail'); ?></p>
            <?php endif; ?>
        </div>

        <?php submit_button(__('Enregistrer les réglages', 'cf7-smtp-mail')); ?>
    </form>

    <div class="cf7-smtp-card">
        <h2><?php esc_html_e('E-mail de test', 'cf7-smtp-mail'); ?></h2>
        <p class="description"><?php esc_html_e('L’essai utilise les réglages enregistrés, même si l’envoi des formulaires n’est pas encore activé.', 'cf7-smtp-mail'); ?></p>
        <form
            id="cf7-smtp-test"
            data-rest-url="<?php echo esc_url(rest_url('cf7-smtp-mail/v1/test-email')); ?>"
            data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
        >
            <label class="cf7-smtp-test-row" for="cf7-smtp-test-to">
                <span><?php esc_html_e('Destinataire', 'cf7-smtp-mail'); ?></span>
                <input type="email" class="regular-text" id="cf7-smtp-test-to" name="to" required placeholder="vous@example.com">
                <button type="submit" class="button button-secondary" id="cf7-smtp-test-submit">
                    <?php esc_html_e('Envoyer un e-mail de test', 'cf7-smtp-mail'); ?>
                </button>
            </label>
            <p id="cf7-smtp-test-result" class="cf7-smtp-test-result" role="status"></p>
        </form>
    </div>
</div>
