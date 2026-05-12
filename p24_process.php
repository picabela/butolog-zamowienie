<?php
// /zamowienie/p24_process.php

// Wczytaj konfigurację, połączenie z bazą i funkcje pomocnicze
require_once 'includes/db.php';
require_once 'includes/functions.php'; // Potrzebne generate_uuid() i P24 API call

// Upewnij się, że sesja jest aktywna (potrzebna do komunikatów o błędach)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sprawdź, czy żądanie jest metodą POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Jeśli ktoś wszedł tu bezpośrednio przez GET, przekieruj
    header("Location: index.php");
    exit;
}

// --- FUNKCJA POMOCNICZA DO OBSŁUGI BŁĘDÓW (specyficzna dla tego pliku) ---
// Zapisuje błąd ORAZ dane formularza do sesji i przekierowuje
function redirect_with_error($message, $form_uuid, $post_data) {
    $_SESSION['error_message'] = $message;
    // Zapisz wszystkie dane z POST do sesji, aby ponownie wypełnić formularz
    $_SESSION['form_data'] = [
        'sender_name' => $post_data['sender_name'] ?? '',
        'sender_email' => $post_data['sender_email'] ?? '',
        'sender_phone' => $post_data['sender_phone'] ?? '', // Zapisz surową (nieoczyszczoną) wartość
        'parcel_size' => $post_data['parcel_size'] ?? 'medium',
        'return_locker_id' => $post_data['return_locker_id'] ?? '',
        'return_locker_street' => $post_data['return_locker_street'] ?? '',
        'return_locker_postcode' => $post_data['return_locker_postcode'] ?? '',
        'return_locker_city' => $post_data['return_locker_city'] ?? '',
    ];
    // Upewnij się, że UUID nie jest puste
    if (empty($form_uuid)) {
        // Fallback, jeśli UUID formularza nie zostało znalezione
        log_message("Błąd krytyczny w redirect_with_error: Brak UUID formularza. Przekierowanie do index.php.");
        header("Location: index.php");
    } else {
        header("Location: form.php?uuid=" . $form_uuid);
    }
    exit;
}
// ------------------------------------------


// 1. Walidacja i bezpieczne pobranie danych z formularza POST
// Używamy trim() i sprawdzamy $_POST zamiast przestarzałego FILTER_SANITIZE_STRING
$form_id = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
$amount_from_form = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT); // Otrzymana cena końcowa
$service_name = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
$sender_name = isset($_POST['sender_name']) ? trim($_POST['sender_name']) : '';
$sender_email = filter_input(INPUT_POST, 'sender_email', FILTER_VALIDATE_EMAIL);
$sender_phone_raw = isset($_POST['sender_phone']) ? trim($_POST['sender_phone']) : ''; // Pobierz surową wartość
$return_locker_id = isset($_POST['return_locker_id']) ? trim($_POST['return_locker_id']) : '';
$return_locker_id = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $return_locker_id)); // Oczyść ID paczkomatu

// Pobierz dane adresowe paczkomatu
$return_locker_street = isset($_POST['return_locker_street']) ? trim($_POST['return_locker_street']) : '';
$return_locker_postcode = isset($_POST['return_locker_postcode']) ? trim($_POST['return_locker_postcode']) : '';
$return_locker_city = isset($_POST['return_locker_city']) ? trim($_POST['return_locker_city']) : '';
// Pobierz wybrany rozmiar paczki
$parcel_size = isset($_POST['parcel_size']) ? trim($_POST['parcel_size']) : '';

// Zmienna do przechowywania UUID formularza na wypadek błędu
$form_uuid_for_redirect = '';
if ($form_id) {
    try {
        $stmt_uuid_lookup = $pdo->prepare("SELECT uuid FROM forms WHERE id = ?");
        $stmt_uuid_lookup->execute([$form_id]);
        $form_uuid_for_redirect = $stmt_uuid_lookup->fetchColumn() ?: '';
    } catch (\PDOException $e) {
        log_message("DB Error fetching UUID for form_id {$form_id}: " . $e->getMessage());
        // Nie przerywaj, walidacja i tak wykryje błąd
    }
}

// Walidacja numeru telefonu (9 cyfr)
$sender_phone_cleaned = preg_replace('/[^0-9]/', '', $sender_phone_raw);
$is_phone_valid = preg_match('/^[0-9]{9}$/', $sender_phone_cleaned);

