<?php
// /zamowienie/admin/dashboard.php

// Wymagaj sprawdzenia autoryzacji - ten plik musi być na początku!
require_once 'includes/auth_check.php';
// Wczytaj połączenie z bazą (może być potrzebne do wyświetlenia statystyk)
require_once '../includes/db.php';
// Wczytaj nagłówek HTML
include 'includes/header.php';

// Tutaj w przyszłości można dodać pobieranie i wyświetlanie statystyk,
// np. liczby nowych zamówień, ostatnich logów itp.
// $stmt_new_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE repair_status = 'new'");
// $new_orders_count = $stmt_new_orders->fetchColumn();

?>

<h2>Dashboard</h2>

<p>Witaj w panelu administracyjnym aplikacji BUTOLOG Zamówienia.</p>
<p>Wybierz jedną z opcji z menu powyżej, aby zarządzać formularzami, zamówieniami lub ustawieniami systemu.</p>

<div class="stats-container" style="margin-top: 30px; display: flex; gap: 20px;">
    <div class="stat-box" style="flex: 1; background: #eee; padding: 15px; border-radius: 5px; text-align: center;">
        <h3>Nowe Zamówienia</h3>
        <p style="font-size: 2em; margin: 10px 0;">
            <?php
                // Przykład pobrania liczby nowych zamówień
                try {
                    $stmt_new = $pdo->query("SELECT COUNT(*) FROM orders WHERE repair_status = 'new' AND payment_status = 'paid'");
                    echo $stmt_new->fetchColumn();
                } catch (PDOException $e) { echo '?'; }
            ?>
        </p>
        <small>(Opłacone, oczekujące na etykietę lub dalsze kroki)</small>
    </div>
     <div class="stat-box" style="flex: 1; background: #eee; padding: 15px; border-radius: 5px; text-align: center;">
        <h3>Aktywne Formularze</h3>
        <p style="font-size: 2em; margin: 10px 0;">
             <?php
                try {
                    $stmt_forms = $pdo->query("SELECT COUNT(*) FROM forms WHERE is_active = TRUE");
                    echo $stmt_forms->fetchColumn();
                } catch (PDOException $e) { echo '?'; }
            ?>
        </p>
         <small>(Liczba aktywnych linków dla klientów)</small>
    </div>
    </div>

<?php
// Wczytaj stopkę HTML
include 'includes/footer.php';
?>