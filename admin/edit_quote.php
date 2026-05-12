<?php
// /zamowienie/admin/edit_quote.php

require_once 'includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$page_title = "Edycja Wyceny";
include 'includes/header.php';

// Pobierz UUID wyceny z URL
$quote_uuid = $_GET['uuid'] ?? null;
if (!$quote_uuid) {
    echo "<div class='error-message'>Nieprawidłowy identyfikator wyceny.</div>";
    include 'includes/footer.php';
    exit;
}

// Pobierz token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Zmienne na komunikaty
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- Logika aktualizacji wyceny (gdy formularz jest wysyłany) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_quote') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error_message = "Błąd CSRF Token. Odśwież stronę i spróbuj ponownie.";
    } else {
        // Pobierz dane z formularza
        $client_email = filter_input(INPUT_POST, 'client_email', FILTER_VALIDATE_EMAIL);
        $email_subject = isset($_POST['email_subject']) ? trim($_POST['email_subject']) : '';
        $email_body = $_POST['email_body'] ?? ''; // Pozwól na HTML

        if (empty($client_email) || empty($email_subject)) {
            $error_message = "Adres e-mail klienta i temat wiadomości są wymagane.";
        } else {
            try {
                // Zaktualizuj wycenę w bazie i oznacz jako 'draft' (bo została zmodyfikowana)
                $stmt = $pdo->prepare(
                    "UPDATE quotes SET client_email = ?, email_subject = ?, email_body = ?, status = 'draft', updated_at = NOW() 
                     WHERE uuid = ?"
                );
                $stmt->execute([$client_email, $email_subject, $email_body, $quote_uuid]);
                
                // Użyj $_SESSION, ponieważ zaraz będzie przekierowanie
                $_SESSION['success_message'] = "Wycena została pomyślnie zaktualizowana i oznaczona jako 'Szkic'.";
                header("Location: quotes.php"); // Wróć do listy wycen
                exit;
                
            } catch (\PDOException $e) {
                error_log("DB Error updating quote {$quote_uuid}: " . $e->getMessage());
                $error_message = "Wystąpił błąd serwera podczas aktualizacji wyceny.";
            }
        }
    }
}

// --- Pobieranie danych wyceny do wyświetlenia ---
try {
    // Pobierz dane wyceny ORAZ formularza (cenę, nazwę usługi, UUID formularza)
    $stmt_get = $pdo->prepare(
        "SELECT q.*, f.service_name, f.price, f.uuid as form_uuid 
         FROM quotes q
         LEFT JOIN forms f ON q.form_id = f.id
         WHERE q.uuid = ?"
    );
    $stmt_get->execute([$quote_uuid]);
    $quote = $stmt_get->fetch();

    if (!$quote) {
        $error_message = "Nie znaleziono wyceny o podanym UUID.";
    } else {
        // Użyj zapisanej treści lub wygeneruj domyślną
        // Jeśli był błąd POST, pokaż dane z POST. W przeciwnym razie, pokaż dane z bazy.
        $client_email_to_show = (!empty($error_message) && isset($_POST['client_email'])) ? $_POST['client_email'] : $quote['client_email'];
        $email_subject_to_show = (!empty($error_message) && isset($_POST['email_subject'])) ? $_POST['email_subject'] : ($quote['email_subject'] ?? ("Wycena naprawy obuwia - BUTOLOG"));
        $email_body_to_show = (!empty($error_message) && isset($_POST['email_body'])) ? $_POST['email_body'] : $quote['email_body'];

        // Jeśli treść e-maila jest nadal pusta (np. nowa wycena), wygeneruj domyślny szablon
        if (empty($email_body_to_show)) {
            // Pobierz domyślny szablon z bazy danych
            $email_body_to_show = get_setting('global_quote_body', $pdo);
            $email_subject_to_show = get_setting('global_quote_subject', $pdo); // Pobierz też domyślny temat
            
            // Jeśli nadal pusty (np. błąd SQL), użyj ostatecznego fallbacku
            if (empty($email_body_to_show)) {
                $email_body_to_show = "<h1>Wycena naprawy</h1><p>Usługa: {{NAZWA_USLUGI}}</p><p>Cena: {{CENA}}</p><p>Link: {{LINK_DO_PLATNOSCI}}</p>";
                $email_subject_to_show = "Wycena naprawy";
            }
        }
    }
} catch (\PDOException $e) {
    error_log("DB Error fetching quote {$quote_uuid} for edit: " . $e->getMessage());
    $error_message = "Błąd pobierania danych wyceny.";
    $quote = null; // Ustaw null, aby formularz się nie wyświetlił
}
?>

