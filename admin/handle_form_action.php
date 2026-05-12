<?php
// /zamowienie/admin/handle_form_action.php

require_once 'includes/auth_check.php'; // Wymaga logowania
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Sprawdź token CSRF (kluczowe dla bezpieczeństwa!)
if (!isset($_GET['token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
    $_SESSION['error_message'] = "Nieprawidłowy token bezpieczeństwa. Akcja anulowana.";
    header("Location: forms.php");
    exit;
}

// Pobierz akcję i ID
$action = $_GET['action'] ?? null;
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    $_SESSION['error_message'] = "Nieprawidłowe ID formularza.";
    header("Location: forms.php");
    exit;
}

try {
    if ($action === 'delete_form') {
        // --- Usuwanie Formularza (i powiązanych zamówień) ---
        
        // Rozpocznij transakcję
        $pdo->beginTransaction();
        
        // 1. Usuń powiązane zamówienia (CASCADE)
        // Uwaga: Jeśli masz klucz obcy z ON DELETE CASCADE, ta linia nie jest konieczna.
        // Ale dla pewności, jeśli go nie ma, robimy to ręcznie.
        $stmt_delete_orders = $pdo->prepare("DELETE FROM orders WHERE form_id = ?");
        $stmt_delete_orders->execute([$id]);
        
        // 2. Usuń formularz
        $stmt_delete_form = $pdo->prepare("DELETE FROM forms WHERE id = ?");
        $stmt_delete_form->execute([$id]);
        
        // Zatwierdź transakcję
        $pdo->commit();
        
        $_SESSION['success_message'] = "Formularz (ID: {$id}) oraz wszystkie powiązane z nim zamówienia zostały usunięte.";

    } elseif ($action === 'toggle_form') {
        // --- Aktywacja / Deaktywacja Formularza ---
        $stmt_toggle = $pdo->prepare("UPDATE forms SET is_active = NOT is_active, updated_at = NOW() WHERE id = ?");
        $stmt_toggle->execute([$id]);
        
        $_SESSION['success_message'] = "Zmieniono status formularza (ID: {$id}).";
        
    } else {
        $_SESSION['error_message'] = "Nieznana akcja.";
    }

} catch (\PDOException $e) {
    // Wycofaj transakcję w razie błędu
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("DB Error in handle_form_action.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Wystąpił błąd bazy danych podczas wykonywania akcji.";
}

// Wróć do listy formularzy
header("Location: forms.php");
exit;
?>
