<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Router;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::logout();
}
Router::redirect('/login');
