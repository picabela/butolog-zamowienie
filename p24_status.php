<?php
// /zamowienie/p24_status.php

// Ignoruj przerwanie połączenia przez klienta (P24), kontynuuj działanie skryptu
ignore_user_abort(true);
// Ustaw odpowiednio długi czas wykonywania skryptu
set_time_limit(120); // 2 minuty

require_once 'includes/db.php'; // Połączenie z bazą i funkcja get_setting()
require_once 'includes/functions.php'; // Funkcje pomocnicze

// --- Pobranie *aktywnej* konfiguracji z bazy danych ---
$p24_posId = get_active_setting('p24_pos_id', $pdo);
$p24_crcKey = get_active_setting('p24_crc_key', $pdo);
$p24_apiKey = get_active_setting('p24_api_key', $pdo);
$p24_sandbox_enabled = get_setting('p24_sandbox_enabled', $pdo);
$p24_apiUrlBase = $p24_sandbox_enabled ? "https://sandbox.przelewy24.pl/api/v1" : "https://secure.przelewy24.pl/api/v1";
$p24_verifyUrl = $p24_apiUrlBase . '/transaction/verify';

$inpost_org_id = get_active_setting('inpost_org_id', $pdo);
$inpost_token = get_active_setting('inpost_token', $pdo);
$inpost_sandbox_enabled = get_setting('inpost_sandbox_enabled', $pdo);
$inpost_apiUrlBase = $inpost_sandbox_enabled ? "https://sandbox-api-shipx-pl.easypack24.net/v1" : "https://api-shipx-pl.easypack24.net/v1";
$inpost_createShipmentUrl = $inpost_apiUrlBase . "/organizations/{$inpost_org_id}/shipments";
$inpost_getShipmentBaseUrl = $inpost_apiUrlBase . "/shipments/";

// Pobierz dane odbiorcy (serwisu) ORAZ e-mail nadawcy (do wysyłki kopii)
$receiver_name = get_setting('service_receiver_name', $pdo);
$receiver_email = get_setting('service_receiver_email', $pdo); // <-- E-mail admina/serwisu
$receiver_phone = get_setting('service_receiver_phone', $pdo);
$receiver_locker = get_active_setting('service_receiver_locker', $pdo);
$sender_name_override = get_setting('sender_name_override', $pdo);
$email_from_address = get_setting('email_from_address', $pdo); // <-- E-mail 'Od'
$email_from_name = get_setting('email_from_name', $pdo);

// Sprawdzenie kluczowych ustawień
if (!$p24_posId || !$p24_crcKey || !$p24_apiKey || !$inpost_org_id || !$inpost_token || !$receiver_locker || !$email_from_address || !$email_from_name || !$receiver_email) {
    log_message("KRYTYCZNY BŁĄD w p24_status.php: Brak kluczowych aktywnych ustawień (w tym 'service_receiver_email'). Sprawdź tabelę settings.");
    http_response_code(500); exit("Internal Server Configuration Error");
}

// === KROK 1: Odbierz powiadomienie P24 ===
log_message("--- Nowe powiadomienie P24 [p24_status.php] ---");
$jsonInput = file_get_contents('php://input');
log_message("Odebrano dane P24: " . $jsonInput);
$p24WebhookData = json_decode($jsonInput, true);
if (!$p24WebhookData || !isset($p24WebhookData['orderId']) || !isset($p24WebhookData['sessionId']) || !isset($p24WebhookData['amount']) || !isset($p24WebhookData['currency'])) {
    log_message("Błąd P24 Webhook: Nieprawidłowe dane JSON lub brak kluczowych pól.");
    http_response_code(400); exit("Invalid Webhook Data");
}
$session_id = $p24WebhookData['sessionId'];

