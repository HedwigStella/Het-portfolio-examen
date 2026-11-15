<?php
/**
 * Plugin Name: Workshopplatform
 * Description: Accountnavigatie voor deelnemers aan workshops.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

class Workshopplatform
{
    public function __construct()
    {
        add_filter('login_redirect', [$this, 'na_inloggen'], 10, 3);
        add_filter('show_admin_bar', [$this, 'toon_toolbar']);
        add_action('admin_init', [$this, 'bescherm_dashboard']);
    }

    public function na_inloggen($redirect, $requested_redirect, $user)
    {
        if ($user instanceof WP_User && !user_can($user, 'edit_posts')) {
            return home_url('/workshops/');
        }

        return $redirect;
    }

    public function toon_toolbar($tonen)
    {
        if (is_user_logged_in() && !current_user_can('edit_posts')) {
            return false;
        }

        return $tonen;
    }

    public function bescherm_dashboard()
    {
        // Verzoeken voor formulieren en achtergrondacties blijven werken.
        if (
            wp_doing_ajax() ||
            ($GLOBALS['pagenow'] ?? '') === 'admin-post.php'
        ) {
            return;
        }

        if (is_user_logged_in() && !current_user_can('edit_posts')) {
            wp_safe_redirect(home_url('/workshops/'));
            exit;
        }
    }
}

new Workshopplatform();