// Sprawdź, czy wszystkie wymagane pola są poprawne
if (
    !$form_id || $amount_from_form === false || empty($service_name) ||
    empty($sender_name) || !$sender_email || empty($sender_phone_cleaned) ||
    !$is_phone_valid || // <-- Walidacja telefonu
    empty($return_locker_id) || !in_array($parcel_size, ['medium', 'large'])
) {
    $error_msg = "Nieprawidłowe lub brakujące dane w formularzu. Proszę wypełnić wszystkie pola poprawnie.";
    if (!$is_phone_valid && !empty($sender_phone_raw)) {
        $error_msg = "Numer telefonu jest nieprawidłowy. Proszę podać 9 cyfr, np. 600123456.";
    }
    redirect_with_error($error_msg, $form_uuid_for_redirect, $_POST);
}
// Użyj oczyszczonego numeru telefonu do zapisu
$sender_phone_to_save = $sender_phone_cleaned;


// 2. Weryfikacja ceny z bazą danych (Kluczowe!)
try {
    if (!$form_data = $pdo->query("SELECT price, uuid FROM forms WHERE id = $form_id AND is_active = TRUE")->fetch()) { // Szybkie zapytanie, bo form_id jest INT
        throw new Exception("Formularz nie istnieje lub nieaktywny.");
    }

    $db_price = (float)$form_data['price'];
    $form_uuid = $form_data['uuid']; // Upewnij się, że mamy poprawny UUID
    $form_uuid_for_redirect = $form_uuid; // Zaktualizuj na pewno poprawny UUID

    $large_parcel_surcharge_str = get_setting('large_parcel_surcharge', $pdo);
    $large_parcel_surcharge = ($large_parcel_surcharge_str !== null) ? (float)$large_parcel_surcharge_str : 5.00;

    $expected_final_price = $db_price;
    if ($parcel_size === 'large') {
        $expected_final_price += $large_parcel_surcharge;
    }
    $expected_final_price = round($expected_final_price, 2);

    if (abs($expected_final_price - $amount_from_form) > 0.01) {
         log_message("Błąd niezgodności ceny! Oczekiwano (z bazy + dopłata): {$expected_final_price}, Otrzymano (z formularza): {$amount_from_form}");
        throw new Exception("Niezgodność ceny przesłanej z formularza z ceną obliczoną na serwerze.");
    }
    
    $amount_grosze = (int) round($expected_final_price * 100);

} catch (\PDOException | \Exception $e) {
    error_log("Błąd weryfikacji formularza/ceny (form_id: {$form_id}): " . $e->getMessage());
    redirect_with_error("Wystąpił błąd podczas weryfikacji danych formularza: " . $e->getMessage(), $form_uuid_for_redirect, $_POST);
}

// 3. Generowanie Session ID i zapis zamówienia do bazy danych
$session_id = 'ORD-' . strtoupper(bin2hex(random_bytes(8)));

try {
    // Zapisz zamówienie ze wszystkimi danymi
    $stmt_insert = $pdo->prepare(
        "INSERT INTO orders (
            form_id, session_id, sender_name, sender_email, sender_phone, 
            return_locker_id, return_locker_street, return_locker_postcode, return_locker_city, 
            parcel_size, amount, currency, payment_status, repair_status, created_at, updated_at
         )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PLN', 'pending', 'new', NOW(), NOW())"
    );
    $stmt_insert->execute([
        $form_id, $session_id, $sender_name, $sender_email, $sender_phone_to_save, 
        $return_locker_id, $return_locker_street, $return_locker_postcode, $return_locker_city,
        $parcel_size, 
        $expected_final_price
    ]);
} catch (\PDOException $e) {
    $error_msg = ($e->getCode() == 23000) ? "Błąd ID sesji. Spróbuj ponownie." : "Błąd serwera podczas zapisu zamówienia.";
    error_log("DB Error inserting order: " . $e->getMessage());
    redirect_with_error($error_msg, $form_uuid, $_POST);
}

// 4. Pobranie *aktywnej* konfiguracji P24 z bazy
$p24_posId = get_active_setting('p24_pos_id', $pdo);
$p24_crcKey = get_active_setting('p24_crc_key', $pdo);
$p24_apiKey = get_active_setting('p24_api_key', $pdo);
$p24_sandbox_enabled = get_setting('p24_sandbox_enabled', $pdo);

// Logowanie odczytanych ustawień
log_message("Odczytano ustawienia P24 dla p24_process.php:");
log_message(" - Tryb Sandbox włączony? " . ($p24_sandbox_enabled ? 'TAK' : 'NIE'));
log_message(" - Używany POS ID: " . ($p24_posId ? $p24_posId : 'BRAK DANYCH'));
log_message(" - Używany Klucz CRC: " . ($p24_crcKey ? substr($p24_crcKey, 0, 5) . '...' : 'BRAK DANYCH'));
log_message(" - Używany Klucz API (pełny): " . ($p24_apiKey ? $p24_apiKey : 'BRAK DANYCH'));

