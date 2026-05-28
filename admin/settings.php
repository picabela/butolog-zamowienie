<?php
// /zamowienie/admin/settings.php

require_once 'includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$page_title = "Ustawienia Systemu";
include 'includes/header.php';
include 'includes/admin_ui_assets.php';

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- Logika zapisywania ustawień ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error_message = "Błąd CSRF Token.";
    } else {
        // Lista *wszystkich* kluczy, które mogą być przesłane
        $settings_keys_from_form = [
            'sandbox_p24_pos_id', 'sandbox_p24_crc_key', 'sandbox_p24_api_key', 'p24_sandbox_enabled',
            'production_p24_pos_id', 'production_p24_crc_key', 'production_p24_api_key',
            'sandbox_inpost_org_id', 'sandbox_inpost_token', 'inpost_sandbox_enabled',
            'production_inpost_org_id', 'production_inpost_token',
            'sandbox_geowidget_token', 'production_geowidget_token', // <-- NOWE TOKENY
            'large_parcel_surcharge', // <-- NOWA DOPŁATA
            'service_receiver_name', 'service_receiver_email', 'service_receiver_phone',
            'sandbox_service_receiver_locker', 'production_service_receiver_locker',
            'sender_name_override',
            'email_from_address', 'email_from_name',
            'sandbox_smtp_host', 'sandbox_smtp_port', 'sandbox_smtp_user', 'sandbox_smtp_password', 'sandbox_smtp_encryption',
            'production_smtp_host', 'production_smtp_port', 'production_smtp_user', 'production_smtp_password', 'production_smtp_encryption',
            'global_quote_subject', 'global_quote_body'
        ];

        try {
            $pdo->beginTransaction();
            $stmt_exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = :key");
            $stmt_update = $pdo->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
            $stmt_insert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)");

            foreach ($settings_keys_from_form as $key) {
                if (str_ends_with($key, '_enabled')) {
                    $value = isset($_POST[$key]) ? 'true' : 'false';
                } elseif ($key === 'large_parcel_surcharge') {
                     // Walidacja dopłaty - upewnij się, że to liczba
                     $surcharge = filter_input(INPUT_POST, $key, FILTER_VALIDATE_FLOAT);
                     $value = ($surcharge !== false && $surcharge >= 0) ? number_format($surcharge, 2, '.', '') : '0.00'; // Zapisz jako string z kropką
                } elseif ($key === 'global_quote_body') {
                    $value = $_POST[$key] ?? '';
                } else {
                    $value = isset($_POST[$key]) ? trim($_POST[$key]) : '';
                }
                $stmt_exists->execute(['key' => $key]);
                if ((int) $stmt_exists->fetchColumn() > 0) {
                    $stmt_update->execute(['key' => $key, 'value' => $value]);
                } else {
                    $stmt_insert->execute(['key' => $key, 'value' => $value]);
                }
            }

            $pdo->commit();
            $_SESSION['success_message'] = "Ustawienia zostały pomyślnie zaktualizowane.";
            header("Location: settings.php");
            exit;

        } catch (\PDOException $e) {
            $pdo->rollBack();
            error_log("DB Error updating settings: " . $e->getMessage());
            $error_message = "Wystąpił błąd serwera podczas zapisywania ustawień.";
        }
    }
}

// Wygeneruj/pobierz token CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = $_SESSION['csrf_token'];

// --- Pobieranie wszystkich aktualnych ustawień ---
$current_settings = get_all_settings($pdo);
$default_quote_subject = get_default_quote_subject();
$default_quote_body = get_default_quote_body();

?>

<h2><?php echo $page_title; ?></h2>

<?php if ($success_message): ?><div class="success-message"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
<?php if ($error_message): ?><div class="error-message"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<p>Skonfiguruj klucze API oraz inne parametry aplikacji.</p>

