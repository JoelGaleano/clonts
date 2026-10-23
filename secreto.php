<?php
declare(strict_types=1);

/**
 * Punto de entrada directo para enlaces de secretos (secreto.php?id=123).
 * Delega la vista e inyección de metadatos Open Graph a index.php.
 */
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $_GET['secreto'] = (int) $_GET['id'];
}
require __DIR__ . '/index.php';
