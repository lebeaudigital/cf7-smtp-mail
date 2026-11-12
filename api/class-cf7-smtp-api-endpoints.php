<?php
/**
 * Routes REST d'administration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF7_SMTP_API_Endpoints {

    /**
     * Instance unique.
     */
    private static ?CF7_SMTP_API_Endpoints $instance = null;

    /**
     * Obtenir l'instance unique.
     */
    public static function get_instance(): CF7_SMTP_API_Endpoints {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Déclarer les routes.
     */
    public function register_routes(): void {
        register_rest_route(
            'cf7-smtp-mail/v1',
            '/test-email',
            [
                'methods' => 'POST',
                'callback' => [$this, 'send_test_email'],
                'permission_callback' => static function (): bool {
                    return current_user_can('manage_options');
                },
                'args' => [
                    'to' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                ],
            ]
        );
    }

    /**
     * Envoyer l'e-mail de test avec la configuration enregistrée.
     */
    public function send_test_email(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $to = sanitize_email((string) $request->get_param('to'));
        if (!is_email($to)) {
            return new WP_Error(
                'cf7_smtp_invalid_email',
                __('Indiquez une adresse e-mail valide.', 'cf7-smtp-mail'),
                ['status' => 400]
            );
        }

        $lock_key = 'cf7_smtp_test_lock_' . get_current_user_id();
        if (get_transient($lock_key)) {
            return new WP_Error(
                'cf7_smtp_rate_limited',
                __('Attendez quelques secondes avant un nouvel essai.', 'cf7-smtp-mail'),
                ['status' => 429]
            );
        }

        set_transient($lock_key, 1, 15);

        $sent = CF7_SMTP_Mailer::get_instance()->send_test($to);
        if (!$sent) {
            $error = CF7_SMTP_Settings::last_error();

            return new WP_Error(
                'cf7_smtp_send_failed',
                $error !== '' ? $error : __('L’envoi a échoué.', 'cf7-smtp-mail'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf(
                /* translators: %s: adresse e-mail destinataire */
                __('E-mail de test envoyé à %s.', 'cf7-smtp-mail'),
                $to
            ),
        ]);
    }
}
