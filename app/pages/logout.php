<?php
defined('SIXPENCE') || exit;

// Sign-out is a POST so other sites can't log people out with a link or image tag.
if (!is_post()) {
    redirect(current_user() ? 'dashboard' : '');
}

verify_csrf();
log_out();

start_secure_session();
flash('info', 'You\'ve been signed out.');
redirect('login');