// === KROK 1.5: Sprawdź zamówienie w bazie ===
try {
    // --- ZMIANA: Dołączamy tabelę 'forms' i pobieramy 'f.uuid' ---
    $sql_select = "SELECT 
                        o.id, o.payment_status, o.repair_status, o.sender_name, o.sender_email, o.sender_phone, 
                        o.return_locker_id, o.return_locker_street, o.return_locker_postcode, o.return_locker_city,
                        o.inpost_shipment_id, o.inpost_tracking_number, o.parcel_size, o.amount,
                        f.uuid as form_uuid 
                   FROM orders o
                   LEFT JOIN forms f ON o.form_id = f.id
                   WHERE o.session_id = ?";
    $stmt_check = $pdo->prepare($sql_select);
    $stmt_check->execute([$session_id]);
    $order = $stmt_check->fetch();
    // --------------------------------------------------
    if (!$order) {
        log_message("Błąd: Nie znaleziono zamówienia w bazie danych dla sessionId: " . $session_id);
        http_response_code(404); exit("Order Not Found");
    }
    if ($order['payment_status'] == 'paid' && $order['repair_status'] == 'label_generated') {
         log_message("Informacja: Otrzymano powtórne powiadomienie dla już przetworzonego zamówienia sessionId: " . $session_id);
         http_response_code(200); exit("OK - Already Processed");
    }
} catch (\PDOException $e) {
    error_log("DB Error checking order (sessionId: {$session_id}): " . $e->getMessage());
    http_response_code(500); exit("Internal Server Error - DB Check");
}

