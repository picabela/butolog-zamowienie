<?php
// includes/config.php

// --- DANE DOSTĘPOWE DO BAZY DANYCH ---
define('DB_HOST', 'localhost'); // Zazwyczaj localhost, chyba że masz inaczej
define('DB_NAME', 'host322698_butolog_zamowienie_demo');
define('DB_USER', 'host322698_butolog_zamowienie_demo');
define('DB_PASS', 'ZhJakULAcUjm5WurBA2v');
define('DB_CHARSET', 'utf8mb4');
// ------------------------------------

// --- INNE USTAWIENIA (można dodać później) ---
// np. define('APP_URL', 'https://adsarna.pl/zamowienie');

// Ustawienia raportowania błędów (zalecane dla developera)
// Na produkcji ustaw display_errors na 0 i loguj błędy do pliku
error_reporting(E_ALL);
ini_set('display_errors', 0); // Produkcja — nie wyciekaj błędów PHP do output (psuje webhooki, JSON, redirecty)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../status_log.txt'); // Loguj błędy PHP do tego samego pliku co logi aplikacji (ścieżka absolutna)

// Ustawienie strefy czasowej
date_default_timezone_set('Europe/Warsaw');

// Start sesji (potrzebne dla panelu admina i komunikatów)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>