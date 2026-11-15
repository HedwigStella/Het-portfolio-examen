<?php
if ( is_user_logged_in() ) {
    echo '<p>Je bent al ingelogd.</p>';
} else {
    printf(
        '<a class="account-knop" href="%s">Account aanmaken</a>',
        esc_url( wp_registration_url() )
    );
}
?>