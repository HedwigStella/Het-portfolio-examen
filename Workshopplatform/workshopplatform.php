<?php
/**
 * Plugin Name: Workshopplatform
 * Description: Accountnavigatie en workshopinschrijvingen.
 * Version: 1.1.1
 */

defined('ABSPATH') || exit;

class Workshopplatform
{
    public function __construct()
    {
        add_action('init', [$this, 'registreer_inschrijvingen']);
        add_shortcode('workshop_inschrijven', [$this, 'formulier']);
        add_action('admin_post_workshop_inschrijven', [$this, 'inschrijven']);
        add_action('admin_post_nopriv_workshop_inschrijven', [$this, 'inschrijven']);
        add_filter('login_redirect', [$this, 'na_inloggen'], 10, 3);
        add_filter('show_admin_bar', [$this, 'toon_toolbar']);
        add_action('admin_init', [$this, 'bescherm_dashboard']);
    }

    private function is_deelnemer()
    {
        return is_user_logged_in() && !current_user_can('edit_posts');
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
        return $this->is_deelnemer() ? false : $tonen;
    }

    public function bescherm_dashboard()
    {
        if (
            wp_doing_ajax() ||
            ($GLOBALS['pagenow'] ?? '') === 'admin-post.php'
        ) {
            return;
        }

        if ($this->is_deelnemer()) {
            wp_safe_redirect(home_url('/workshops/'));
            exit;
        }
    }

    public function registreer_inschrijvingen()
    {
        register_post_type('wp_inschrijving', [
            'labels' => [
                'name' => 'Inschrijvingen',
                'singular_name' => 'Inschrijving',
            ],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    private function geldige_workshop($id)
    {
        return get_post_type($id) === 'workshop'
            && get_post_status($id) === 'publish';
    }

    private function workshopgegevens($id)
    {
        $datum = get_post_meta($id, 'workshop_datum', true);
        $tijd = get_post_meta($id, 'workshop_tijd', true);
        $datumtijd = $datum . ' ' . $tijd;

        $start = DateTimeImmutable::createFromFormat(
            '!Ymd H:i:s',
            $datumtijd,
            wp_timezone()
        );

        $maximum = filter_var(
            get_post_meta($id, 'workshop_max_deelnemers', true),
            FILTER_VALIDATE_INT
        );

        $inschrijvingen = get_posts([
            'post_type' => 'wp_inschrijving',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_key' => 'workshop_id',
            'meta_value' => $id,
        ]);

        return [
            'geldig' => $this->geldige_workshop($id)
                && $start
                && $start->format('Ymd H:i:s') === $datumtijd
                && $maximum > 0,
            'start' => $start,
            'vrij' => max(0, (int) $maximum - count($inschrijvingen)),
            'deelnemers' => array_map(
                'intval',
                wp_list_pluck($inschrijvingen, 'post_author')
            ),
        ];
    }

    private function status($gegevens)
    {
        if (!$gegevens['geldig']) {
            return 'ongeldig';
        }

        if (in_array(get_current_user_id(), $gegevens['deelnemers'], true)) {
            return 'ingeschreven';
        }

        if ($gegevens['start'] <= current_datetime()) {
            return 'gesloten';
        }

        return $gegevens['vrij'] > 0 ? 'open' : 'vol';
    }

    public function formulier()
    {
        $id = get_queried_object_id();

        if (get_post_type($id) !== 'workshop') {
        return '';
        }

        $gegevens = $this->workshopgegevens($id);
        $status = $this->status($gegevens);

        $meldingen = [
            'ongeldig' => 'Inschrijven is momenteel niet mogelijk.',
            'ingeschreven' => 'Je bent ingeschreven voor deze workshop.',
            'gesloten' => 'De inschrijving voor deze workshop is gesloten.',
            'vol' => 'Deze workshop is volgeboekt.',
        ];

        if ($status === 'ongeldig') {
            return '<p role="status">' .
                esc_html($meldingen[$status]) . '</p>';
        }

        ob_start();
        ?>

            <div style="display:flex;justify-content:space-between;gap:20px;width:100%;padding:24px 0;border-bottom:1px solid #161616;margin-bottom:24px">
            <span>BESCHIKBARE PLAATSEN</span>
            <span><?php echo (int) $gegevens['vrij']; ?></span>
        </div>

        <?php if ($status !== 'open') : ?>

            <p role="status">
                <?php echo esc_html($meldingen[$status]); ?>
            </p>

        <?php elseif (!is_user_logged_in()) : ?>

            <p>
                <a href="<?php echo esc_url(home_url('/inloggen/')); ?>">
                Log in
                </a>
                of
                <a href="<?php echo esc_url(home_url('/registreren/')); ?>">
                maak een account aan
                </a>
                om je in te schrijven.
            </p>

        <?php else : ?>

            <?php if (($_GET['inschrijving'] ?? '') === 'fout') : ?>
                <p role="alert">
                    Opslaan is niet gelukt. Probeer het opnieuw.
                </p>
            <?php endif; ?>

            <form method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>">

                <input type="hidden"
                    name="action"
                    value="workshop_inschrijven">

                <input type="hidden"
                    name="workshop_id"
                    value="<?php echo (int) $id; ?>">

                <?php wp_nonce_field('inschrijven_' . $id); ?>

                <button type="submit"
                        style="background:#161616;color:#F7F7F2;border:0;border-radius:999px;padding:14px 24px;font:inherit;cursor:pointer">
                    Inschrijven
                </button>
            </form>

        <?php endif; ?>

        <?php
        return ob_get_clean();
    }

    public function inschrijven()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Gebruik de inschrijfknop op de workshopdetailpagina.');
        }

        if (!is_user_logged_in() || !current_user_can('read')) {
            wp_safe_redirect(home_url('/inloggen/'));
            exit;
        }

        $invoer = $_POST['workshop_id'] ?? '';
        $id = is_scalar($invoer) ? absint($invoer) : 0;

        check_admin_referer('inschrijven_' . $id);

        if (!$this->geldige_workshop($id)) {
            wp_die('Deze workshop is niet beschikbaar.');
        }

        global $wpdb;

        // Eén inschrijfverzoek tegelijk per workshop.
        $slot = 'workshop_' . md5(DB_NAME . $wpdb->prefix . $id);
        $vergrendeld = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 5)', $slot)
        );
        $resultaat = 'fout';

        if ((int) $vergrendeld === 1) {
            try {
                // Controleer opnieuw: de beschikbaarheid kan veranderd zijn.
                $status = $this->status($this->workshopgegevens($id));

                if ($status === 'open') {
                    $inschrijving = wp_insert_post([
                        'post_type' => 'wp_inschrijving',
                        'post_status' => 'publish',
                        'post_author' => get_current_user_id(),
                        'post_title' => get_the_title($id)
                            . ' — ' . wp_get_current_user()->display_name,
                        'meta_input' => ['workshop_id' => $id],
                    ], true);

                    $resultaat = !is_wp_error($inschrijving) && $inschrijving
                        ? 'gelukt'
                        : 'fout';
                } else {
                    $resultaat = $status;
                }
            } finally {
                $wpdb->get_var(
                    $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $slot)
                );
            }
        }

        wp_safe_redirect(
            add_query_arg('inschrijving', $resultaat, get_permalink($id))
        );
        exit;
    }
}

new Workshopplatform();