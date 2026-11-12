<?php
/**
 * Mise à jour automatique depuis les releases GitHub.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF7_SMTP_Updater {

    /**
     * Instance unique.
     */
    private static ?CF7_SMTP_Updater $instance = null;

    /**
     * Slug du dossier du plugin.
     */
    private string $slug = 'cf7-smtp-mail';

    /**
     * Chemin du fichier principal, relatif à wp-content/plugins.
     */
    private string $plugin_file;

    /**
     * Compte GitHub.
     */
    private string $github_username = 'lebeaudigital';

    /**
     * Dépôt GitHub.
     */
    private string $github_repo = 'cf7-smtp-mail';

    /**
     * Jeton optionnel pour un dépôt privé.
     */
    private string $access_token = '';

    /**
     * Données lues dans l'en-tête du plugin.
     *
     * @var array<string, string>
     */
    private array $plugin_data = [];

    /**
     * Dernière release mise en cache pour la requête en cours.
     */
    private ?object $github_response = null;

    /**
     * Obtenir l'instance unique.
     */
    public static function get_instance(): CF7_SMTP_Updater {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Préparer les données du plugin et les hooks WordPress.
     */
    private function __construct() {
        $this->plugin_file = CF7_SMTP_BASENAME;

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->plugin_data = get_plugin_data(CF7_SMTP_FILE);
        $this->init_hooks();
    }

    /**
     * Brancher la vérification de version et les liens d'administration.
     */
    private function init_hooks(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
        add_filter('plugin_action_links_' . $this->plugin_file, [$this, 'plugin_action_links']);
        add_action('admin_init', [$this, 'handle_force_check']);
    }

    /**
     * Proposer la release GitHub si elle est plus récente.
     *
     * @param object $transient
     */
    public function check_update(object $transient): object {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if ($release === null) {
            return $transient;
        }

        $github_version = ltrim((string) $release->tag_name, 'v');
        $current_version = $this->plugin_data['Version'] ?? CF7_SMTP_VERSION;

        if (version_compare($github_version, $current_version, '>')) {
            $transient->response[$this->plugin_file] = (object) [
                'slug' => $this->slug,
                'plugin' => $this->plugin_file,
                'new_version' => $github_version,
                'url' => $this->github_url(),
                'package' => $this->get_download_url($release),
                'tested' => '6.9',
                'requires' => '6.0',
                'requires_php' => '8.0',
                'compatibility' => new stdClass(),
            ];
        }

        return $transient;
    }

    /**
     * Fiche affichée dans la fenêtre de détails de WordPress.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugin_info($result, string $action, object $args) {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_github_release();
        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => $this->plugin_data['Name'] ?? 'CF7 SMTP Mail',
            'slug' => $this->slug,
            'version' => ltrim((string) $release->tag_name, 'v'),
            'author' => $this->plugin_data['Author'] ?? 'Le Beau Digital',
            'author_profile' => 'https://github.com/' . $this->github_username,
            'homepage' => $this->github_url(),
            'requires' => '6.0',
            'tested' => '6.9',
            'requires_php' => '8.0',
            'downloaded' => 0,
            'last_updated' => $release->published_at ?? '',
            'sections' => [
                'description' => $this->plugin_data['Description'] ?? '',
                'changelog' => $this->format_changelog($release),
                'installation' => $this->installation_instructions(),
            ],
            'download_link' => $this->get_download_url($release),
        ];
    }

    /**
     * Replacer le dossier extrait sous le slug du plugin, puis le réactiver.
     *
     * @param mixed $response
     * @param array<string, mixed> $hook_extra
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function post_install($response, array $hook_extra, array $result): array {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $result;
        }

        $plugin_folder = WP_PLUGIN_DIR . '/' . $this->slug;
        if (!empty($result['destination']) && $result['destination'] !== $plugin_folder && $wp_filesystem) {
            $wp_filesystem->move($result['destination'], $plugin_folder, true);
            $result['destination'] = $plugin_folder;
        }

        activate_plugin($this->plugin_file);
        delete_transient('cf7_smtp_github_release');

        return $result;
    }

    /**
     * Liens sous la description du plugin.
     *
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function plugin_row_meta(array $links, string $file): array {
        if ($file !== $this->plugin_file) {
            return $links;
        }

        $links[] = '<a href="' . esc_url($this->github_url()) . '" target="_blank" rel="noopener">' . esc_html__('GitHub', 'cf7-smtp-mail') . '</a>';
        $links[] = '<a href="' . esc_url($this->github_url('/issues')) . '" target="_blank" rel="noopener">' . esc_html__('Signaler un bug', 'cf7-smtp-mail') . '</a>';

        return $links;
    }

    /**
     * Liens à côté d'Activer.
     *
     * @param array<int, string> $links
     * @return array<int, string>
     */
    public function plugin_action_links(array $links): array {
        if (defined('WPCF7_VERSION')) {
            $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=cf7-smtp-mail')) . '">' . esc_html__('Réglages', 'cf7-smtp-mail') . '</a>';
            array_unshift($links, $settings_link);
        }

        $update_url = wp_nonce_url(
            admin_url('plugins.php?cf7_smtp_force_update=1'),
            'cf7_smtp_force_update'
        );
        $links[] = '<a href="' . esc_url($update_url) . '">' . esc_html__('Vérifier mise à jour', 'cf7-smtp-mail') . '</a>';

        return $links;
    }

    /**
     * Forcer une lecture de la dernière release et afficher le résultat.
     */
    public function handle_force_check(): void {
        if (!isset($_GET['cf7_smtp_force_update'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'cf7_smtp_force_update')) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        self::force_check();

        $test_response = wp_remote_get($this->github_api_url() . '/releases/latest', [
            'timeout' => 15,
            'sslverify' => false,
            'headers' => $this->github_headers(),
        ]);

        $current_version = $this->plugin_data['Version'] ?? CF7_SMTP_VERSION;

        if (is_wp_error($test_response)) {
            $error_message = $test_response->get_error_message();
            add_action('admin_notices', static function () use ($error_message): void {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html(sprintf(
                    /* translators: %s: message d'erreur réseau */
                    __('Impossible de contacter GitHub : %s', 'cf7-smtp-mail'),
                    $error_message
                ));
                echo '</p></div>';
            });
            return;
        }

        $response_code = (int) wp_remote_retrieve_response_code($test_response);
        if ($response_code !== 200) {
            add_action('admin_notices', static function () use ($response_code): void {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html(sprintf(
                    /* translators: %d: code HTTP */
                    __('Erreur GitHub (code %d). Vérifiez que le dépôt existe et est public.', 'cf7-smtp-mail'),
                    $response_code
                ));
                echo '</p></div>';
            });
            return;
        }

        $release_data = json_decode((string) wp_remote_retrieve_body($test_response));
        if (empty($release_data) || !isset($release_data->tag_name)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Aucune release trouvée sur GitHub.', 'cf7-smtp-mail');
                echo '</p></div>';
            });
            return;
        }

        $github_version = ltrim((string) $release_data->tag_name, 'v');
        set_transient('cf7_smtp_github_release', $release_data, 6 * HOUR_IN_SECONDS);

        if (version_compare($github_version, $current_version, '>')) {
            delete_site_transient('update_plugins');
            add_action('admin_notices', static function () use ($github_version, $current_version): void {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo wp_kses(
                    sprintf(
                        /* translators: 1: version actuelle, 2: nouvelle version, 3: URL de mise à jour */
                        __('Mise à jour disponible ! Version %1$s → %2$s. <a href="%3$s">Mettre à jour maintenant</a>', 'cf7-smtp-mail'),
                        esc_html($current_version),
                        esc_html($github_version),
                        esc_url(admin_url('update-core.php'))
                    ),
                    ['a' => ['href' => []]]
                );
                echo '</p></div>';
            });
            return;
        }

        add_action('admin_notices', static function () use ($current_version): void {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(
                /* translators: %s: version installée */
                __('CF7 SMTP Mail est à jour ! Version actuelle : %s', 'cf7-smtp-mail'),
                $current_version
            ));
            echo '</p></div>';
        });
    }

    /**
     * Oublier le cache pour la prochaine vérification.
     */
    public static function force_check(): void {
        delete_transient('cf7_smtp_github_release');
        delete_site_transient('update_plugins');
    }

    /**
     * Lire la dernière release, avec un cache de six heures.
     */
    private function get_github_release(): ?object {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        $cached = get_transient('cf7_smtp_github_release');
        if ($cached instanceof stdClass && isset($cached->tag_name)) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        $response = wp_remote_get($this->github_api_url() . '/releases/latest', [
            'timeout' => 10,
            'headers' => $this->github_headers(),
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response));
        if (empty($data) || !isset($data->tag_name)) {
            return null;
        }

        set_transient('cf7_smtp_github_release', $data, 6 * HOUR_IN_SECONDS);
        $this->github_response = $data;

        return $this->github_response;
    }

    /**
     * Archive ZIP de la release, ou le zipball GitHub à défaut.
     */
    private function get_download_url(object $release): string {
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (isset($asset->name, $asset->browser_download_url) && str_ends_with((string) $asset->name, '.zip')) {
                    return (string) $asset->browser_download_url;
                }
            }
        }

        return (string) ($release->zipball_url ?? '');
    }

    /**
     * Notes de release converties en HTML simple.
     */
    private function format_changelog(object $release): string {
        $changelog = '<h4>' . esc_html((string) $release->tag_name) . '</h4>';

        if (!empty($release->body)) {
            $body = esc_html((string) $release->body);
            $body = preg_replace('/^### (.+)$/m', '<h5>$1</h5>', $body) ?? $body;
            $body = preg_replace('/^## (.+)$/m', '<h4>$1</h4>', $body) ?? $body;
            $body = preg_replace('/^# (.+)$/m', '<h3>$1</h3>', $body) ?? $body;
            $body = preg_replace('/^[*-] (.+)$/m', '<li>$1</li>', $body) ?? $body;
            $changelog .= '<p>' . nl2br($body) . '</p>';
        } else {
            $changelog .= '<p>' . esc_html__('Voir les détails sur GitHub.', 'cf7-smtp-mail') . '</p>';
        }

        if (!empty($release->html_url)) {
            $changelog .= '<p><a href="' . esc_url((string) $release->html_url) . '" target="_blank" rel="noopener">';
            $changelog .= esc_html__('Voir la release sur GitHub', 'cf7-smtp-mail');
            $changelog .= '</a></p>';
        }

        return $changelog;
    }

    /**
     * Texte d'installation de la fiche plugin.
     */
    private function installation_instructions(): string {
        return '<ol>'
            . '<li>' . esc_html__('Téléchargez le plugin depuis GitHub ou mettez-le à jour via WordPress.', 'cf7-smtp-mail') . '</li>'
            . '<li>' . esc_html__('Activez le plugin dans le menu Extensions. Contact Form 7 doit être actif.', 'cf7-smtp-mail') . '</li>'
            . '<li>' . esc_html__('Ouvrez Contact > Configuration SMTP.', 'cf7-smtp-mail') . '</li>'
            . '</ol>';
    }

    /**
     * URL de l'API du dépôt.
     */
    private function github_api_url(): string {
        return 'https://api.github.com/repos/' . $this->github_username . '/' . $this->github_repo;
    }

    /**
     * URL publique du dépôt, avec un chemin optionnel.
     */
    private function github_url(string $path = ''): string {
        return 'https://github.com/' . $this->github_username . '/' . $this->github_repo . $path;
    }

    /**
     * En-têtes communs des appels GitHub.
     *
     * @return array<string, string>
     */
    private function github_headers(): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'CF7-SMTP-Mail-Updater',
        ];

        if ($this->access_token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->access_token;
        }

        return $headers;
    }
}
