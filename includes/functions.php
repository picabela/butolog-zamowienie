<?php
// /zamowienie/includes/functions.php
require_once __DIR__ . '/db.php'; // Upewnij się, że db.php jest załadowany
require_once __DIR__ . '/../vendor/autoload.php'; // PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Generuje unikalny identyfikator UUID v4.
 * @return string UUID v4.
 */
function generate_uuid() {
    try {
         $data = random_bytes(16);
         $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // v4
         $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // RFC 4122
         return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    } catch (Exception $e) { // Fallback
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

/**
 * Loguje wiadomość do pliku.
 * @param string $message Wiadomość.
 */
function log_message($message) {
    $log_file = __DIR__ . '/../status_log.txt';
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND | LOCK_EX);
}


/**
 * Domyślny temat wiadomości z wyceną.
 */
function get_default_quote_subject(): string
{
    return 'Wycena naprawy';
}

/**
 * Domyślny szablon HTML wiadomości z wyceną.
 */
function get_default_quote_body(): string
{
    return '<h1>Wycena naprawy</h1><p>Usługa: {{NAZWA_USLUGI}}</p><p>Cena: {{CENA}}</p><p>Link: {{LINK_DO_PLATNOSCI}}</p>';
}

/**
 * Pobiera pojedyncze ustawienie z bazy danych.
 * @param string $key Klucz ustawienia.
 * @param PDO $pdo_conn Połączenie PDO.
 * @param bool $treat_as_boolean Czy traktować 'true'/'false' jako boolean?
 * @return string|bool|null Wartość ustawienia lub null, jeśli nie znaleziono lub błąd.
 */
function get_setting(string $key, PDO $pdo_conn, bool $treat_as_boolean = true)
{
    try {
        $stmt = $pdo_conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();

        if ($result === false) { // Nie znaleziono klucza
            return null;
        }

        // === POPRAWKA: Zawsze usuwaj białe znaki! ===
        $trimmed_result = trim($result);
        // ============================================

        // Konwersja na boolean dla kluczy _enabled
        if ($treat_as_boolean && str_ends_with($key, '_enabled')) {
            return strtolower($trimmed_result) === 'true';
        }

        return $trimmed_result; // Zwróć wartość pozbawioną spacji

    } catch (\PDOException $e) {
        error_log("DB Error fetching setting '{$key}': " . $e->getMessage());
        return null; // Zwróć null w przypadku błędu
    }
}


/**
 * Pobiera *aktywne* ustawienie (sandbox lub production) na podstawie trybu.
 * @param string $base_key Klucz bazowy (np. 'p24_pos_id', 'geowidget_token').
 * @param PDO $pdo_conn Połączenie PDO.
 * @return string|null Wartość ustawienia lub null.
 */
function get_active_setting(string $base_key, PDO $pdo_conn): ?string
{
    $sandbox_enabled = false;
    $is_mode_dependent = false;

    if (str_starts_with($base_key, 'p24_')) {
        $sandbox_enabled = get_setting('p24_sandbox_enabled', $pdo_conn);
        $is_mode_dependent = true;
    } elseif (str_starts_with($base_key, 'inpost_') || $base_key === 'geowidget_token' || $base_key === 'service_receiver_locker') {
        $sandbox_enabled = get_setting('inpost_sandbox_enabled', $pdo_conn);
        $is_mode_dependent = true;
    } elseif (str_starts_with($base_key, 'smtp_')) {
        $sandbox_enabled = get_setting('p24_sandbox_enabled', $pdo_conn);
        $is_mode_dependent = true;
    }

    if (!$is_mode_dependent) {
        return get_setting($base_key, $pdo_conn, false);
    }

    if ($sandbox_enabled === null) {
        log_message("Ostrzeżenie: Brak ustawienia trybu sandbox dla {$base_key} w bazie. Przyjęto tryb produkcyjny.");
        $sandbox_enabled = false;
    }

    $prefix = $sandbox_enabled ? 'sandbox_' : 'production_';

     if($base_key === 'geowidget_token'){
         $active_key = $prefix . 'geowidget_token';
     } else if ($base_key === 'service_receiver_locker') {
          $active_key = $prefix . 'service_receiver_locker';
     } else if (str_starts_with($base_key, 'smtp_')) {
          $key_without_prefix = preg_replace('/^(sandbox_|production_|smtp_)/', '', $base_key);
          $active_key = $prefix . 'smtp_' . $key_without_prefix;
     } else if (str_starts_with($base_key, 'inpost_')) {
          $key_without_prefix = preg_replace('/^(sandbox_|production_|inpost_)/', '', $base_key);
          $active_key = $prefix . 'inpost_' . $key_without_prefix;
     } else { // Dla kluczy p24_
          $key_without_prefix = preg_replace('/^(sandbox_|production_|p24_)/', '', $base_key);
          $active_key = $prefix . 'p24_' . $key_without_prefix;
     }

    // Pobierz wartość odpowiedniego klucza
    return get_setting($active_key, $pdo_conn, false);
}

/**
 * Pobiera *wszystkie* ustawienia jako tablicę asocjacyjną.
 */
function get_all_settings(PDO $pdo_conn): array
{
    $settings = [];
    try {
        $stmt = $pdo_conn->query("SELECT setting_key, setting_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (\PDOException $e) {
        error_log("DB Error fetching all settings: " . $e->getMessage());
    }
    return $settings;
}

/**
 * Pomocnicza funkcja do pobierania wartości ustawienia z tablicy (dla formularza).
 */
function get_current_setting_value(string $key, array $settings_array, string $default = ''): string
{
    return isset($settings_array[$key]) ? htmlspecialchars($settings_array[$key]) : $default;
}

/**
 * Pomocnicza funkcja do sprawdzania checkboxa trybu sandbox.
 */
function is_sandbox_enabled(string $key, array $settings_array): bool
{
    global $pdo;
    if (!$pdo) {
         error_log("Błąd w is_sandbox_enabled: Brak globalnego obiektu PDO.");
         return false;
    }
    $value_from_db = get_setting($key, $pdo);
    return $value_from_db === true;
}


/**
 * Wysyła wiadomość HTML przez PHPMailer.
 * Jeśli skonfigurowano SMTP w panelu admina – używa SMTP (z twardym timeoutem).
 * W przeciwnym razie – używa wbudowanej funkcji mail() (przez PHPMailer, z lepszymi nagłówkami).
 *
 * @param string $to Adres odbiorcy.
 * @param string $subject Temat (zostanie zakodowany UTF-8).
 * @param string $html Treść HTML.
 * @param string $from_name Nazwa nadawcy w polu From.
 * @param string $from_address Adres nadawcy w polu From.
 * @param string $reply_to Opcjonalny Reply-To.
 * @return bool true gdy wysyłka się powiodła.
 */
function send_email_html(string $to, string $subject, string $html, string $from_name, string $from_address, string $reply_to = '', string $cc = ''): bool
{
    global $pdo;
    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->Timeout = 15;

        if ($pdo instanceof PDO) {
            $smtp_host = get_active_setting('smtp_host', $pdo);
            if (!empty($smtp_host)) {
                $smtp_port = (int) (get_active_setting('smtp_port', $pdo) ?: 587);
                $smtp_user = get_active_setting('smtp_user', $pdo);
                $smtp_pass = get_active_setting('smtp_password', $pdo);
                $smtp_enc = strtolower((string) get_active_setting('smtp_encryption', $pdo));

                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->Port = $smtp_port;
                if (!empty($smtp_user)) {
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtp_user;
                    $mail->Password = (string) $smtp_pass;
                }
                if ($smtp_enc === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($smtp_enc === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }
            }
        }

        $mail->setFrom($from_address, $from_name);
        $mail->addAddress($to);
        if (!empty($reply_to)) {
            $mail->addReplyTo($reply_to);
        }
        if (!empty($cc)) {
            $mail->addCC($cc);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</h1>', '</h2>', '</h3>'], "\n", $html)));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        log_message("BŁĄD send_email_html do {$to}: " . $mail->ErrorInfo);
        error_log("send_email_html exception: " . $e->getMessage());
        return false;
    } catch (\Throwable $e) {
        log_message("BŁĄD send_email_html do {$to} (Throwable): " . $e->getMessage());
        return false;
    }
}

/**
 * Po przyjęciu webhooka P24 oddaj odpowiedź klientowi (P24) zanim zaczniemy
 * wysyłać maile – inaczej długo trwający SMTP wisi webhook i P24 ponawia.
 */
function finish_webhook_response(string $body = 'OK'): void
{
    if (headers_sent()) {
        return;
    }
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }
}

// --- Funkcje API ---

/**
 * Wykonuje żądanie do API Przelewy24.
 */
function call_p24_api($url, $posId, $apiKey, $payload = [], $method = 'POST') {
    $ch = curl_init($url);
    $jsonData = json_encode($payload);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERPWD => $posId . ":" . $apiKey, // Basic Auth
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
    ];

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $jsonData;
    } elseif ($method === 'PUT') {
        $options[CURLOPT_CUSTOMREQUEST] = "PUT";
        $options[CURLOPT_POSTFIELDS] = $jsonData;
    }

    curl_setopt_array($ch, $options);

    // === DODANE LOGOWANIE DLA AUTORYZACJI ===
    if (function_exists('log_message')) { // Sprawdź czy funkcja log_message istnieje
         log_message("call_p24_api: Używam Basic Auth: User=[" . $posId . "] Key=[" . substr($apiKey, 0, 5) . "...(pełny klucz ma " . strlen($apiKey) . " znaków)]");
    }
    // ======================================

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($error) {
        log_message("cURL Error P24 ({$method} {$url}): [{$errno}] " . $error);
        return ['http_code' => 0, 'response' => "cURL Error: [{$errno}] " . $error, 'data' => null];
    }

    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE && $httpCode >= 200 && $httpCode < 300) {
         log_message("cURL Warning P24 ({$method} {$url}): Invalid JSON response for successful HTTP code {$httpCode}. Response: " . substr($response, 0, 200));
    }

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'data' => $responseData
    ];
}


