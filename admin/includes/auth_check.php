<?php
// /zamowienie/admin/includes/auth_check.php

// Upewnij się, że sesja jest aktywna
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sprawdź, czy zmienna sesji 'admin_logged_in' jest ustawiona i ma wartość true
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Jeśli nie, zapisz komunikat i przekieruj do strony logowania
    $_SESSION['login_error'] = "Musisz się zalogować, aby uzyskać dostęp do tej strony.";
    header("Location: index.php"); // Zakładając, że index.php jest stroną logowania w tym folderze
    exit; // Zakończ wykonywanie skryptu
}

// Opcjonalnie: Dodatkowe sprawdzanie bezpieczeństwa, np. User Agent, IP
// if (!isset($_SESSION['user_agent']) || $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
//     // Podejrzenie przejęcia sesji - wyloguj i przekieruj
//     session_destroy();
//     header("Location: index.php?error=session_hijack");
//     exit;
// }

?>