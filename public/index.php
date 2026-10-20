<?php
require_once __DIR__ . '/../includes/bootstrap.php';

redirect(current_user() !== null ? '/dashboard.php' : '/login.php');
