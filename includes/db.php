<?php
// /zamowienie/includes/db.php
require_once 'config.php'; // Wczytaj dane dostępowe (DB_HOST, DB_NAME, etc.)

// Zdefiniuj Data Source Name (DSN) dla PDO
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

// Ustaw opcje dla połączenia PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Rzucaj wyjątki w przypadku błędów SQL
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Zwracaj wyniki jako tablice asocjacyjne domyślnie
    PDO::ATTR_EMULATE_PREPARES   => false, // Używaj prawdziwych prepared statements (bezpieczniejsze)
];

try {
     // Utwórz nowe połączenie PDO i zapisz je w zmiennej $pdo
     $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch (\PDOException $e) {
     // W przypadku błędu połączenia, zaloguj go
     error_log("DB Connection Error: " . $e->getMessage());
     // Wyświetl ogólny komunikat błędu użytkownikowi (nie pokazuj szczegółów na produkcji)
     die("Wystąpił krytyczny błąd połączenia z bazą danych. Prosimy spróbować ponownie później lub skontaktować się z administratorem.");
}

// Funkcja get_setting() została USUNIĘTA z tego pliku,
// ponieważ jej definicja znajduje się teraz w includes/functions.php

?>