$p24_apiUrlBase = $p24_sandbox_enabled ? "https://sandbox.przelewy24.pl/api/v1" : "https://secure.przelewy24.pl/api/v1";
$p24_redirectBase = $p24_sandbox_enabled ? "https://sandbox.przelewy24.pl/trnRequest/" : "https://secure.przelewy24.pl/trnRequest/";
$p24_registerUrl = $p24_apiUrlBase . '/transaction/register';

log_message(" - Używany URL API P24 do rejestracji: " . $p24_registerUrl);

// Sprawdzenie, czy aktywne klucze zostały pobrane
if (!$p24_posId || !$p24_crcKey || !$p24_apiKey) {
    $mode = $p24_sandbox_enabled ? 'sandbox' : 'production';
    $error_msg = "Błąd konfiguracji systemu płatności ({$mode}). Skontaktuj się z administratorem.";
    error_log("Krytyczny błąd: Brak aktywnych kluczy API Przelewy24 ({$mode}) w bazie danych.");
    redirect_with_error($error_msg, $form_uuid, $_POST);
}

// Ustalenie pełnych adresów URL powrotu i statusu
$appBaseUrl = "https://butolog.pl/zamowienie";
$urlReturn = $appBaseUrl . '/p24_return.php';
$urlStatus = $appBaseUrl . '/p24_status.php';

// 5. Przygotowanie danych i podpisu ('sign') dla Przelewy24 API
$signData = [
    "sessionId" => $session_id,
    "merchantId" => (int)$p24_posId,
    "amount" => $amount_grosze, // Użyj $amount_grosze (obliczonej z $expected_final_price)
    "currency" => "PLN",
    "crc" => $p24_crcKey
];

$sign = hash('sha384', json_encode($signData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$requestPayload = [
    "merchantId" => (int)$p24_posId,
    "posId" => (int)$p24_posId,
    "sessionId" => $session_id,
    "amount" => $amount_grosze,
    "currency" => "PLN",
    "description" => $service_name . " (Zam: " . $session_id . ")",
    "email" => $sender_email,
    "client" => $sender_name,
    "phone" => $sender_phone_to_save, // Wysyłaj oczyszczony numer
    "country" => "PL",
    "language" => "pl",
    "urlReturn" => $urlReturn,
    "urlStatus" => $urlStatus,
    "sign" => $sign
];

// 6. Wywołanie API Przelewy24
log_message("P24 Register Request for sessionId {$session_id} (Mode: " . ($p24_sandbox_enabled ? 'Sandbox' : 'Production') . "): " . json_encode($requestPayload));
$p24Result = call_p24_api($p24_registerUrl, $p24_posId, $p24_apiKey, $requestPayload, 'POST');
log_message("P24 Register Response for sessionId {$session_id} (HTTP {$p24Result['http_code']}): " . $p24Result['response']);

// 7. Przetworzenie odpowiedzi z P24
if ($p24Result['http_code'] == 200 && isset($p24Result['data']['data']['token'])) {
    $token = $p24Result['data']['data']['token'];
    $paymentLink = $p24_redirectBase . $token;
    log_message("P24 registration successful for sessionId {$session_id}. Token: {$token}. Redirecting to: {$paymentLink}");
    
    // Wyczyść dane formularza z sesji po sukcesie
    unset($_SESSION['form_data']);

    header("Location: " . $paymentLink);
    exit;
} else {
    // Błąd podczas rejestracji w P24
    $errorDetails = $p24Result['response'] ?? 'Brak odpowiedzi P24.';
    if(isset($p24Result['data']['error'])) { 
        $errorDetails = "P24 Error: " . ($p24Result['data']['error'] ?? 'Unknown') . " (Code: " . ($p24Result['data']['code'] ?? 'N/A') . ")"; 
    } elseif(isset($p24Result['data']['errors'])) { 
        $errorDetails = "P24 Validation Errors: " . json_encode($p24Result['data']['errors']); 
    } elseif ($p24Result['http_code'] === 0) { 
        $errorDetails = "Błąd połączenia z serwerem Przelewy24."; 
    }
    
    error_log("P24 Register Error for sessionId {$session_id} (HTTP {$p24Result['http_code']}): " . $errorDetails);
    
    try { 
        $pdo->prepare("UPDATE orders SET payment_status = 'cancelled', updated_at = NOW() WHERE session_id = ?")->execute([$session_id]); 
    } catch (\PDOException $e) { 
        error_log("DB Error updating order to cancelled for {$session_id}: " . $e->getMessage()); 
    }
    
    redirect_with_error(
        "Nie udało się rozpocząć procesu płatności. Szczegóły: " . htmlspecialchars($errorDetails),
        $form_uuid,
        $_POST
    );
}

?>