/**
 * Wykonuje żądanie POST do API InPost.
 */
function call_inpost_api_post($url, $token, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

     if ($error) {
        log_message("cURL Error InPost (POST {$url}): [{$errno}] " . $error);
        return ['http_code' => 0, 'response' => "cURL Error: [{$errno}] " . $error, 'data' => null];
    }

    $responseData = json_decode($response, true);
     if (json_last_error() !== JSON_ERROR_NONE && $httpCode >= 200 && $httpCode < 300) {
         log_message("cURL Warning InPost (POST {$url}): Invalid JSON response... Response: " . substr($response, 0, 200));
     }

     return [
        'http_code' => $httpCode,
        'response' => $response,
        'data' => $responseData
    ];
}

/**
 * Wykonuje żądanie GET do API InPost.
 */
function call_inpost_api_get($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($error) {
        log_message("cURL Error InPost (GET {$url}): [{$errno}] " . $error);
        return ['http_code' => 0, 'response' => $error, 'data' => null];
    }

    $responseData = json_decode($response, true);
     if (json_last_error() !== JSON_ERROR_NONE && $httpCode >= 200 && $httpCode < 300) {
         log_message("cURL Warning InPost (GET {$url}): Invalid JSON response... Response: " . substr($response, 0, 200));
     }

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'data' => $responseData
    ];
}

?>
