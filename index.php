<?php
// index.php (główny router)
require_once 'includes/db.php'; // Potrzebne do połączenia z bazą

// Sprawdź, czy jest jakiś aktywny formularz w bazie
// Jeśli tak, przekieruj do niego (do pierwszego znalezionego)
// Jeśli nie, przekieruj do panelu admina (lub strony informacyjnej)

try {
    // Znajdź pierwszy aktywny formularz posortowany np. po ID
    $stmt = $pdo->prepare("SELECT uuid FROM forms WHERE is_active = TRUE ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $first_active_form_uuid = $stmt->fetchColumn();

    if ($first_active_form_uuid) {
        // Znaleziono aktywny formularz, przekieruj do niego
        header("Location: form.php?uuid=" . $first_active_form_uuid);
        exit;
    } else {
        // Brak aktywnych formularzy, przekieruj do panelu admina (do strony logowania)
        header("Location: admin/index.php");
        exit;
    }

} catch (\PDOException $e) {
    error_log("Error in main index.php: " . $e->getMessage());
    die("Wystąpił błąd aplikacji. Spróbuj ponownie później.");
}

?>