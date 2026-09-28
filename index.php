<?php
declare(strict_types=1);

require __DIR__ . '/system/bootstrap.php';

redirect(Auth::check() ? 'pages/pos.php' : 'login.php');
