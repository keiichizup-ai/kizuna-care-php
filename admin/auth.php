<?php
session_start();

require_once __DIR__ . '/../config/config.php';

function admin_logged_in()
{
    return !empty($_SESSION['admin_logged_in']);
}

function require_admin()
{
    if (!admin_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

