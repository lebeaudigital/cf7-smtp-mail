<?php
/**
 * Plugin Name: CF7 SMTP Mail
 * Plugin URI: https://github.com/lebeaudigital/cf7-smtp-mail
 * Description: Configuration SMTP pour les e-mails de Contact Form 7, par serveur SMTP ou connexion Google.
 * Version: 1.0.0
 * Author: Le Beau Digital
 * Author URI: https://lebeaudigital.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-smtp-mail
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: contact-form-7
 * GitHub Plugin URI: lebeaudigital/cf7-smtp-mail
 * GitHub Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CF7_SMTP_VERSION', '1.0.0');
define('CF7_SMTP_FILE', __FILE__);
define('CF7_SMTP_DIR', plugin_dir_path(__FILE__));
define('CF7_SMTP_URL', plugin_dir_url(__FILE__));
define('CF7_SMTP_BASENAME', plugin_basename(__FILE__));

require_once CF7_SMTP_DIR . 'includes/class-cf7-smtp-settings.php';
require_once CF7_SMTP_DIR . 'api/class-cf7-smtp-api-google.php';
require_once CF7_SMTP_DIR . 'includes/class-cf7-smtp-mailer.php';
require_once CF7_SMTP_DIR . 'api/class-cf7-smtp-api-endpoints.php';
require_once CF7_SMTP_DIR . 'includes/class-cf7-smtp-admin.php';
require_once CF7_SMTP_DIR . 'includes/class-cf7-smtp-updater.php';

/**
 * Point d'entrée du plugin.
 */
class CF7_SMTP_Mail {

    /**
     * Instance unique.
     */
    private static ?CF7_SMTP_Mail $instance = null;

    /**
     * Obtenir l'instance unique.
     */
    public static function get_instance(): CF7_SMTP_Mail {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructeur.
     */
    private function __construct() {
        register_activation_hook(CF7_SMTP_FILE, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
    }

    /**
     * Préparer l'option de réglages à l'activation.
     */
    public function activate(): void {
        CF7_SMTP_Settings::ensure_option();
    }

    /**
     * Charger les composants une fois les extensions disponibles.
     */
    public function on_plugins_loaded(): void {
        load_plugin_textdomain(
            'cf7-smtp-mail',
            false,
            dirname(CF7_SMTP_BASENAME) . '/languages'
        );

        CF7_SMTP_Mailer::get_instance();

        add_action('rest_api_init', static function (): void {
            CF7_SMTP_API_Endpoints::get_instance()->register_routes();
        });

        if (is_admin()) {
            CF7_SMTP_Admin::get_instance();
            CF7_SMTP_Updater::get_instance();
        }
    }
}

CF7_SMTP_Mail::get_instance();
