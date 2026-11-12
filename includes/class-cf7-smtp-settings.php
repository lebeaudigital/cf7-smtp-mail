<?php
/**
 * Lecture, écriture et chiffrement des réglages SMTP.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF7_SMTP_Settings {

    /**
     * Nom de l'option WordPress.
     */
    public const OPTION = 'cf7_smtp_mail_settings';

    /**
     * Algorithme de chiffrement des secrets.
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * Valeurs par défaut.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            'enabled' => false,
            'mode' => 'manual',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'from_email' => '',
            'from_name' => '',
            'google_client_id' => '',
            'google_client_secret' => '',
            'google_refresh_token' => '',
            'google_email' => '',
            'google_from_name' => '',
            'last_error' => '',
            'last_success_at' => '',
        ];
    }

    /**
     * Presets d'hôtes SMTP.
     *
     * @return array<string, array{label: string, host: string, port: int, encryption: string}>
     */
    public static function presets(): array {
        return [
            'gmail' => [
                'label' => 'Gmail',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
            'outlook' => [
                'label' => 'Outlook / Microsoft 365',
                'host' => 'smtp.office365.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
            'ovh' => [
                'label' => 'OVH',
                'host' => 'ssl0.ovh.net',
                'port' => 587,
                'encryption' => 'tls',
            ],
            'infomaniak' => [
                'label' => 'Infomaniak',
                'host' => 'mail.infomaniak.com',
                'port' => 587,
                'encryption' => 'tls',
            ],
        ];
    }

    /**
     * Créer l'option si elle n'existe pas encore.
     */
    public static function ensure_option(): void {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
    }

    /**
     * Réglages bruts, secrets encore chiffrés.
     *
     * @return array<string, mixed>
     */
    public static function stored(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * Réglages utilisables pour l'envoi, secrets déchiffrés.
     *
     * @return array<string, mixed>
     */
    public static function all(): array {
        $settings = self::stored();
        $settings['smtp_password'] = self::decrypt((string) $settings['smtp_password']);
        $settings['google_client_secret'] = self::decrypt((string) $settings['google_client_secret']);
        $settings['google_refresh_token'] = self::decrypt((string) $settings['google_refresh_token']);

        return $settings;
    }

    /**
     * Réglages sûrs pour l'affichage du formulaire.
     *
     * @return array<string, mixed>
     */
    public static function for_form(): array {
        $stored = self::stored();

        return [
            'enabled' => (bool) $stored['enabled'],
            'mode' => $stored['mode'] === 'google' ? 'google' : 'manual',
            'smtp_host' => (string) $stored['smtp_host'],
            'smtp_port' => (int) $stored['smtp_port'],
            'smtp_encryption' => (string) $stored['smtp_encryption'],
            'smtp_username' => (string) $stored['smtp_username'],
            'from_email' => (string) $stored['from_email'],
            'from_name' => (string) $stored['from_name'],
            'google_client_id' => (string) $stored['google_client_id'],
            'google_email' => (string) $stored['google_email'],
            'google_from_name' => (string) $stored['google_from_name'],
            'last_error' => (string) $stored['last_error'],
            'last_success_at' => (string) $stored['last_success_at'],
            'has_smtp_password' => (string) $stored['smtp_password'] !== '',
            'has_google_client_secret' => (string) $stored['google_client_secret'] !== '',
            'google_connected' => (string) $stored['google_refresh_token'] !== '' && (string) $stored['google_email'] !== '',
            'preset' => self::detect_preset((string) $stored['smtp_host'], (int) $stored['smtp_port'], (string) $stored['smtp_encryption']),
        ];
    }

    /**
     * Reconnaître un preset à partir de l'hôte enregistré.
     */
    public static function detect_preset(string $host, int $port, string $encryption): string {
        foreach (self::presets() as $key => $preset) {
            if ($host === $preset['host'] && $port === $preset['port'] && $encryption === $preset['encryption']) {
                return $key;
            }
        }

        return 'custom';
    }

    /**
     * URI de redirection OAuth, à recopier dans Google Cloud Console.
     */
    public static function google_redirect_uri(): string {
        return admin_url('admin.php?page=cf7-smtp-mail');
    }

    /**
     * Enregistrer le formulaire d'administration.
     *
     * @param array<string, mixed> $input
     */
    public static function save(array $input): void {
        $current = self::stored();
        $mode = isset($input['mode']) && $input['mode'] === 'google' ? 'google' : 'manual';
        $encryption = isset($input['smtp_encryption']) ? (string) $input['smtp_encryption'] : 'tls';
        if (!in_array($encryption, ['', 'ssl', 'tls'], true)) {
            $encryption = 'tls';
        }

        $port = isset($input['smtp_port']) ? absint($input['smtp_port']) : 587;
        if ($port < 1 || $port > 65535) {
            $port = 587;
        }

        $client_id = sanitize_text_field((string) ($input['google_client_id'] ?? ''));
        $client_secret = (string) ($input['google_client_secret'] ?? '');
        $stored_secret = (string) $current['google_client_secret'];

        if ($client_secret === '') {
            $encrypted_secret = $stored_secret;
        } else {
            $encrypted_secret = self::encrypt($client_secret);
        }

        $password = (string) ($input['smtp_password'] ?? '');
        if ($password === '') {
            $encrypted_password = (string) $current['smtp_password'];
        } else {
            $encrypted_password = self::encrypt($password);
        }

        $credentials_changed = $client_id !== (string) $current['google_client_id']
            || ($client_secret !== '' && !hash_equals(self::decrypt($stored_secret), $client_secret));

        $refresh_token = (string) $current['google_refresh_token'];
        $google_email = (string) $current['google_email'];
        if ($credentials_changed) {
            $refresh_token = '';
            $google_email = '';
            delete_transient('cf7_smtp_google_access_token');
        }

        $settings = [
            'enabled' => !empty($input['enabled']),
            'mode' => $mode,
            'smtp_host' => sanitize_text_field((string) ($input['smtp_host'] ?? '')),
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => sanitize_text_field((string) ($input['smtp_username'] ?? '')),
            'smtp_password' => $encrypted_password,
            'from_email' => sanitize_email((string) ($input['from_email'] ?? '')),
            'from_name' => sanitize_text_field((string) ($input['from_name'] ?? '')),
            'google_client_id' => $client_id,
            'google_client_secret' => $encrypted_secret,
            'google_refresh_token' => $refresh_token,
            'google_email' => $credentials_changed ? '' : $google_email,
            'google_from_name' => sanitize_text_field((string) ($input['google_from_name'] ?? '')),
            'last_error' => (string) $current['last_error'],
            'last_success_at' => (string) $current['last_success_at'],
        ];

        self::persist($settings);
    }

    /**
     * Enregistrer le compte Google après le consentement OAuth.
     */
    public static function store_google_connection(string $refresh_token, string $email): void {
        $settings = self::stored();
        $settings['google_refresh_token'] = self::encrypt($refresh_token);
        $settings['google_email'] = sanitize_email($email);
        $settings['last_error'] = '';
        self::persist($settings);
        delete_transient('cf7_smtp_google_access_token');
    }

    /**
     * Oublier le compte Google tout en conservant l'identifiant client.
     */
    public static function disconnect_google(): void {
        $settings = self::stored();
        $settings['google_refresh_token'] = '';
        $settings['google_email'] = '';
        self::persist($settings);
        delete_transient('cf7_smtp_google_access_token');
    }

    /**
     * Mémoriser le dernier succès ou la dernière erreur d'envoi.
     */
    public static function set_status(string $error, bool $success): void {
        $settings = self::stored();
        $settings['last_error'] = $success ? '' : $error;
        if ($success) {
            $settings['last_success_at'] = current_time('mysql');
        }
        self::persist($settings);
    }

    /**
     * Dernier message d'erreur d'envoi.
     */
    public static function last_error(): string {
        return (string) self::stored()['last_error'];
    }

    /**
     * Déposer un message pour la prochaine ouverture de la page.
     */
    public static function push_notice(string $type, string $message): void {
        set_transient(
            self::notice_key(),
            [
                'type' => $type === 'success' ? 'success' : 'error',
                'message' => $message,
            ],
            MINUTE_IN_SECONDS
        );
    }

    /**
     * Lire puis effacer le message d'administration.
     *
     * @return array{type: string, message: string}|null
     */
    public static function pull_notice(): ?array {
        $notice = get_transient(self::notice_key());
        if (!is_array($notice) || empty($notice['message'])) {
            return null;
        }

        delete_transient(self::notice_key());

        return [
            'type' => ($notice['type'] ?? '') === 'success' ? 'success' : 'error',
            'message' => (string) $notice['message'],
        ];
    }

    /**
     * Chiffrer un secret avant stockage.
     */
    public static function encrypt(string $value): string {
        if ($value === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('L’extension OpenSSL est requise pour enregistrer les secrets.');
        }

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Impossible de chiffrer le secret.');
        }

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    /**
     * Déchiffrer un secret. Une valeur ancienne en clair est renvoyée telle quelle.
     */
    public static function decrypt(string $value): string {
        if ($value === '' || !str_starts_with($value, 'enc:')) {
            return $value;
        }

        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }

        $plain = openssl_decrypt(substr($raw, 16), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));

        return $plain === false ? '' : $plain;
    }

    /**
     * Clé dérivée des sels WordPress.
     */
    private static function key(): string {
        return hash('sha256', wp_salt('auth'), true);
    }

    /**
     * Clé du message d'administration de l'utilisateur courant.
     */
    private static function notice_key(): string {
        return 'cf7_smtp_notice_' . get_current_user_id();
    }

    /**
     * Écrire l'option sans l'autocharger.
     *
     * @param array<string, mixed> $settings
     */
    private static function persist(array $settings): void {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $settings, '', false);
            return;
        }

        update_option(self::OPTION, $settings, false);
    }
}
