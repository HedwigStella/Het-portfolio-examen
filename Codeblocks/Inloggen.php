<?php
if ( is_user_logged_in() ) {
    echo '<p>Je bent al ingelogd.</p>';
} else {
    wp_login_form([
        'redirect'     => home_url('/workshops/'),
        'label_log_in' => 'Inloggen',
    ]);
}
?>