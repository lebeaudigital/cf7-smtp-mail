<?php
/**
 * Page de réglages dans le menu Contact de Contact Form 7.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF7_SMTP_Admin {

    /**
     * Instance unique.
     */
    private static ?CF7_SMTP_Admin $instance = null;

    /**
     * Identifiant d'écran renvoyé par add_submenu_page.
     */
    private string $page_hook = '';

    /**
     * Obtenir l'instance unique.
     */
    public static function get_instance(): CF7_SMTP_Admin {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Brancher les hooks d'administration.
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('admin_init', [$this, 'handle_requests']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'missing_dependency_notice']);
        add_filter('script_loader_tag', [$this, 'script_module_tag'], 10, 3);
    }

    /**
     * Ajouter le sous-menu Configuration SMTP.
     */
    public function register_menu(): void {
        if (!defined('WPCF7_VERSION')) {
            return;
        }

        $this->page_hook = (string) add_submenu_page(
            'wpcf7',
            __('Configuration SMTP', 'cf7-smtp-mail'),
            __('Configuration SMTP', 'cf7-smtp-mail'),
            'manage_options',
            'cf7-smtp-mail',
            [$this, 'render_page']
        );
    }

    /**
     * Traiter l'enregistrement, la connexion Google et la déconnexion.
     */
    public function handle_requests(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'cf7-smtp-mail') {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'google_connect') {
            $this->start_google_connect();
        }

        if ($action === 'google_disconnect') {
            $this->disconnect_google();
        }

        if (isset($_GET['error'])) {
            delete_transient($this->oauth_state_key());
            $this->redirect_with_notice('error', __('Connexion Google annulée.', 'cf7-smtp-mail'));
        }

        if (isset($_GET['code'], $_GET['state'])) {
            $this->complete_google_callback();
        }

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cf7_smtp_nonce'])) {
            $this->save_settings();
        }
    }

    /**
     * Charger les styles et les scripts de la page.
     */
    public function enqueue_assets(string $hook): void {
        if ($this->page_hook === '' || $hook !== $this->page_hook) {
            return;
        }

        wp_enqueue_style(
            'cf7-smtp-admin',
            CF7_SMTP_URL . 'assets/css/admin.css',
            [],
            CF7_SMTP_VERSION
        );

        $scripts = [
            'cf7-smtp-settings' => 'assets/js/smtp-settings.js',
            'cf7-smtp-google' => 'assets/js/google-connect.js',
            'cf7-smtp-mail-test' => 'assets/js/mail-test.js',
        ];

        foreach ($scripts as $handle => $path) {
            wp_enqueue_script($handle, CF7_SMTP_URL . $path, [], CF7_SMTP_VERSION, true);
        }
    }

    /**
     * Charger chaque script de fonctionnalité comme module ES6.
     *
     * @param string $tag
     * @param string $handle
     * @param string $src
     */
    public function script_module_tag(string $tag, string $handle, string $src): string {
        $modules = ['cf7-smtp-settings', 'cf7-smtp-google', 'cf7-smtp-mail-test'];
        if (!in_array($handle, $modules, true)) {
            return $tag;
        }

        return '<script type="module" src="' . esc_url($src) . '"></script>' . "\n";
    }

    /**
     * Signaler l'absence de Contact Form 7 dans l'écran Extensions.
     */
    public function missing_dependency_notice(): void {
        if (defined('WPCF7_VERSION') || !current_user_can('activate_plugins')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->id !== 'plugins') {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('CF7 SMTP Mail nécessite Contact Form 7 pour afficher le menu Configuration SMTP.', 'cf7-smtp-mail');
        echo '</p></div>';
    }

    /**
     * Afficher la page de réglages.
     */
    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = CF7_SMTP_Settings::for_form();
        $notice = CF7_SMTP_Settings::pull_notice();
        $redirect_uri = CF7_SMTP_Settings::google_redirect_uri();
        $presets = CF7_SMTP_Settings::presets();
        $connect_url = wp_nonce_url(
            admin_url('admin.php?page=cf7-smtp-mail&action=google_connect'),
            'cf7_smtp_google_connect'
        );
        $disconnect_url = wp_nonce_url(
            admin_url('admin.php?page=cf7-smtp-mail&action=google_disconnect'),
            'cf7_smtp_google_disconnect'
        );

        include CF7_SMTP_DIR . 'templates/admin/settings-page.php';
    }

    /**
     * Enregistrer le formulaire puis revenir sur la page.
     */
    private function save_settings(): void {
        check_admin_referer('cf7_smtp_save_settings', 'cf7_smtp_nonce');

        $before = CF7_SMTP_Settings::for_form();

        try {
            CF7_SMTP_Settings::save(wp_unslash($_POST));
        } catch (Throwable $exception) {
            $this->redirect_with_notice('error', $exception->getMessage());
        }

        $after = CF7_SMTP_Settings::for_form();
        if ($before['google_connected'] && !$after['google_connected']) {
            $this->redirect_with_notice(
                'success',
                __('Réglages enregistrés. Le compte Google a été déconnecté car les identifiants OAuth ont changé.', 'cf7-smtp-mail')
            );
        }

        $this->redirect_with_notice('success', __('Réglages enregistrés.', 'cf7-smtp-mail'));
    }

    /**
     * Rediriger l'administrateur vers l'écran de consentement Google.
     */
    private function start_google_connect(): void {
        check_admin_referer('cf7_smtp_google_connect');

        $settings = CF7_SMTP_Settings::all();
        if ((string) $settings['google_client_id'] === '' || (string) $settings['google_client_secret'] === '') {
            $this->redirect_with_notice(
                'error',
                __('Enregistrez le Client ID et le Client Secret avant de connecter Google.', 'cf7-smtp-mail')
            );
        }

        $state = wp_generate_password(32, false, false);
        set_transient($this->oauth_state_key(), $state, 10 * MINUTE_IN_SECONDS);

        wp_redirect((new CF7_SMTP_API_Google())->get_auth_url($state));
        exit;
    }

    /**
     * Ranger le jeton renvoyé par Google.
     */
    private function complete_google_callback(): void {
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $expected = get_transient($this->oauth_state_key());
        delete_transient($this->oauth_state_key());

        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state)) {
            $this->redirect_with_notice(
                'error',
                __('La session Google a expiré. Relancez la connexion.', 'cf7-smtp-mail')
            );
        }

        try {
            $api = new CF7_SMTP_API_Google();
            $tokens = $api->exchange_code($code);
            $refresh_token = $tokens['refresh_token'];

            if ($refresh_token === '') {
                $refresh_token = (string) CF7_SMTP_Settings::all()['google_refresh_token'];
            }

            if ($refresh_token === '') {
                throw new RuntimeException(__('Google n’a pas renvoyé de jeton de renouvellement. Réessayez la connexion.', 'cf7-smtp-mail'));
            }

            $email = $api->get_user_email($tokens['access_token']);
            CF7_SMTP_Settings::store_google_connection($refresh_token, $email);
            $this->redirect_with_notice(
                'success',
                sprintf(
                    /* translators: %s: adresse Gmail connectée */
                    __('Compte Google connecté : %s.', 'cf7-smtp-mail'),
                    $email
                )
            );
        } catch (Throwable $exception) {
            $this->redirect_with_notice('error', $exception->getMessage());
        }
    }

    /**
     * Oublier le compte Google.
     */
    private function disconnect_google(): void {
        check_admin_referer('cf7_smtp_google_disconnect');
        CF7_SMTP_Settings::disconnect_google();
        $this->redirect_with_notice('success', __('Compte Google déconnecté.', 'cf7-smtp-mail'));
    }

    /**
     * Mémoriser un message puis revenir à la page sans paramètres OAuth.
     */
    private function redirect_with_notice(string $type, string $message): void {
        CF7_SMTP_Settings::push_notice($type, $message);
        wp_safe_redirect(admin_url('admin.php?page=cf7-smtp-mail'));
        exit;
    }

    /**
     * Clé temporaire du jeton anti-falsification OAuth.
     */
    private function oauth_state_key(): string {
        return 'cf7_smtp_oauth_state_' . get_current_user_id();
    }
}
