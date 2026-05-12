<?php
// /zamowienie/admin/handle_quote_action.php

// Wymagaj sprawdzenia autoryzacji
require_once 'includes/auth_check.php';
// Wczytaj połączenie z bazą i funkcje pomocnicze
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Upewnij się, że PDO jest dostępne globalnie (z db.php)
global $pdo;

// Sprawdź, czy akcja została określona (przez POST lub GET)
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$request_method = $_SERVER['REQUEST_METHOD'];

// Pobierz token CSRF (z POST lub GET)
$token = $_POST['csrf_token'] ?? $_GET['token'] ?? null;

// Weryfikacja tokenu CSRF
if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    $_SESSION['error_message'] = "Nieprawidłowy token bezpieczeństwa. Akcja anulowana.";
    header("Location: quotes.php"); // Wróć do listy wycen
    exit;
}

// --- Obsługa różnych akcji ---
try {
    switch ($action) {

        // --- AKCJA: Utwórz nową wycenę (ze strony quotes.php) ---
        case 'create_quote':
            if ($request_method !== 'POST') {
                throw new Exception("Nieprawidłowa metoda żądania.");
            }

            $form_uuid = isset($_POST['form_uuid']) ? trim($_POST['form_uuid']) : '';
            $client_email = filter_input(INPUT_POST, 'client_email', FILTER_VALIDATE_EMAIL);

            if (empty($form_uuid) || !$client_email) {
                throw new Exception("UUID Formularza oraz poprawny e-mail klienta są wymagane.");
            }

            // Znajdź formularz w bazie, aby pobrać jego ID
            $stmt_form = $pdo->prepare("SELECT id FROM forms WHERE uuid = ? AND is_active = TRUE");
            $stmt_form->execute([$form_uuid]);
            $form_id = $stmt_form->fetchColumn();

            if (!$form_id) {
                throw new Exception("Formularz o podanym UUID nie został znaleziony lub jest nieaktywny.");
            }

            // Wygeneruj unikalny UUID dla nowej wyceny
            $quote_uuid = generate_uuid();

            // Wstaw nową wycenę do bazy ze statusem 'draft'
            // Treść i temat e-maila pozostają puste (NULL), aby edytor wczytał domyślny szablon
            $stmt_insert = $pdo->prepare(
                "INSERT INTO quotes (uuid, form_id, client_email, status, created_at, updated_at)
                 VALUES (?, ?, ?, 'draft', NOW(), NOW())"
            );
            $stmt_insert->execute([$quote_uuid, $form_id, $client_email]);

            $_SESSION['success_message'] = "Nowy szkic wyceny dla '{$client_email}' został utworzony. Możesz go teraz edytować i wysłać.";
            header("Location: quotes.php");
            exit;

        // --- AKCJA: Usuń wycenę (z listy quotes.php) ---
        case 'delete_quote':
            if ($request_method !== 'GET') {
                throw new Exception("Nieprawidłowa metoda żądania.");
            }

            $quote_uuid = $_GET['uuid'] ?? null;
            if (!$quote_uuid) {
                throw new Exception("Brak UUID wyceny do usunięcia.");
            }

            $stmt_delete = $pdo->prepare("DELETE FROM quotes WHERE uuid = ?");
            $deleted = $stmt_delete->execute([$quote_uuid]);

            if ($deleted && $stmt_delete->rowCount() > 0) {
                $_SESSION['success_message'] = "Wycena została pomyślnie usunięta.";
            } else {
                $_SESSION['error_message'] = "Nie udało się usunąć wyceny (nie znaleziono?).";
            }
            header("Location: quotes.php");
            exit;

        // --- AKCJA: Wyślij wycenę (z listy quotes.php lub edytora edit_quote.php) ---
        case 'send_quote':
            $quote_uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? null; // Pobierz UUID z POST (formularz) lub GET (link)
            if (!$quote_uuid) {
                throw new Exception("Brak UUID wyceny do wysłania.");
            }

            // === POPRAWKA: Domyślnie wysyłaj kopię ===
            $send_copy = true; // Zawsze wysyłaj kopię...

            // Sprawdź, czy dane pochodzą z formularza edycji (POST)
            if ($request_method === 'POST') {
                // Dane pochodzą z formularza 'edit_quote.php' (przycisk "Zapisz i Wyślij")
                log_message("Wysyłanie wyceny z formularza edycji (POST) dla UUID: {$quote_uuid}");

                // Najpierw zapisz zmiany
                $client_email = filter_input(INPUT_POST, 'client_email', FILTER_VALIDATE_EMAIL);
                $email_subject = isset($_POST['email_subject']) ? trim($_POST['email_subject']) : '';
                $email_body = $_POST['email_body'] ?? '';

                // === POPRAWKA: ...chyba że checkbox jest ODZNACZONY ===
                // Jeśli formularz jest wysłany (POST) i checkboxa NIE MA w danych (bo był odznaczony),
                // to ustaw $send_copy na false.
                if (!isset($_POST['send_copy'])) {
                    $send_copy = false;
                }
                // (Jeśli był zaznaczony (isset), $send_copy pozostanie true)
                // =======================================================

                if (empty($client_email) || empty($email_subject) || empty($email_body)) {
                    $_SESSION['error_message'] = "E-mail, temat i treść wiadomości nie mogą być puste, aby wysłać.";
                    header("Location: edit_quote.php?uuid=" . $quote_uuid);
                    exit;
                }

                $stmt_update = $pdo->prepare(
                    "UPDATE quotes SET client_email = ?, email_subject = ?, email_body = ?
                     WHERE uuid = ?"
                );
                $stmt_update->execute([$client_email, $email_subject, $email_body, $quote_uuid]);
                log_message("Zaktualizowano treść wyceny {$quote_uuid} przed wysłaniem.");

            } else {
                // Dane pochodzą z linku "Wyślij" na liście 'quotes.php' (GET)
                log_message("Wysyłanie wyceny z listy (GET) dla UUID: {$quote_uuid}");
                // $send_copy pozostaje domyślnie true (zgodnie z Poprawką 1)
            }

            // Pobierz pełne dane wyceny (zaktualizowane lub z bazy)
            $stmt_get = $pdo->prepare(
                "SELECT q.*, f.service_name, f.price, f.uuid as form_uuid
                 FROM quotes q
                 LEFT JOIN forms f ON q.form_id = f.id
                 WHERE q.uuid = ?"
            );
            $stmt_get->execute([$quote_uuid]);
            $quote = $stmt_get->fetch();

            if (!$quote) {
                throw new Exception("Nie znaleziono wyceny do wysłania.");
            }

            // Upewnij się, że treść i temat nie są puste
            $client_email = $quote['client_email'];
            $email_subject = $quote['email_subject'];
            $email_body_html = $quote['email_body'];

            // Jeśli treść jest pusta, załaduj domyślny szablon globalny
            if (empty($email_body_html)) {
                log_message("Treść e-maila pusta, ładowanie szablonu globalnego.");
                $email_body_html = get_setting('global_quote_body', $pdo, false); // false = nie traktuj jako boolean
            }
            // Jeśli temat jest pusty, załaduj domyślny
            if (empty($email_subject)) {
                 $email_subject = get_setting('global_quote_subject', $pdo, false);
            }

            if (empty($email_subject)) $email_subject = get_default_quote_subject();
            if (empty($email_body_html)) $email_body_html = get_default_quote_body();

            // --- Zastępowanie zmiennych w treści i temacie ---
            $link_do_platnosci = "https://butolog.pl/zamowienie/form.php?uuid=" . htmlspecialchars($quote['form_uuid']);

            $vars_to_replace = [
                '{{EMAIL_KLIENTA}}' => $client_email,
                '{{NAZWA_USLUGI}}' => htmlspecialchars($quote['service_name']),
                '{{CENA}}' => number_format($quote['price'], 2, ',', ' ') . " PLN",
                '{{LINK_DO_PLATNOSCI}}' => $link_do_platnosci
            ];

            $final_subject = $email_subject;
            $final_body = $email_body_html;
            foreach ($vars_to_replace as $key => $value) {
                $final_subject = str_replace($key, $value, $final_subject);
                $final_body = str_replace($key, $value, $final_body);
            }

            // --- Wysyłka E-maila ---
            $email_from_address = get_setting('email_from_address', $pdo, false);
            $email_from_name = get_setting('email_from_name', $pdo, false);

            if (empty($email_from_address) || empty($email_from_name)) {
                throw new Exception("Brak skonfigurowanego adresu 'Od' lub nazwy nadawcy w Ustawieniach.");
            }

            $headers = "From: {$email_from_name} <{$email_from_address}>\r\n";
            $headers .= "Reply-To: {$email_from_address}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

            // === POPRAWKA 3: Dodaj kopię (Cc), jeśli $send_copy jest true ===
            if ($send_copy) {
                $service_receiver_email = get_setting('service_receiver_email', $pdo, false);
                if (!empty($service_receiver_email)) {
                    $headers .= "Cc: {$service_receiver_email}\r\n"; // Użyj Cc (DW)
                    log_message("Wysyłanie kopii Cc do: {$service_receiver_email}");
                } else {
                     log_message("Ostrzeżenie: Chciano wysłać kopię (domyślnie lub z POST), ale 'service_receiver_email' nie jest ustawiony.");
                }
            } else {
                 log_message("Wysyłanie bez kopii (checkbox był odznaczony w formularzu POST).");
            }
            $headers .= "X-Mailer: PHP/" . phpversion();
            // =================================================================

            if (mail($client_email, $final_subject, $final_body, $headers)) {
                // Sukces wysyłki - zaktualizuj status w bazie
                $stmt_sent = $pdo->prepare("UPDATE quotes SET status = 'sent', updated_at = NOW() WHERE uuid = ?");
                $stmt_sent->execute([$quote_uuid]);
                $_SESSION['success_message'] = "Wycena została pomyślnie wysłana do " . htmlspecialchars($client_email);
            } else {
                throw new Exception("Funkcja mail() nie powiodła się. E-mail nie został wysłany. Sprawdź konfigurację serwera pocztowego.");
            }

            header("Location: quotes.php");
            exit;

        // --- Domyślna akcja (błąd) ---
        default:
            throw new Exception("Nieznana lub nieobsługiwana akcja.");
    }

} catch (\PDOException | \Exception $e) {
    // Globalna obsługa błędów
    error_log("Błąd w handle_quote_action.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Wystąpił błąd: " . $e->getMessage();
    // Wróć do listy wycen
    header("Location: quotes.php");
    exit;
}
?>
