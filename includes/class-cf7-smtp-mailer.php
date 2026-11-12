<?php
/**
 * Envoi des e-mails Contact Form 7 par SMTP ou par l'API Gmail.
 */

if (!defined('ABSPATH')) {
    exit;
}

class CF7_SMTP_Mailer {

    /**
     * Instance unique.
     */
    private static ?CF7_SMTP_Mailer $instance = null;

    /**
     * Vrai pendant l'envoi d'un formulaire CF7.
     */
    private bool $sending_cf7 = false;

    /**
     * Vrai pendant un e-mail de test, même si l'envoi CF7 est désactivé.
     */
    private bool $force = false;

    /**
     * Obtenir l'instance unique.
     */
    public static function get_instance(): CF7_SMTP_Mailer {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Brancher les hooks d'envoi.
     */
    private function __construct() {
        add_action('wpcf7_before_send_mail', [$this, 'begin'], 1);
        add_action('wpcf7_before_send_mail', [$this, 'confirm_send'], PHP_INT_MAX, 2);
        add_action('wpcf7_mail_sent', [$this, 'end'], 999);
        add_action('wpcf7_mail_failed', [$this, 'end'], 999);
        add_action('shutdown', [$this, 'end']);
        add_filter('pre_wp_mail', [$this, 'intercept_mail'], 10, 2);
        add_action('phpmailer_init', [$this, 'configure_phpmailer']);
        add_action('wp_mail_failed', [$this, 'on_mail_failed']);
        add_action('wp_mail_succeeded', [$this, 'on_mail_succeeded']);
    }

    /**
     * Marquer le début d'un envoi Contact Form 7.
     */
    public function begin(): void {
        $this->sending_cf7 = true;
    }

    /**
     * Annuler le marqueur si un autre hook a interrompu l'envoi.
     *
     * @param mixed $contact_form
     * @param mixed $abort
     */
    public function confirm_send($contact_form = null, $abort = false): void {
        if ($abort) {
            $this->sending_cf7 = false;
        }
    }

    /**
     * Relâcher le marqueur d'envoi.
     */
    public function end(): void {
        $this->sending_cf7 = false;
    }

    /**
     * Envoyer un message de test avec les réglages enregistrés.
     */
    public function send_test(string $to): bool {
        $this->force = true;

        try {
            return (bool) wp_mail(
                $to,
                '[CF7 SMTP] E-mail de test',
                sprintf(
                    "Ceci est un e-mail de test envoyé depuis %s le %s.",
                    get_bloginfo('name'),
                    current_time('mysql')
                )
            );
        } finally {
            $this->force = false;
        }
    }

    /**
     * Court-circuiter wp_mail pour Gmail, ou laisser PHPMailer traiter le SMTP.
     *
     * @param mixed                $result
     * @param array<string, mixed> $atts
     * @return mixed
     */
    public function intercept_mail($result, array $atts) {
        if (!$this->should_handle()) {
            return $result;
        }

        $settings = CF7_SMTP_Settings::all();
        if ($settings['mode'] === 'google') {
            return $this->send_via_gmail($atts, $settings);
        }

        if ($settings['smtp_host'] === '' || $settings['smtp_username'] === '') {
            CF7_SMTP_Settings::set_status('Configuration SMTP incomplète.', false);
            return false;
        }

        return $result;
    }

    /**
     * Appliquer le serveur SMTP au PHPMailer de WordPress.
     *
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public function configure_phpmailer($phpmailer): void {
        if (!$this->should_handle()) {
            return;
        }

        $settings = CF7_SMTP_Settings::all();
        if ($settings['mode'] !== 'manual') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host = (string) $settings['smtp_host'];
        $phpmailer->Port = (int) $settings['smtp_port'];
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = (string) $settings['smtp_username'];
        $phpmailer->Password = (string) $settings['smtp_password'];
        $phpmailer->Timeout = 15;

        $encryption = (string) $settings['smtp_encryption'];
        if ($encryption === 'ssl' || $encryption === 'tls') {
            $phpmailer->SMTPSecure = $encryption;
        } else {
            $phpmailer->SMTPSecure = '';
            $phpmailer->SMTPAutoTLS = false;
        }

        $from_email = (string) $settings['from_email'];
        if ($from_email === '' && is_email((string) $settings['smtp_username'])) {
            $from_email = (string) $settings['smtp_username'];
        }

        if ($from_email === '' || !is_email($from_email)) {
            return;
        }

        $original_email = (string) $phpmailer->From;
        $original_name = (string) $phpmailer->FromName;
        $from_name = (string) $settings['from_name'] !== '' ? (string) $settings['from_name'] : $original_name;

        try {
            $phpmailer->setFrom($from_email, $from_name, false);
            $phpmailer->Sender = $from_email;

            if ($original_email !== '' && strcasecmp($original_email, $from_email) !== 0) {
                $phpmailer->addReplyTo($original_email, $original_name);
            }
        } catch (Throwable $exception) {
            CF7_SMTP_Settings::set_status($exception->getMessage(), false);
        }
    }

    /**
     * Conserver l'erreur PHPMailer pour l'afficher dans les réglages.
     */
    public function on_mail_failed(WP_Error $error): void {
        if (!$this->should_handle()) {
            return;
        }

        CF7_SMTP_Settings::set_status($error->get_error_message(), false);
    }

    /**
     * Conserver la date du dernier envoi SMTP réussi.
     */
    public function on_mail_succeeded(): void {
        if (!$this->should_handle()) {
            return;
        }

        $settings = CF7_SMTP_Settings::stored();
        if ($settings['mode'] !== 'manual') {
            return;
        }

        CF7_SMTP_Settings::set_status('', true);
    }

    /**
     * L'envoi en cours doit passer par ce plugin.
     */
    private function should_handle(): bool {
        if ($this->force) {
            return true;
        }

        if (!$this->sending_cf7) {
            return false;
        }

        return (bool) CF7_SMTP_Settings::stored()['enabled'];
    }

    /**
     * Construire le MIME et l'envoyer avec le compte Google.
     *
     * @param array<string, mixed> $atts
     * @param array<string, mixed> $settings
     */
    private function send_via_gmail(array $atts, array $settings): bool {
        if ((string) $settings['google_refresh_token'] === '' || (string) $settings['google_email'] === '') {
            CF7_SMTP_Settings::set_status('Compte Google non connecté.', false);
            return false;
        }

        try {
            $mime = $this->build_gmail_mime($atts, $settings);
            (new CF7_SMTP_API_Google())->send_raw($mime);
            CF7_SMTP_Settings::set_status('', true);
            return true;
        } catch (Throwable $exception) {
            CF7_SMTP_Settings::set_status($exception->getMessage(), false);
            return false;
        }
    }

    /**
     * Assembler un message RFC822 avec le PHPMailer fourni par WordPress.
     *
     * @param array<string, mixed> $atts
     * @param array<string, mixed> $settings
     */
    private function build_gmail_mime(array $atts, array $settings): string {
        $this->load_phpmailer();

        $headers = $this->parse_headers($atts['headers'] ?? []);
        $charset = $headers['charset'] !== '' ? $headers['charset'] : (string) get_bloginfo('charset');
        $charset = (string) apply_filters('wp_mail_charset', $charset);
        $content_type = $headers['content_type'] !== '' ? $headers['content_type'] : 'text/plain';
        $content_type = (string) apply_filters('wp_mail_content_type', $content_type);

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = $charset !== '' ? $charset : 'UTF-8';
        $mail->Encoding = PHPMailer\PHPMailer\PHPMailer::ENCODING_8BIT;

        $google_email = (string) $settings['google_email'];
        $from_name = (string) $settings['google_from_name'];
        if ($from_name === '') {
            $from_name = $headers['from_name'] !== '' ? $headers['from_name'] : (string) get_bloginfo('name');
        }

        $mail->setFrom($google_email, $from_name, false);

        if ($headers['from_email'] !== '' && strcasecmp($headers['from_email'], $google_email) !== 0) {
            $mail->addReplyTo($headers['from_email'], $headers['from_name']);
        }

        foreach ($this->address_list($atts['to'] ?? '') as $address) {
            $mail->addAddress($address['email'], $address['name']);
        }

        foreach ($headers['cc'] as $address) {
            $mail->addCC($address['email'], $address['name']);
        }

        foreach ($headers['bcc'] as $address) {
            $mail->addBCC($address['email'], $address['name']);
        }

        foreach ($headers['reply_to'] as $address) {
            $mail->addReplyTo($address['email'], $address['name']);
        }

        if (count($mail->getToAddresses()) === 0) {
            throw new RuntimeException('Aucun destinataire valide.');
        }

        $mail->Subject = (string) ($atts['subject'] ?? '');
        $message = (string) ($atts['message'] ?? '');

        if (str_starts_with(strtolower($content_type), 'text/html')) {
            $mail->isHTML(true);
            $mail->Body = $message;
            $mail->AltBody = wp_strip_all_tags($message);
        } else {
            $mail->Body = $message;
        }

        foreach ($headers['custom'] as $header_name => $header_value) {
            if ($header_name === '') {
                continue;
            }

            try {
                $mail->addCustomHeader($header_name, $header_value);
            } catch (Throwable $exception) {
                continue;
            }
        }

        $attachments = $atts['attachments'] ?? [];
        if (is_string($attachments)) {
            $attachments = $attachments !== '' ? preg_split("/\r\n|\n|\r/", $attachments) : [];
        }

        if (is_array($attachments)) {
            foreach ($attachments as $filename => $attachment) {
                if (!is_string($attachment) || $attachment === '' || !is_readable($attachment)) {
                    continue;
                }
                $name = is_string($filename) ? $filename : '';
                $mail->addAttachment($attachment, $name);
            }
        }

        $embeds = $atts['embeds'] ?? [];
        if (is_string($embeds)) {
            $embeds = $embeds !== '' ? preg_split("/\r\n|\n|\r/", $embeds) : [];
        }

        if (is_array($embeds)) {
            foreach ($embeds as $cid => $embed_path) {
                if (!is_string($embed_path) || $embed_path === '' || !is_readable($embed_path)) {
                    continue;
                }
                $mail->addEmbeddedImage($embed_path, (string) $cid, basename($embed_path));
            }
        }

        $mail->preSend();

        return $mail->getSentMIMEMessage();
    }

    /**
     * Charger les classes PHPMailer livrées avec WordPress.
     */
    private function load_phpmailer(): void {
        if (class_exists(PHPMailer\PHPMailer\PHPMailer::class, false)) {
            return;
        }

        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }

    /**
     * Lire les en-têtes transmis par wp_mail.
     *
     * @param mixed $raw_headers
     * @return array{
     *     charset: string,
     *     content_type: string,
     *     from_email: string,
     *     from_name: string,
     *     cc: array<int, array{email: string, name: string}>,
     *     bcc: array<int, array{email: string, name: string}>,
     *     reply_to: array<int, array{email: string, name: string}>,
     *     custom: array<string, string>
     * }
     */
    private function parse_headers($raw_headers): array {
        $parsed = [
            'charset' => '',
            'content_type' => '',
            'from_email' => '',
            'from_name' => '',
            'cc' => [],
            'bcc' => [],
            'reply_to' => [],
            'custom' => [],
        ];

        if (is_string($raw_headers)) {
            $raw_headers = preg_split("/\r\n|\n|\r/", $raw_headers) ?: [];
        }

        if (!is_array($raw_headers)) {
            return $parsed;
        }

        foreach ($raw_headers as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if ($name === 'content-type') {
                $parsed['content_type'] = trim(strtok($value, ';') ?: $value);
                if (preg_match('/charset\s*=\s*"?([^";]+)"?/i', $value, $matches) === 1) {
                    $parsed['charset'] = sanitize_text_field($matches[1]);
                }
                continue;
            }

            if ($name === 'from') {
                $from = $this->address_list($value);
                if ($from !== []) {
                    $parsed['from_email'] = $from[0]['email'];
                    $parsed['from_name'] = $from[0]['name'];
                }
                continue;
            }

            if ($name === 'cc') {
                $parsed['cc'] = array_merge($parsed['cc'], $this->address_list($value));
            } elseif ($name === 'bcc') {
                $parsed['bcc'] = array_merge($parsed['bcc'], $this->address_list($value));
            } elseif ($name === 'reply-to') {
                $parsed['reply_to'] = array_merge($parsed['reply_to'], $this->address_list($value));
            } elseif (!in_array($name, ['mime-version', 'x-mailer'], true)) {
                $parsed['custom'][sanitize_text_field($name)] = sanitize_text_field($value);
            }
        }

        return $parsed;
    }

    /**
     * Découper une liste d'adresses au format wp_mail.
     *
     * @param mixed $value
     * @return array<int, array{email: string, name: string}>
     */
    private function address_list($value): array {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', (string) $value) ?: [];
        }

        $addresses = [];
        foreach ($parts as $part) {
            if (!is_string($part)) {
                continue;
            }

            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $email = $part;
            $name = '';
            if (preg_match('/^(.*)<([^>]+)>$/', $part, $matches) === 1) {
                $name = trim($matches[1], " \t\n\r\0\x0B\"");
                $email = trim($matches[2]);
            }

            $email = sanitize_email($email);
            if (!is_email($email)) {
                continue;
            }

            $addresses[] = [
                'email' => $email,
                'name' => sanitize_text_field($name),
            ];
        }

        return $addresses;
    }
}
