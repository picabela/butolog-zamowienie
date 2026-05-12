<?php
// /zamowienie/admin/forms.php

require_once 'includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php'; // Potrzebna funkcja generate_uuid()

$page_title = "Zarządzanie Formularzami";
include 'includes/header.php';
?>
<link rel="stylesheet" href="../assets/admin_ui.css">
<script src="../assets/admin_behaviors.js" defer></script>
<?php

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// --- Logika obsługi dodawania nowego formularza ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_form') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error_message = "Błąd CSRF Token. Odśwież stronę i spróbuj ponownie.";
    } else {
        // === POPRAWKA: Zmiana z FILTER_SANITIZE_STRING na trim() ===
        $service_name = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
        $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $provided_uuid = isset($_POST['uuid']) ? trim($_POST['uuid']) : '';
        // =========================================================

        // Walidacja podstawowa
        if (empty($service_name) || $price === false || $price <= 0) {
            $error_message = "Nazwa usługi i poprawna cena (większa od 0) są wymagane.";
        } else {
            // Logika UUID (bez zmian)
            $uuid_to_use = '';
            if (!empty($provided_uuid)) {
                if (strlen($provided_uuid) > 36) {
                     $error_message = "Podane UUID jest za długie (maksymalnie 36 znaków).";
                } elseif (preg_match('/[^a-zA-Z0-9-]/', $provided_uuid)) {
                     $error_message = "Podane UUID zawiera niedozwolone znaki. Używaj tylko liter, cyfr i myślników.";
                } else {
                    $uuid_to_use = $provided_uuid;
                }
            } else {
                $uuid_to_use = generate_uuid();
            }

            // Kontynuuj tylko jeśli nie było błędu walidacji UUID
            if (empty($error_message)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO forms (uuid, service_name, price, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$uuid_to_use, $service_name, $price, $description, $is_active]);
                    $_SESSION['success_message'] = "Nowy formularz (UUID: " . htmlspecialchars($uuid_to_use) . ") został pomyślnie dodany.";
                    header("Location: forms.php");
                    exit;
                } catch (\PDOException $e) {
                    error_log("DB Error inserting form: " . $e->getMessage());
                    if ($e->getCode() == 23000) { // Duplikat UUID
                         $error_message = "Błąd: Podane UUID ('" . htmlspecialchars($provided_uuid) . "') już istnieje w bazie danych. Proszę podać inne lub pozostawić pole puste.";
                    } else {
                        $error_message = "Wystąpił błąd serwera podczas dodawania formularza.";
                    }
                }
            }
        }
    }
}

// Wygeneruj/pobierz token CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Pobieranie listy istniejących formularzy (bez zmian) ---
try {
    $stmt_list = $pdo->query("SELECT * FROM forms ORDER BY created_at DESC");
    $forms = $stmt_list->fetchAll();
} catch (\PDOException $e) {
    error_log("DB Error fetching forms list: " . $e->getMessage());
    $error_message = ($error_message ? $error_message . '<br>' : '') . "Nie udało się pobrać listy formularzy.";
    $forms = [];
}
?>

<h2><?php echo $page_title; ?></h2>

<?php if ($success_message): ?><div class="success-message"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
<?php if ($error_message): ?><div class="error-message"><?php echo $error_message; /* Nie escapuj, błąd UUID może mieć ' */ ?></div><?php endif; ?>

<!-- Formularz dodawania (z poprawnym <label> dla checkboxa) -->
<div class="add-form-section" style="margin-bottom: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #eee; border-radius: 5px;">
    <h3>Dodaj Nowy Formularz Naprawy</h3>
    <form action="forms.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="add_form">

        <label for="service_name">Nazwa Usługi *</label>
        <input type="text" id="service_name" name="service_name" required placeholder="np. Wymiana fleków">

        <label for="price">Cena (PLN) *</label>
        <input type="number" id="price" name="price" step="0.01" min="0.01" required placeholder="np. 49.99">

        <label for="uuid">Unikalne ID (UUID) - opcjonalne</label>
        <input type="text" id="uuid" name="uuid" placeholder="Zostaw puste, aby wygenerować automatycznie" style="font-family: monospace;" maxlength="36">
        <small>Możesz podać własne ID (np. `naprawa-premium-2025`). Używaj tylko liter, cyfr i myślników. Max 36 znaków. Musi być unikalne.</small>

        <label for="description">Opis (opcjonalny)</label>
        <textarea id="description" name="description" placeholder="Dodatkowy opis usługi widoczny dla admina"></textarea>

        <label for="is_active" class="checkbox-label">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
             Aktywny (link będzie działał dla klientów)
        </label>

        <button type="submit" class="button add-button">Dodaj Formularz</button>
    </form>
</div>

<!-- Tabela listy -->
<h3>Istniejące Formularze</h3>
<?php if (!empty($forms)): ?>
    
    <!-- === POPRAWKA RESPONSIVE: Dodano wrapper dla tabeli === -->
    <div class="table-wrapper admin-table-wrapper forms-table-wrapper">
        <table class="admin-compact-table forms-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Link dla Klienta (UUID)</th>
                    <th>Nazwa Usługi</th>
                    <th style="text-align: right;">Cena</th>
                    <th style="text-align: center;">Aktywny</th>
                    <th style="text-align: center;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $form): ?>
                    <tr>
                        <td data-label="ID"><?php echo $form['id']; ?></td>
                        <td data-label="Link dla Klienta (UUID)">
                            <?php if ($form['is_active']): ?>
                                <a href="../form.php?uuid=<?php echo htmlspecialchars($form['uuid']); ?>" target="_blank" title="Otwórz link publiczny">
                                    <?php echo htmlspecialchars($form['uuid']); ?>
                                </a>
                            <?php else: ?>
                                <span title="Formularz nieaktywny"><?php echo htmlspecialchars($form['uuid']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Nazwa Usługi"><?php echo htmlspecialchars($form['service_name']); ?></td>
                        <td data-label="Cena" style="text-align: right;"><?php echo number_format($form['price'], 2, ',', ' '); ?> PLN</td>
                        <td data-label="Aktywny" style="text-align: center;"><?php echo $form['is_active'] ? '✔️' : '❌'; ?></td>
                        <td data-label="Akcje" style="text-align: center;">
                            
                            <!-- POPRAWKA: Link do edycji -->
                            <a href="edit_form.php?id=<?php echo $form['id']; ?>" title="Edytuj">✏️</a> |
                            
                            <!-- POPRAWKA: Link do aktywacji/deaktywacji -->
                            <a href="handle_form_action.php?action=toggle_form&id=<?php echo $form['id']; ?>&token=<?php echo $csrf_token; ?>" 
                               title="<?php echo $form['is_active'] ? 'Dezaktywuj' : 'Aktywuj'; ?>">
                                <?php echo $form['is_active'] ? '🚫' : '✅'; ?>
                            </a> |

                            <!-- POPRAWKA: Link do usuwania -->
                            <a href="handle_form_action.php?action=delete_form&id=<?php echo $form['id']; ?>&token=<?php echo $csrf_token; ?>" 
                               onclick="return confirm('Czy na pewno chcesz usunąć ten formularz? Spowoduje to również usunięcie WSZYSTKICH powiązanych z nim zamówień!');" 
                               title="Usuń">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div> <!-- === ZAMKNIĘCIE WRAPPERA === -->
<?php else: ?>
    <p>Nie dodano jeszcze żadnych formularzy.</p>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>

