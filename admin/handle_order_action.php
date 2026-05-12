<?php
// /zamowienie/admin/handle_order_action.php

require_once 'includes/auth_check.php'; // Wymaga logowania
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Sprawdź token CSRF
if (!isset($_GET['token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
    $_SESSION['error_message'] = "Nieprawidłowy token bezpieczeństwa. Akcja anulowana.";
    header("Location: orders.php");
    exit;
}

// Pobierz akcję i ID
$action = $_GET['action'] ?? null;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    $_SESSION['error_message'] = "Nieprawidłowe ID zamówienia.";
    header("Location: orders.php");
    exit;
}

try {
    if ($action === 'delete_order') {
        // --- Usuwanie Zamówienia ---
        $stmt_delete_order = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt_delete_order->execute([$id]);
        
        if ($stmt_delete_order->rowCount() > 0) {
            $_SESSION['success_message'] = "Zamówienie (ID: {$id}) zostało pomyślnie usunięte.";
        } else {
             $_SESSION['error_message'] = "Nie znaleziono zamówienia o ID: {$id} do usunięcia.";
        }
        
    } else {
        $_SESSION['error_message'] = "Nieznana akcja.";
    }

} catch (\PDOException $e) {
    error_log("DB Error in handle_order_action.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Wystąpił błąd bazy danych podczas usuwania zamówienia.";
}

// Wróć do listy zamówień
header("Location: orders.php");
exit;
?>
