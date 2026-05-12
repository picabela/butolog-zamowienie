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
            'service_receiver_name', 'service_receiver_email', 'service_receiver_phone', 'service_receiver_locker',
            'sender_name_override',
            'email_from_address', 'email_from_name',
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
                <label for="service_receiver_locker">Docelowy Paczkomat odbiorcy (ID)</label>
                <input type="text" id="service_receiver_locker" name="service_receiver_locker" value="<?php echo get_current_setting_value('service_receiver_locker', $current_settings); ?>" required>
            </div>
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
        <summary>Domyślny szablon wyceny e-mail</summary>
        <fieldset>
            <legend>Domyślny szablon wyceny e-mail</legend>
            <div class="setting-group">
                <label for="global_quote_subject">Domyślny temat e-maila z wyceną</label>
                <input type="text" id="global_quote_subject" name="global_quote_subject" value="<?php echo get_current_setting_value('global_quote_subject', $current_settings, htmlspecialchars($default_quote_subject)); ?>">
                <label for="global_quote_body">Domyślna treść e-maila z wyceną (HTML)</label>
                <textarea id="global_quote_body" name="global_quote_body" class="monospace quote-template-area"><?php echo get_current_setting_value('global_quote_body', $current_settings, htmlspecialchars($default_quote_body)); ?></textarea>
                <small>Ten szablon jest używany dla nowych wycen i wysyłek bez zapisanej, indywidualnie edytowanej treści. Dostępne zmienne: <code>{{EMAIL_KLIENTA}}</code>, <code>{{NAZWA_USLUGI}}</code>, <code>{{CENA}}</code>, <code>{{LINK_DO_PLATNOSCI}}</code>.</small>
            </div>
        </fieldset>
    </details>

    <?php include 'includes/quote_template_settings.php'; ?>

    <?php include 'includes/quote_template_settings.php'; ?>

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