<form action="settings.php" method="POST" class="settings-form">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="action" value="save_settings">

    <details class="settings-card">
        <summary>Ustawienia Przelewy24</summary>
        <fieldset>
            <legend>Ustawienia Przelewy24</legend>
            <details class="settings-subcard" open>
                <summary>Środowisko Sandbox (Testowe)</summary>
                <div class="setting-group">
                    <label for="sandbox_p24_pos_id">Sandbox POS ID</label>
                    <input type="text" id="sandbox_p24_pos_id" name="sandbox_p24_pos_id" value="<?php echo get_current_setting_value('sandbox_p24_pos_id', $current_settings); ?>">
                    <label for="sandbox_p24_crc_key">Sandbox Klucz CRC</label>
                    <input type="text" id="sandbox_p24_crc_key" name="sandbox_p24_crc_key" value="<?php echo get_current_setting_value('sandbox_p24_crc_key', $current_settings); ?>">
                    <label for="sandbox_p24_api_key">Sandbox Klucz API (do raportów)</label>
                    <input type="text" id="sandbox_p24_api_key" name="sandbox_p24_api_key" value="<?php echo get_current_setting_value('sandbox_p24_api_key', $current_settings); ?>" class="monospace">
                    <label class="checkbox-label" for="p24_sandbox_enabled">
                        <input type="checkbox" id="p24_sandbox_enabled" name="p24_sandbox_enabled" value="true" <?php echo is_sandbox_enabled('p24_sandbox_enabled', $current_settings) ? 'checked' : ''; ?>>
                        <strong>Aktywny Tryb Sandbox dla Przelewy24</strong>
                    </label>
                </div>
            </details>
            <details class="settings-subcard">
                <summary>Środowisko Produkcyjne</summary>
                <div class="setting-group">
                    <label for="production_p24_pos_id">Produkcyjny POS ID</label>
                    <input type="text" id="production_p24_pos_id" name="production_p24_pos_id" value="<?php echo get_current_setting_value('production_p24_pos_id', $current_settings); ?>">
                    <label for="production_p24_crc_key">Produkcyjny Klucz CRC</label>
                    <input type="text" id="production_p24_crc_key" name="production_p24_crc_key" value="<?php echo get_current_setting_value('production_p24_crc_key', $current_settings); ?>">
                    <label for="production_p24_api_key">Produkcyjny Klucz API (do raportów)</label>
                    <input type="text" id="production_p24_api_key" name="production_p24_api_key" value="<?php echo get_current_setting_value('production_p24_api_key', $current_settings); ?>" class="monospace">
                </div>
            </details>
        </fieldset>
    </details>

    <details class="settings-card">
        <summary>Ustawienia InPost API (ShipX + Geowidget)</summary>
        <fieldset>
            <legend>Ustawienia InPost API (ShipX + Geowidget)</legend>
            <details class="settings-subcard" open>
                <summary>Środowisko Sandbox (Testowe)</summary>
                <div class="setting-group">
                    <label for="sandbox_inpost_org_id">Sandbox ID Organizacji ShipX</label>
                    <input type="text" id="sandbox_inpost_org_id" name="sandbox_inpost_org_id" value="<?php echo get_current_setting_value('sandbox_inpost_org_id', $current_settings); ?>">
                    <label for="sandbox_inpost_token">Sandbox Token API ShipX (Bearer)</label>
                    <textarea id="sandbox_inpost_token" name="sandbox_inpost_token" class="monospace token-area"><?php echo get_current_setting_value('sandbox_inpost_token', $current_settings); ?></textarea>
                    <label for="sandbox_geowidget_token">Sandbox Token Geowidget</label>
                    <textarea id="sandbox_geowidget_token" name="sandbox_geowidget_token" class="monospace token-area"><?php echo get_current_setting_value('sandbox_geowidget_token', $current_settings); ?></textarea>
                    <label class="checkbox-label" for="inpost_sandbox_enabled">
                        <input type="checkbox" id="inpost_sandbox_enabled" name="inpost_sandbox_enabled" value="true" <?php echo is_sandbox_enabled('inpost_sandbox_enabled', $current_settings) ? 'checked' : ''; ?>>
                        <strong>Aktywny Tryb Sandbox dla InPost (ShipX + Geowidget)</strong>
                    </label>
                </div>
            </details>
            <details class="settings-subcard">
                <summary>Środowisko Produkcyjne</summary>
                <div class="setting-group">
                    <label for="production_inpost_org_id">Produkcyjne ID Organizacji ShipX</label>
                    <input type="text" id="production_inpost_org_id" name="production_inpost_org_id" value="<?php echo get_current_setting_value('production_inpost_org_id', $current_settings); ?>">
                    <label for="production_inpost_token">Produkcyjny Token API ShipX (Bearer)</label>
                    <textarea id="production_inpost_token" name="production_inpost_token" class="monospace token-area"><?php echo get_current_setting_value('production_inpost_token', $current_settings); ?></textarea>
                    <label for="production_geowidget_token">Produkcyjny Token Geowidget</label>
                    <textarea id="production_geowidget_token" name="production_geowidget_token" class="monospace token-area"><?php echo get_current_setting_value('production_geowidget_token', $current_settings); ?></textarea>
                </div>
            </details>
        </fieldset>
    </details>

    <details class="settings-card">
        <summary>Ustawienia Przesyłki</summary>
        <fieldset>
            <legend>Ustawienia Przesyłki</legend>
            <div class="setting-group">
                <label for="large_parcel_surcharge">Dopłata za dużą paczkę (PLN)</label>
                <input type="number" id="large_parcel_surcharge" name="large_parcel_surcharge" step="0.01" min="0.00" value="<?php echo get_current_setting_value('large_parcel_surcharge', $current_settings, '5.00'); ?>">
                <small>Kwota dodawana do ceny usługi, gdy klient wybierze większy rozmiar paczki.</small>
            </div>
        </fieldset>
    </details>

    <details class="settings-card">
        <summary>Dane Odbiorcy (Serwisu Naprawczego)</summary>
        <fieldset>
            <legend>Dane Odbiorcy (Serwisu Naprawczego)</legend>
            <div class="setting-group">
                <label for="service_receiver_name">Nazwa odbiorcy (na etykiecie)</label>
                <input type="text" id="service_receiver_name" name="service_receiver_name" value="<?php echo get_current_setting_value('service_receiver_name', $current_settings); ?>" required>
                <label for="service_receiver_email">E-mail odbiorcy</label>
                <input type="email" id="service_receiver_email" name="service_receiver_email" value="<?php echo get_current_setting_value('service_receiver_email', $current_settings); ?>" required>
                <label for="service_receiver_phone">Telefon odbiorcy</label>
                <input type="tel" id="service_receiver_phone" name="service_receiver_phone" value="<?php echo get_current_setting_value('service_receiver_phone', $current_settings); ?>" required>
            </div>
            <?php
                $legacy_locker = $current_settings['service_receiver_locker'] ?? '';
                $sandbox_locker_default = $current_settings['sandbox_service_receiver_locker'] ?? $legacy_locker;
                $production_locker_default = $current_settings['production_service_receiver_locker'] ?? $legacy_locker;
            ?>
            <details class="settings-subcard" open>
                <summary>Docelowy Paczkomat odbiorcy — Środowisko Sandbox (Testowe)</summary>
                <div class="setting-group">
                    <label for="sandbox_service_receiver_locker">Sandbox – ID paczkomatu odbiorcy</label>
                    <input type="text" id="sandbox_service_receiver_locker" name="sandbox_service_receiver_locker" value="<?php echo htmlspecialchars($sandbox_locker_default); ?>" required>
                    <small>Używany, gdy aktywny jest tryb Sandbox InPost (np. KRA012).</small>
                </div>
            </details>
            <details class="settings-subcard">
                <summary>Docelowy Paczkomat odbiorcy — Środowisko Produkcyjne</summary>
                <div class="setting-group">
                    <label for="production_service_receiver_locker">Produkcja – ID paczkomatu odbiorcy</label>
                    <input type="text" id="production_service_receiver_locker" name="production_service_receiver_locker" value="<?php echo htmlspecialchars($production_locker_default); ?>" required>
                    <small>Używany w trybie produkcyjnym InPost (np. JAS02N).</small>
                </div>
            </details>
        </fieldset>
    </details>

    <details class="settings-card">
        <summary>Ustawienia Nadawcy i E-mail</summary>
        <fieldset>
            <legend>Ustawienia Nadawcy i E-mail</legend>
            <div class="setting-group">
                <label for="sender_name_override">Nadpisz nazwę nadawcy na etykiecie InPost</label>
                <input type="text" id="sender_name_override" name="sender_name_override" value="<?php echo get_current_setting_value('sender_name_override', $current_settings); ?>">
                <small>Zostaw puste, aby użyć danych klienta.</small>
                <label for="email_from_address">Adres e-mail "Od" (w powiadomieniach)</label>
                <input type="email" id="email_from_address" name="email_from_address" value="<?php echo get_current_setting_value('email_from_address', $current_settings); ?>" required>
                <label for="email_from_name">Nazwa nadawcy "Od" (w powiadomieniach)</label>
                <input type="text" id="email_from_name" name="email_from_name" value="<?php echo get_current_setting_value('email_from_name', $current_settings); ?>" required>
            </div>
        </fieldset>
    </details>

    <details class="settings-card">
        <summary>Ustawienia SMTP (wysyłka e-maili)</summary>
        <fieldset>
            <legend>Ustawienia SMTP (wysyłka e-maili)</legend>
            <p><small>Jeśli pole „Host SMTP" jest puste dla aktywnego trybu, system użyje wbudowanej funkcji PHP <code>mail()</code>. Tryb (Sandbox/Produkcja) bierzemy z ustawień Przelewy24.</small></p>
            <details class="settings-subcard" open>
                <summary>Środowisko Sandbox (Testowe)</summary>
                <div class="setting-group">
                    <label for="sandbox_smtp_host">Sandbox – Host SMTP</label>
                    <input type="text" id="sandbox_smtp_host" name="sandbox_smtp_host" value="<?php echo get_current_setting_value('sandbox_smtp_host', $current_settings); ?>" placeholder="np. smtp.gmail.com">
                    <label for="sandbox_smtp_port">Sandbox – Port</label>
                    <input type="number" id="sandbox_smtp_port" name="sandbox_smtp_port" value="<?php echo get_current_setting_value('sandbox_smtp_port', $current_settings, '587'); ?>" min="1" max="65535">
                    <label for="sandbox_smtp_user">Sandbox – Użytkownik</label>
                    <input type="text" id="sandbox_smtp_user" name="sandbox_smtp_user" value="<?php echo get_current_setting_value('sandbox_smtp_user', $current_settings); ?>" autocomplete="off">
                    <label for="sandbox_smtp_password">Sandbox – Hasło</label>
                    <input type="password" id="sandbox_smtp_password" name="sandbox_smtp_password" value="<?php echo get_current_setting_value('sandbox_smtp_password', $current_settings); ?>" autocomplete="new-password">
                    <label for="sandbox_smtp_encryption">Sandbox – Szyfrowanie</label>
                    <select id="sandbox_smtp_encryption" name="sandbox_smtp_encryption">
                        <?php $sandbox_enc = $current_settings['sandbox_smtp_encryption'] ?? 'tls'; ?>
                        <option value="tls" <?php echo $sandbox_enc === 'tls' ? 'selected' : ''; ?>>STARTTLS (port 587)</option>
                        <option value="ssl" <?php echo $sandbox_enc === 'ssl' ? 'selected' : ''; ?>>SSL/TLS (port 465)</option>
                        <option value="none" <?php echo $sandbox_enc === 'none' ? 'selected' : ''; ?>>Brak (niezalecane)</option>
                    </select>
                </div>
            </details>
            <details class="settings-subcard">
                <summary>Środowisko Produkcyjne</summary>
                <div class="setting-group">
                    <label for="production_smtp_host">Produkcja – Host SMTP</label>
                    <input type="text" id="production_smtp_host" name="production_smtp_host" value="<?php echo get_current_setting_value('production_smtp_host', $current_settings); ?>" placeholder="np. smtp.gmail.com">
                    <label for="production_smtp_port">Produkcja – Port</label>
                    <input type="number" id="production_smtp_port" name="production_smtp_port" value="<?php echo get_current_setting_value('production_smtp_port', $current_settings, '587'); ?>" min="1" max="65535">
                    <label for="production_smtp_user">Produkcja – Użytkownik</label>
                    <input type="text" id="production_smtp_user" name="production_smtp_user" value="<?php echo get_current_setting_value('production_smtp_user', $current_settings); ?>" autocomplete="off">
                    <label for="production_smtp_password">Produkcja – Hasło</label>
                    <input type="password" id="production_smtp_password" name="production_smtp_password" value="<?php echo get_current_setting_value('production_smtp_password', $current_settings); ?>" autocomplete="new-password">
                    <label for="production_smtp_encryption">Produkcja – Szyfrowanie</label>
                    <select id="production_smtp_encryption" name="production_smtp_encryption">
                        <?php $prod_enc = $current_settings['production_smtp_encryption'] ?? 'tls'; ?>
                        <option value="tls" <?php echo $prod_enc === 'tls' ? 'selected' : ''; ?>>STARTTLS (port 587)</option>
                        <option value="ssl" <?php echo $prod_enc === 'ssl' ? 'selected' : ''; ?>>SSL/TLS (port 465)</option>
                        <option value="none" <?php echo $prod_enc === 'none' ? 'selected' : ''; ?>>Brak (niezalecane)</option>
                    </select>
                </div>
            </details>
        </fieldset>
    </details>

    <details class="settings-card">
        <summary>Domyślny szablon wyceny e-mail</summary>
        <?php include 'includes/quote_template_settings.php'; ?>
    </details>

    <button type="submit" class="button">Zapisz Ustawienia</button>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const settingsForm = document.querySelector('.settings-form');

    if (!settingsForm) {
        return;
    }

    settingsForm.addEventListener('invalid', function (event) {
        let panel = event.target.closest('details');

        while (panel) {
            panel.open = true;
            panel = panel.parentElement.closest('details');
        }
    }, true);
});
</script>

<?php include 'includes/footer.php'; ?>
