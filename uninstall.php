<?php
/**
 * Nettoyage à la désinstallation.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('cf7_smtp_mail_settings');
delete_transient('cf7_smtp_github_release');
delete_transient('cf7_smtp_google_access_token');
