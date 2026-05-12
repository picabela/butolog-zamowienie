<?php
// /zamowienie/fetch_label.php

// Wczytaj konfigurację, połączenie z bazą i funkcje pomocnicze
require_once 'includes/db.php'; // Potrzebne do $pdo
require_once 'includes/functions.php'; // Potrzebne do log_message, get_setting, get_active_setting

// === POBIERZ AKTYWNĄ KONFIGURACJĘ INPOST Z BAZY ===
$inpost_token = get_active_setting('inpost_token', $pdo); // <-- POPRAWKA: Pobierz aktywny token (prod lub sandbox)
$inpost_sandbox_enabled = get_setting('inpost_sandbox_enabled', $pdo); // Sprawdź tryb pracy z bazy
$inpost_apiUrlBase = $inpost_sandbox_enabled ? "https://sandbox-api-shipx-pl.easypack24.net/v1" : "https://api-shipx-pl.easypack24.net/v1";
$inpost_labelUrlBase = $inpost_apiUrlBase . "/shipments/"; // Bazowy URL do etykiet

// Sprawdź, czy token został w ogóle wczytany
if (empty($inpost_token)) {
    $mode_text = $inpost_sandbox_enabled ? 'sandbox' : 'production';
    log_message("fetch_label.php: KRYTYCZNY BŁĄD - Brak aktywnego tokenu InPost ({$mode_text}) w konfiguracji.");
    http_response_code(500);
    die("Błąd konfiguracji serwera: Brak tokenu API InPost.");
}

// =================================================

// Sprawdź, czy przekazano ID przesyłki
if (!isset($_GET['shipment_id']) || !ctype_digit((string)$_GET['shipment_id'])) {
    log_message("fetch_label.php: Błąd - Brak lub nieprawidłowe ID przesyłki w GET.");
    http_response_code(400);
    die("Błąd: Brak lub nieprawidłowe ID przesyłki.");
}

$shipmentId = $_GET['shipment_id'];
$labelUrl = $inpost_labelUrlBase . $shipmentId . '/label?format=pdf'; // Skonstruuj pełny URL do API InPost

log_message("fetch_label.php: Żądanie pobrania etykiety dla ID: {$shipmentId} z URL: {$labelUrl}");
log_message("fetch_label.php: Używam tokenu InPost: " . substr($inpost_token, 0, 10) . "..."); // Loguj początek używanego tokenu

// === Pobierz etykietę z API InPost ===
$ch = curl_init($labelUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, // Zwróć odpowiedź jako string
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $inpost_token // Uwierzytelnienie AKTYWNYM tokenem!
    ],
    CURLOPT_SSL_VERIFYPEER => false, // Zmień na true na produkcji (wymaga poprawnych certyfikatów na serwerze)
    CURLOPT_SSL_VERIFYHOST => false, // Zmień na 2 na produkcji
    CURLOPT_FOLLOWLOCATION => true, // Podążaj za przekierowaniami
    CURLOPT_FAILONERROR => false, // Nie traktuj kodów HTTP 4xx/5xx jako błędu cURL
    CURLOPT_TIMEOUT => 30, // Limit czasu
]);

$pdfContent = curl_exec($ch); // Wykonaj żądanie
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Pobierz kod statusu HTTP
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); // Pobierz typ zawartości odpowiedzi
$curlError = curl_error($ch); // Sprawdź, czy wystąpił błąd cURL
curl_close($ch);

// === Sprawdź odpowiedź i zwróć PDF lub błąd ===
if ($curlError) {
    log_message("fetch_label.php: Błąd cURL podczas pobierania etykiety dla ID {$shipmentId}: " . $curlError);
    http_response_code(500);
    die("Wystąpił błąd serwera podczas próby pobrania etykiety. Skontaktuj się z administratorem.");
} elseif ($httpCode == 200 && $pdfContent !== false && strpos($contentType, 'application/pdf') !== false) {
    // Sukces - otrzymano PDF
    log_message("fetch_label.php: Pomyślnie pobrano etykietę PDF dla ID: {$shipmentId}");

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="etykieta_inpost_' . $shipmentId . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private');

    echo $pdfContent;
    exit;
} else {
    // Wystąpił błąd po stronie InPost lub inny problem
    log_message("fetch_label.php: Błąd podczas pobierania etykiety dla ID {$shipmentId}. HTTP: {$httpCode}. Content-Type: {$contentType}. Odpowiedź: " . substr($pdfContent, 0, 500));
    http_response_code($httpCode == 401 ? 401 : ($httpCode >= 400 ? $httpCode : 500));

    echo "<!DOCTYPE html><html lang='pl'><head><meta charset='UTF-8'><title>Błąd Pobierania Etykiety</title></head><body>";
    echo "<h1>Błąd podczas pobierania etykiety</h1>";
    echo "<p>Nie udało się pobrać etykiety dla przesyłki o ID: " . htmlspecialchars($shipmentId) . ".</p>";
    if ($httpCode == 401) {
        echo "<p><strong>Przyczyna:</strong> Błąd autoryzacji (nieprawidłowy lub wygasły token API InPost).</p>";
    } elseif ($httpCode == 404) {
        echo "<p><strong>Przyczyna:</strong> Nie znaleziono przesyłki o podanym ID.</p>";
    } else {
        echo "<p><strong>Kod błędu HTTP:</strong> " . $httpCode . "</p>";
    }
    echo "<p>Proszę spróbować ponownie później lub skontaktować się z administratorem.</p>";
    
    if (!empty($pdfContent) && ($httpCode != 200 || strpos($contentType, 'application/pdf') === false)) {
       echo "<h2>Odpowiedź serwera InPost:</h2><pre style='background:#eee; padding:10px; border:1px solid #ccc; border-radius:4px; word-wrap:break-word; white-space:pre-wrap;'>" . htmlspecialchars($pdfContent) . "</pre>";
    }
    echo "</body></html>";
    exit;
}
?>