<h2><?php echo $page_title; ?> (ID: <?php echo htmlspecialchars($quote['id'] ?? 'Błąd'); ?>)</h2>
<p><a href="quotes.php" style="font-size: 0.9em;">&laquo; Wróć do listy wycen</a></p>

<?php if ($success_message): ?><div class="success-message"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
<?php if ($error_message): ?><div class="error-message"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<?php if (!empty($quote)): // Pokaż formularz tylko jeśli dane wyceny zostały pobrane ?>
<div class="email-editor-wrapper">
    <div class="email-editor-main">
        <form action="edit_quote.php?uuid=<?php echo htmlspecialchars($quote_uuid); ?>" method="POST" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="update_quote">

            <fieldset>
                <legend>Edycja Wyceny</legend>
                <div class="setting-group">
                    <label for="client_email">E-mail Klienta *</label>
                    <input type="email" id="client_email" name="client_email" value="<?php echo htmlspecialchars($client_email_to_show); ?>" required>

                    <label for="email_subject">Temat E-maila *</label>
                    <input type="text" id="email_subject" name="email_subject" value="<?php echo htmlspecialchars($email_subject_to_show); ?>" required>

                    <label for="email_body">Treść E-maila (HTML)</label>
                    <textarea id="email_body" name="email_body"><?php echo htmlspecialchars($email_body_to_show); ?></textarea>
                </div>
            </fieldset>

            <label for="send_copy" class="checkbox-label">
                <input type="checkbox" id="send_copy" name="send_copy" value="1" checked> Wyślij kopię (DW) na e-mail serwisu (<?php echo htmlspecialchars(get_setting('service_receiver_email', $pdo)); ?>)
            </label>

            <button type="submit" class="button">Zapisz jako Szkic</button>
            
            <button type="submit" formaction="handle_quote_action.php?action=send_quote&uuid=<?php echo htmlspecialchars($quote_uuid); ?>&token=<?php echo $csrf_token; ?>" 
                    onclick="return confirm('Czy na pewno chcesz ZAPISAĆ i WYSŁAĆ tę wycenę do klienta <?php echo htmlspecialchars($quote['client_email']); ?>?');"
                    class="button send-button">
                Zapisz i Wyślij
            </button>

        </form>
    </div>

    <aside class="email-editor-sidebar">
        <h4>Dostępne Zmienne</h4>
        <p>Użyj poniższych zmiennych w temacie lub treści e-maila. Zostaną one automatycznie podmienione przed wysyłką.</p>
        
        <p><code>{{EMAIL_KLIENTA}}</code><br>
        Adres e-mail klienta (z pola powyżej).</p>
        
        <p><code>{{NAZWA_USLUGI}}</code><br>
        Nazwa usługi powiązana z tą wyceną (<?php echo htmlspecialchars($quote['service_name']); ?>).</p>
        
        <p><code>{{CENA}}</code><br>
        Cena usługi (<?php echo number_format($quote['price'], 2, ',', ' '); ?> PLN).</p>

        <p><code>{{LINK_DO_PLATNOSCI}}</code><br>
        Unikalny link do formularza płatności (z `form.php?uuid=...` powiązanego formularza).</p>
        
        <h4>Opis usługi</h4>
        <p style="font-size: 0.9em; color: #555;">W skład usługi wchodzi: naprawa, wysyłka do serwisu, odesłanie gotowej naprawy, opłata operacyjna.</p>

    </aside>
</div>
<?php elseif (empty($error_message)): // Jeśli $quote jest puste, ale nie było błędu ?>
    <div class="error-message">Nie można załadować danych wyceny.</div>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>