// === KROK 2: Weryfikacja P24 ===
log_message("Rozpoczynanie weryfikacji P24 dla sessionId: " . $session_id . ", P24 OrderID: " . $p24WebhookData['orderId']);
$amount_for_verification_grosze = (int) round((float)$order['amount'] * 100);
if ($amount_for_verification_grosze !== (int)$p24WebhookData['amount']) {
    log_message("KRYTYCZNY BŁĄD: Niezgodność kwoty! Otrzymano z P24: {$p24WebhookData['amount']}, Oczekiwano (z bazy): {$amount_for_verification_grosze}. SessionId: {$session_id}");
    http_response_code(400); exit("Amount mismatch");
}
$verifySignData = [ "sessionId" => $session_id, "orderId" => (int)$p24WebhookData['orderId'], "amount" => $amount_for_verification_grosze, "currency" => $p24WebhookData['currency'], "crc" => $p24_crcKey ];
$verifySign = hash('sha384', json_encode($verifySignData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$verifyPayload = [ "merchantId" => (int)$p24_posId, "posId" => (int)$p24_posId, "sessionId" => $session_id, "amount" => $amount_for_verification_grosze, "currency" => $p24WebhookData['currency'], "orderId" => (int)$p24WebhookData['orderId'], "sign" => $verifySign ];

log_message("Dane do podpisu weryfikacji: " . json_encode($verifySignData));
log_message("Pełny payload weryfikacji wysyłany do P24: " . json_encode($verifyPayload));
log_message("Klucze używane do autoryzacji weryfikacji: User=[" . $p24_posId . "] Key=[" . substr($p24_apiKey, 0, 5) . "...]");

$p24VerifyResult = call_p24_api($p24_verifyUrl, $p24_posId, $p24_apiKey, $verifyPayload, 'PUT');
log_message("Odpowiedź weryfikacji P24 (HTTP {$p24VerifyResult['http_code']}): " . $p24VerifyResult['response']);

// === KROK 3: Płatność zweryfikowana - InPost i Email ===
if ($p24VerifyResult['http_code'] == 200 && isset($p24VerifyResult['data']['data']['status']) && $p24VerifyResult['data']['data']['status'] == 'success') {
    log_message("Weryfikacja P24 Pomyślna dla sessionId: {$session_id}");
    
    $updatedRows = 0;
    if ($order['payment_status'] == 'pending') {
        try {
            $stmt_update_paid = $pdo->prepare("UPDATE orders SET payment_status = 'paid', p24_order_id = ?, updated_at = NOW() WHERE session_id = ? AND payment_status = 'pending'");
            $updatedRows = $stmt_update_paid->execute([$p24WebhookData['orderId'], $session_id]) ? $stmt_update_paid->rowCount() : 0;
            if ($updatedRows > 0) {
                 log_message("Zaktualizowano status zamówienia {$session_id} na 'paid'.");
                 $order['payment_status'] = 'paid';
            } else {
                 log_message("OSTRZEŻENIE: Weryfikacja P24 pomyślna, ale nie zaktualizowano statusu 'pending' na 'paid' dla sessionId: {$session_id}.");
            }
        } catch (\PDOException $e) {
            error_log("DB Error updating order to paid for {$session_id}: " . $e->getMessage());
        }
    } else {
        log_message("Informacja: Zamówienie {$session_id} miało już status {$order['payment_status']}.");
    }

    $existing_shipment_id = $order['inpost_shipment_id'];
    $resend_only = $existing_shipment_id && $order['repair_status'] == 'label_generated';

    if ($resend_only) {
        log_message("Informacja: Etykieta InPost dla sessionId {$session_id} już istnieje (ID: {$existing_shipment_id}). Ponawiam wysyłkę e-maila do klienta.");

        // Oddaj odpowiedź P24 zanim wyślemy maila (mail może wisieć minutami).
        finish_webhook_response('OK');

        $trackingNumber = $order['inpost_tracking_number'] ?? 'Oczekuje na nadanie';
        $shipmentId = $existing_shipment_id;
        $labelDownloadPageLink = "https://butolog.pl/zamowienie/download_page.php?shipment_id=" . $shipmentId;
        $customerEmail = $order['sender_email'];
        $customerName = $order['sender_name'];
        $amountPaid = number_format($p24WebhookData['amount'] / 100, 2, ',', '');
        $currency = $p24WebhookData['currency'];
        $targetLocker = $receiver_locker;

        $returnLockerId_cleaned = str_replace("PL", "", $order['return_locker_id'] ?? '');
        $returnLockerAddress = "";
        if (!empty($order['return_locker_street'])) { $returnLockerAddress .= $order['return_locker_street'] . ", "; }
        if (!empty($order['return_locker_postcode'])) { $returnLockerAddress .= $order['return_locker_postcode'] . " "; }
        if (!empty($order['return_locker_city'])) { $returnLockerAddress .= $order['return_locker_city']; }
        $returnLockerAddress = trim(trim($returnLockerAddress), ",");

        $trackingLink = '';
        if ($trackingNumber && $trackingNumber !== 'Oczekuje na nadanie') {
            $trackingLink = "https://inpost.pl/sledzenie-przesylek?number=" . urlencode($trackingNumber);
        }

        $subject = "[Ponowne wysłanie] Potwierdzenie opłaty i etykieta nadawcza {$email_from_name} (Zam: {$session_id})";

        $message_html_customer  = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>";
        $message_html_customer .= "<p style='font-size: 1.1em;'>Witaj {$customerName},</p>";
        $message_html_customer .= "<p>Przesyłamy ponownie potwierdzenie opłaty i etykietę nadawczą dla zamówienia <strong>{$session_id}</strong>.</p>";
        $message_html_customer .= "<p style='margin-top: 20px; padding: 10px; background-color: #f4f4f4; border-radius: 5px;'>";
        $message_html_customer .= "<strong>Status płatności:</strong> OPŁACONE<br>";
        $message_html_customer .= "<strong>Kwota:</strong> {$amountPaid} {$currency}";
        $message_html_customer .= "</p>";
        $message_html_customer .= "<h3 style='color: #5d4037; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>ETYKIETA NADAWCZA INPOST</h3>";
        $message_html_customer .= "<p style='margin-top: 15px;'><strong>Strona do pobrania etykiety (PDF):</strong><br>";
        $message_html_customer .= "<a href='{$labelDownloadPageLink}' target='_blank' style='color: #007bff; text-decoration: none;'>{$labelDownloadPageLink}</a></p>";
        if ($trackingLink) { $message_html_customer .= "<p style='margin-top: 15px;'><strong>Numer przesyłki (śledzenia) do nas:</strong><br><a href='{$trackingLink}' target='_blank' style='color: #007bff; text-decoration: none;'>{$trackingNumber} (Kliknij, aby śledzić)</a></p>"; }
        else { $message_html_customer .= "<p style='margin-top: 15px;'><strong>Numer przesyłki (śledzenia) do nas:</strong> {$trackingNumber}</p>"; }
        $message_html_customer .= "<p style='font-size: 0.9em; color: #555;'>Prosimy o wydrukowanie etykiety, naklejenie jej na paczkę i nadanie w dowolnym paczomacie lub PaczkoPunkcie. Została ona zaadresowana do naszego paczkomatu: {$targetLocker}.</p>";
        $message_html_customer .= "<h3 style='color: #5d4037; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>DANE ZWROTNE</h3>";
        $message_html_customer .= "<p>Po naprawie odeślemy buty do wskazanego przez Ciebie paczkomatu:</p>";
        $message_html_customer .= "<p style='margin-top: 15px; padding: 10px; background-color: #f9f9f9; border-radius: 5px;'>";
        $message_html_customer .= "<strong>Paczkomat:</strong> {$returnLockerId_cleaned}<br>";
        if (!empty($returnLockerAddress)) { $message_html_customer .= "<strong>Adres:</strong> {$returnLockerAddress}"; }
        $message_html_customer .= "</p>";
        $message_html_customer .= "<p style='margin-top: 30px;'>Pozdrawiamy,<br>Zespół {$email_from_name}</p>";
        $message_html_customer .= "</body></html>";

        if (send_email_html($customerEmail, $subject, $message_html_customer, $email_from_name, $email_from_address)) {
            log_message("Pomyślnie wysłano ponownie e-mail (HTML) dla sessionId: {$session_id} na adres: " . $customerEmail);
        } else {
            log_message("BŁĄD: send_email_html() nie powiodło się przy ponownym wysłaniu dla sessionId: {$session_id}.");
        }

        exit;
    } else {
        // --- Generowanie Standardowej Etykiety InPost ---
        log_message("Rozpoczynanie generowania etykiety InPost dla sessionId: {$session_id}");

        if (empty($order['sender_email'])) {
             log_message("BŁĄD KRYTYCZNY: Brak danych klienta w zamówieniu {$session_id}.");
             try { $pdo->prepare("UPDATE orders SET repair_status = 'cancelled' WHERE session_id = ?")->execute([$session_id]); } catch (\PDOException $e) { error_log("DB Error: ".$e->getMessage()); }
             http_response_code(200); exit("OK - Missing Customer Data");
        }
        log_message("Pomyślnie pobrano dane klienta z bazy dla sessionId: {$session_id}");

        $sender_name_inpost = !empty($sender_name_override) ? $sender_name_override : $order['sender_name'];
        $sender_company_name_inpost = $sender_name_inpost;
        $parcel_template = ($order['parcel_size'] === 'large') ? 'large' : 'medium';
        log_message("Wybrany rozmiar paczki (z bazy): {$order['parcel_size']}, Używany szablon InPost: {$parcel_template}");

        $inpostPayload = [
            'receiver' => ['name' => $receiver_name, 'email' => $receiver_email, 'phone' => $receiver_phone],
            'sender' => [
                'name' => $sender_name_inpost,
                'company_name' => $sender_company_name_inpost,
                'email' => $order['sender_email'],
                'phone' => $order['sender_phone']
            ],
            'parcels' => [['template' => $parcel_template]],
            'service' => 'inpost_locker_standard',
            'reference' => $session_id,
            'comments' => 'Naprawa obuwia (Zam: ' . $session_id . ')',
            'additional_services' => [], // Upewnij się, że 'labelless' jest usunięte
            'sending_method' => 'any_point',
            'custom_attributes' => [
                'target_point' => $receiver_locker,
                'sending_method' => 'any_point'
            ]
        ];
        log_message("Wysyłanie żądania utworzenia przesyłki InPost (standard): " . json_encode($inpostPayload));

        $inpostCreateResult = call_inpost_api_post($inpost_createShipmentUrl, $inpost_token, $inpostPayload);
        log_message("Odpowiedź InPost (tworzenie standard) (HTTP {$inpostCreateResult['http_code']}): " . $inpostCreateResult['response']);
        $inpostCreateResponse = $inpostCreateResult['data'];

        if ($inpostCreateResult['http_code'] == 201 && isset($inpostCreateResponse['id'])) {
            $shipmentId = $inpostCreateResponse['id'];
            $trackingNumber = $inpostCreateResponse['tracking_number'] ?? null;
            $targetLocker = $inpostCreateResponse['custom_attributes']['target_point'] ?? ($inpostCreateResponse['target_point'] ?? $receiver_locker);
            
            if (empty($trackingNumber) && isset($inpostCreateResponse['parcels'][0]['tracking_number'])) {
                $trackingNumber = $inpostCreateResponse['parcels'][0]['tracking_number'];
                log_message("Pobrano numer śledzenia (z 'parcels'): {$trackingNumber}");
            }

            log_message("Sukces InPost! Utworzono przesyłkę standard. ID: {$shipmentId}, Tracking: {$trackingNumber}, Paczkomat: {$targetLocker}");

            if (empty($trackingNumber)) {
                log_message("Numer śledzenia pusty/null. Czekam 5 sekund...");
                sleep(5);
                $inpostGetShipmentUrl = $inpost_getShipmentBaseUrl . $shipmentId;
                $inpostGetResult = call_inpost_api_get($inpostGetShipmentUrl, $inpost_token);
                log_message("Odpowiedź InPost (GET /shipments/{$shipmentId}): (HTTP {$inpostGetResult['http_code']}): " . $inpostGetResult['response']);
                if ($inpostGetResult['http_code'] == 200 && isset($inpostGetResult['data'])) {
                    $trackingNumber = $inpostGetResult['data']['tracking_number'] ?? ($inpostGetResult['data']['parcels'][0]['tracking_number'] ?? 'Oczekuje na nadanie');
                    log_message("Pobrano zaktualizowany numer śledzenia: {$trackingNumber}");
                } else {
                    log_message("Nie udało się pobrać numeru śledzenia.");
                    $trackingNumber = 'Oczekuje na nadanie';
                }
            }

            $trackingNumberForDb = ($trackingNumber == 'Oczekuje na nadanie' || empty($trackingNumber)) ? null : $trackingNumber;
            try {
                $stmt_update_inpost = $pdo->prepare("UPDATE orders SET inpost_shipment_id = ?, inpost_tracking_number = ?, repair_status = 'label_generated', updated_at = NOW() WHERE session_id = ?");
                $stmt_update_inpost->execute([$shipmentId, $trackingNumberForDb, $session_id]);
                 log_message("Zaktualizowano zamówienie {$session_id} o dane InPost.");
            } catch (\PDOException $e) { error_log("DB Error updating order with InPost data for {$session_id}: " . $e->getMessage()); }

            // Oddaj odpowiedź P24 zanim wyślemy maile — SMTP/mail() może wisieć
            // i blokować webhook (P24 by ponawiał), a status InPost mamy już zapisany.
            finish_webhook_response('OK');

            // --- 1. WYSYŁKA E-MAILA DO KLIENTA ---
            $labelDownloadPageLink = "https://butolog.pl/zamowienie/download_page.php?shipment_id=" . $shipmentId;
            $customerEmail = $order['sender_email'];
            $customerName = $order['sender_name'];
            $amountPaid = number_format($p24WebhookData['amount'] / 100, 2, ',', '');
            $currency = $p24WebhookData['currency'];
            
            $trackingLink = '';
            if ($trackingNumberForDb) {
                $trackingLink = "https://inpost.pl/sledzenie-przesylek?number=" . urlencode($trackingNumberForDb);
            }
            
            $returnLockerId_cleaned = str_replace("PL", "", $order['return_locker_id']);
            $returnLockerAddress = "";
            if (!empty($order['return_locker_street'])) { $returnLockerAddress .= $order['return_locker_street'] . ", "; }
            if (!empty($order['return_locker_postcode'])) { $returnLockerAddress .= $order['return_locker_postcode'] . " "; }
            if (!empty($order['return_locker_city'])) { $returnLockerAddress .= $order['return_locker_city']; }
            $returnLockerAddress = trim(trim($returnLockerAddress), ", ");

            $subject_customer = "Potwierdzenie opłaty i etykieta nadawcza {$email_from_name} (Zam: {$session_id})";
            
            $message_html_customer = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>";
            $message_html_customer .= "<p style='font-size: 1.1em;'>Witaj {$customerName},</p>";
            $message_html_customer .= "<p>Dziękujemy za opłacenie usługi naprawy (Zamówienie: <strong>{$session_id}</strong> w {$email_from_name}.)</p>";
            $message_html_customer .= "<p style='margin-top: 20px; padding: 10px; background-color: #f4f4f4; border-radius: 5px;'>";
            $message_html_customer .= "<strong>Status płatności:</strong> OPŁACONE<br>";
            $message_html_customer .= "<strong>Kwota:</strong> {$amountPaid} {$currency}";
            $message_html_customer .= "</p>";
            $message_html_customer .= "<h3 style='color: #5d4037; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>ETYKIETA NADAWCZA INPOST</h3>";
            $message_html_customer .= "<p>Wygenerowaliśmy dla Ciebie etykietę, dzięki której możesz wysłać do nas swoje buty.</p>";
            $message_html_customer .= "<p style='margin-top: 15px;'><strong>Strona do pobrania etykiety (PDF):</strong><br>";
            $message_html_customer .= "<a href='{$labelDownloadPageLink}' target='_blank' style='color: #007bff; text-decoration: none;'>{$labelDownloadPageLink}</a></p>";
            if ($trackingLink) { $message_html_customer .= "<p style='margin-top: 15px;'><strong>Numer przesyłki (śledzenia) do nas:</strong><br><a href='{$trackingLink}' target='_blank' style='color: #007bff; text-decoration: none;'>{$trackingNumber} (Kliknij, aby śledzić)</a></p>"; }
            else { $message_html_customer .= "<p style='margin-top: 15px;'><strong>Numer przesyłki (śledzenia) do nas:</strong> {$trackingNumber}</p>"; }
            $message_html_customer .= "<p style='font-size: 0.9em; color: #555;'>Prosimy o wydrukowanie etykiety, naklejenie jej na paczkę i nadanie w dowolnym paczomacie lub PaczkoPunkcie. Została ona zaadresowana do naszego serwisu napraw.</p>";
            $message_html_customer .= "<h3 style='color: #5d4037; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>DANE ZWROTNE</h3>";
            $message_html_customer .= "<p>Po naprawie odeślemy buty do wskazanego przez Ciebie paczkomatu:</p>";
            $message_html_customer .= "<p style='margin-top: 15px; padding: 10px; background-color: #f9f9f9; border-radius: 5px;'>";
            $message_html_customer .= "<strong>Paczkomat:</strong> {$returnLockerId_cleaned}<br>";
            if (!empty($returnLockerAddress)) { $message_html_customer .= "<strong>Adres:</strong> {$returnLockerAddress}"; }
            $message_html_customer .= "</p>";
            $message_html_customer .= "<p style='margin-top: 30px;'>Pozdrawiamy,<br>Zespół {$email_from_name}</p>";
            $message_html_customer .= "</body></html>";

            if (send_email_html($customerEmail, $subject_customer, $message_html_customer, $email_from_name, $email_from_address)) {
                log_message("Pomyślnie wysłano e-mail (HTML) do KLIENTA dla sessionId: {$session_id} na adres: " . $customerEmail);
            } else {
                log_message("BŁĄD: send_email_html() nie powiodło się przy wysyłaniu do KLIENTA dla sessionId: {$session_id}.");
                error_log("Mail Error: Failed to send email to CUSTOMER {$customerEmail} for session {$session_id}");
            }
            
            // === NOWA SEKCJA: 2. WYSYŁKA E-MAILA DO ADMINA/SERWISU ===
            // (Ta sekcja była w poprzedniej odpowiedzi, ale upewniam się, że jest w tej kompletnej wersji)
            log_message("Próba wysłania powiadomienia do admina ({$receiver_email}) dla sessionId: {$session_id}");
            
            $subject_admin = "NOWE OPŁACONE ZAMÓWIENIE: {$session_id} (Klient: {$order['sender_name']})";
            
            $message_html_admin = "<html><body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>";
            $message_html_admin .= "<h2 style='color: #5d4037;'>Otrzymano nowe opłacone zamówienie!</h2>";
            $message_html_admin .= "<p>Płatność dla zamówienia <strong>{$session_id}</strong> została pomyślnie zweryfikowana. Etykieta InPost została wygenerowana i wysłana do klienta.</p>";
            $message_html_admin .= "<h3 style='border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>Dane Klienta (Nadawcy)</h3>";
            $message_html_admin .= "<p style='margin-top: 15px; padding: 10px; background-color: #f9f9f9; border-radius: 5px;'>";
            $message_html_admin .= "<strong>Imię i nazwisko:</strong> " . htmlspecialchars($order['sender_name']) . "<br>";
            $message_html_admin .= "<strong>E-mail:</strong> " . htmlspecialchars($order['sender_email']) . "<br>";
            $message_html_admin .= "<strong>Telefon:</strong> " . htmlspecialchars($order['sender_phone']);
            $message_html_admin .= "</p>";

            $message_html_admin .= "<h3 style='border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>Paczkomat zwrotny klienta</h3>";
            $message_html_admin .= "<p style='margin-top: 15px; padding: 10px; background-color: #f9f9f9; border-radius: 5px;'>";
            $message_html_admin .= "<strong>Paczkomat:</strong> {$returnLockerId_cleaned}<br>";
            if (!empty($returnLockerAddress)) {
                $message_html_admin .= "<strong>Adres:</strong> {$returnLockerAddress}";
            }
            $message_html_admin .= "</p>";

            $message_html_admin .= "<h3 style='border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px;'>Szczegóły Formularza</h3>";
            $message_html_admin .= "<p><strong>UUID Formularza:</strong> " . htmlspecialchars($order['form_uuid']) . "</p>"; // Używamy $order['form_uuid']
            
            $message_html_admin .= "<p style='margin-top: 30px; font-size: 0.9em; color: #777;'>To jest automatyczne powiadomienie systemowe.</p>";
            $message_html_admin .= "</body></html>";

            if (send_email_html($receiver_email, $subject_admin, $message_html_admin, $email_from_name . ' (System)', $email_from_address, $order['sender_email'])) {
                log_message("Pomyślnie wysłano powiadomienie do ADMINA ({$receiver_email}) dla sessionId: {$session_id}");
            } else {
                log_message("BŁĄD: send_email_html() nie powiodło się przy wysyłaniu do ADMINA dla sessionId: {$session_id}.");
                error_log("Mail Error: Failed to send email to ADMIN {$receiver_email} for session {$session_id}");
            }
            // =======================================================
            
        } else {
            // Obsługa błędu InPost
            $errorResponse = $inpostCreateResult['response'] ?? 'Brak odpowiedzi InPost';
            log_message("BŁĄD KRYTYCZNY INPOST dla sessionId: {$session_id}. Nie udało się wygenerować etykiety. Odpowiedź (HTTP {$inpostCreateResult['http_code']}): " . $errorResponse);
            try { 
                $stmt_update_error = $pdo->prepare("UPDATE orders SET repair_status = 'cancelled', payment_status = 'paid', updated_at = NOW() WHERE session_id = ?");
                $stmt_update_error->execute([$session_id]);
                log_message("Zaktualizowano status zamówienia {$session_id} na 'cancelled' po błędzie InPost.");
            } catch (\PDOException $e) { error_log("DB Error updating order status to cancelled for {$session_id}: " . $e->getMessage()); }
        }
    } // Koniec bloku 'if (!$existing_shipment_id)'

} else {
    // Obsługa błędu weryfikacji P24
    $errorResponseP24 = $p24VerifyResult['response'] ?? 'Brak odpowiedzi P24';
    log_message("Błąd weryfikacji P24 dla sessionId: {$session_id}. Odpowiedź P24 (HTTP {$p24VerifyResult['http_code']}): " . $errorResponseP24);
    try {
         $stmt_update_failed = $pdo->prepare("UPDATE orders SET payment_status = 'failed', updated_at = NOW() WHERE session_id = ? AND payment_status = 'pending'");
         $updatedRowsFailed = $stmt_update_failed->execute([$session_id]);
          if ($updatedRowsFailed > 0) {
              log_message("Zaktualizowano status zamówienia {$session_id} na 'failed'.");
          }
    } catch (\PDOException $e) {
         error_log("DB Error updating order status to failed for {$session_id}: " . $e->getMessage());
    }
}

// === KROK 4: Zawsze odpowiedz P24 kodem 200 OK (jeśli nagłówki nie zostały już wysłane przez finish_webhook_response) ===
if (!headers_sent()) {
    http_response_code(200);
    echo "OK";
}
exit;

?>

