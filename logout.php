<?php
declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

// Logout is POST + CSRF only, so another site can't sign the cashier out with a link.
if (!is_post()) {
    redirect('index.php');
}
Csrf::verifyRequest();

Auth::logout();
flash('success', 'You have been signed out.');
redirect('login.php');
