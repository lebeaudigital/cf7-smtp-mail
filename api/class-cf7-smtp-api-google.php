<?php
/**
 * Appels HTTP vers Google : OAuth, profil et envoi Gmail.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF7_SMTP_API_Google {

    /**
     * Scope d'envoi Gmail.
     */
    private const SCOPE_SEND = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * Scope de lecture de l'adresse du compte.
     */
    private const SCOPE_EMAIL = 'https://www.googleapis.com/auth/userinfo.email';

    /**
     * URL d'autorisation OAuth.
     */
    public function get_auth_url(string $state): string {
        $settings = CF7_SMTP_Settings::all();
        $params = [
            'client_id' => (string) $settings['google_client_id'],
            'redirect_uri' => CF7_SMTP_Settings::google_redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPE_SEND . ' ' . self::SCOPE_EMAIL,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Échanger le code d'autorisation contre des jetons.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function exchange_code(string $code): array {
        $settings = CF7_SMTP_Settings::all();
        $data = $this->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code' => $code,
                'client_id' => (string) $settings['google_client_id'],
                'client_secret' => (string) $settings['google_client_secret'],
                'redirect_uri' => CF7_SMTP_Settings::google_redirect_uri(),
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new RuntimeException('Google n’a pas renvoyé de jeton d’accès.');
        }

        $refresh_token = isset($data['refresh_token']) && is_string($data['refresh_token']) ? $data['refresh_token'] : '';
        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->cache_access_token($data['access_token'], $expires_in);

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $refresh_token,
            'expires_in' => $expires_in,
        ];
    }

    /**
     * Adresse du compte Google connecté.
     */
    public function get_user_email(string $access_token): string {
        $data = $this->request('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ]);

        $email = isset($data['email']) ? sanitize_email((string) $data['email']) : '';
        if ($email === '' || !is_email($email)) {
            throw new RuntimeException('Impossible de lire l’adresse du compte Google.');
        }

        return $email;
    }

    /**
     * Envoyer un message RFC822 déjà construit.
     */
    public function send_raw(string $mime): void {
        $token = $this->get_access_token();

        try {
            $this->post_message($mime, $token);
        } catch (RuntimeException $exception) {
            if (!$this->is_auth_error($exception)) {
                throw $exception;
            }

            delete_transient('cf7_smtp_google_access_token');
            $this->post_message($mime, $this->get_access_token());
        }
    }

    /**
     * Jeton d'accès en cache, renouvelé avec le refresh token.
     */
    public function get_access_token(): string {
        $cached = get_transient('cf7_smtp_google_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $settings = CF7_SMTP_Settings::all();
        $refresh_token = (string) $settings['google_refresh_token'];
        if ($refresh_token === '') {
            throw new RuntimeException('Compte Google non connecté.');
        }

        $data = $this->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => (string) $settings['google_client_id'],
                'client_secret' => (string) $settings['google_client_secret'],
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new RuntimeException('Impossible de renouveler le jeton Google.');
        }

        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $this->cache_access_token($data['access_token'], $expires_in);

        return $data['access_token'];
    }

    /**
     * Poster le message sur l'API Gmail.
     */
    private function post_message(string $mime, string $access_token): void {
        $this->request('POST', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'raw' => $this->base64url($mime),
            ]),
        ]);
    }

    /**
     * Conserver le jeton d'accès un peu moins longtemps que sa durée de vie.
     */
    private function cache_access_token(string $token, int $expires_in): void {
        set_transient('cf7_smtp_google_access_token', $token, max(60, $expires_in - 60));
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $args): array {
        $args['timeout'] = 20;
        $response = $method === 'GET' ? wp_remote_get($url, $args) : wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($decoded) ? $decoded : [];

        if ($code < 200 || $code >= 300) {
            throw new RuntimeException($this->error_message($body, 'Erreur Google (' . $code . ').'));
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function error_message(array $body, string $fallback): string {
        if (isset($body['error_description']) && is_string($body['error_description'])) {
            return $body['error_description'];
        }

        if (isset($body['error']['message']) && is_string($body['error']['message'])) {
            return $body['error']['message'];
        }

        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        return $fallback;
    }

    /**
     * Repérer un rejet d'authentification pour renouveler le jeton une fois.
     */
    private function is_auth_error(RuntimeException $exception): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'invalid authentication')
            || str_contains($message, 'invalid credentials')
            || str_contains($message, 'unauthorized')
            || str_contains($message, '(401)');
    }

    /**
     * Encodage base64url attendu par l'API Gmail.
     */
    private function base64url(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
