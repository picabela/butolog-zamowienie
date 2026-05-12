<?php
// /zamowienie/admin/edit_form.php

require_once 'includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$page_title = "Edycja Formularza";
include 'includes/header.php';

// Pobierz ID formularza z URL
$form_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$form_id) {
    echo "<div class='error-message'>Nieprawidłowe ID formularza.</div>";
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

// --- Logika aktualizacji formularza (gdy formularz jest wysyłany) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_form') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error_message = "Błąd CSRF Token. Odśwież stronę i spróbuj ponownie.";
    } else {
        // Walidacja ID (na wszelki wypadek)
        $posted_form_id = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
        if ($posted_form_id !== $form_id) {
             $error_message = "Błąd zgodności ID formularza.";
        } else {
            // Pobierz i waliduj dane (podobnie jak przy dodawaniu)
            $service_name = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
            $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $provided_uuid = isset($_POST['uuid']) ? trim($_POST['uuid']) : '';

            if (empty($service_name) || $price === false || $price <= 0) {
                $error_message = "Nazwa usługi i poprawna cena (większa od 0) są wymagane.";
            } elseif (empty($provided_uuid)) {
                 $error_message = "Unikalne ID (UUID) nie może być puste podczas edycji.";
            } elseif (strlen($provided_uuid) > 36) {
                 $error_message = "Podane UUID jest za długie (maksymalnie 36 znaków).";
            } elseif (preg_match('/[^a-zA-Z0-9-]/', $provided_uuid)) {
                 $error_message = "Podane UUID zawiera niedozwolone znaki.";
            } else {
                // Wszystko OK, zaktualizuj bazę
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE forms SET 
                         uuid = ?, service_name = ?, price = ?, description = ?, is_active = ?, updated_at = NOW() 
                         WHERE id = ?"
                    );
                    $stmt->execute([$provided_uuid, $service_name, $price, $description, $is_active, $form_id]);
                    
                    $_SESSION['success_message'] = "Formularz (ID: {$form_id}) został pomyślnie zaktualizowany.";
                    header("Location: forms.php"); // Wróć do listy
                    exit;
                    
                } catch (\PDOException $e) {
                    error_log("DB Error updating form {$form_id}: " . $e->getMessage());
                    if ($e->getCode() == 23000) { // Duplikat UUID
                         $error_message = "Błąd: Podane UUID ('" . htmlspecialchars($provided_uuid) . "') już istnieje w bazie danych dla innego formularza.";
                    } else {
                        $error_message = "Wystąpił błąd serwera podczas aktualizacji formularza.";
                    }
                }
            }
        }
    }
}


// --- Pobieranie danych formularza do wyświetlenia (gdy strona jest ładowana) ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // Pobierz tylko, jeśli formularz nie został właśnie wysłany (lub jeśli wystąpił błąd)
    try {
        $stmt_get_form = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
        $stmt_get_form->execute([$form_id]);
        $form_data = $stmt_get_form->fetch();

        if (!$form_data) {
            $error_message = "Nie znaleziono formularza o ID: {$form_id}";
        }
    } catch (\PDOException $e) {
        error_log("DB Error fetching form {$form_id} for edit: " . $e->getMessage());
        $error_message = "Błąd pobierania danych formularza.";
    }
} else {
    // Jeśli był błąd POST, użyj danych z POST, aby ponownie wypełnić formularz
    $form_data = [
        'id' => $form_id,
        'service_name' => $_POST['service_name'] ?? '',
        'price' => $_POST['price'] ?? '',
        'description' => $_POST['description'] ?? '',
        'uuid' => $_POST['uuid'] ?? '',
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];
}


?>

<h2><?php echo $page_title; ?> (ID: <?php echo $form_id; ?>)</h2>
<p><a href="forms.php" style="font-size: 0.9em;">&laquo; Wróć do listy formularzy</a></p>

<?php if ($success_message): ?><div class="success-message"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
<?php if ($error_message): ?><div class="error-message"><?php echo $error_message; ?></div><?php endif; ?>

<?php if (!empty($form_data)): // Pokaż formularz tylko jeśli dane zostały pobrane ?>
<div class="add-form-section" style="margin-bottom: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #eee; border-radius: 5px;">
    <h3>Edytuj Formularz Naprawy</h3>
    <form action="edit_form.php?id=<?php echo $form_id; ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="update_form">
        <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">

        <label for="service_name">Nazwa Usługi *</label>
        <input type="text" id="service_name" name="service_name" required value="<?php echo htmlspecialchars($form_data['service_name']); ?>">

        <label for="price">Cena (PLN) *</label>
        <input type="number" id="price" name="price" step="0.01" min="0.01" required value="<?php echo htmlspecialchars($form_data['price']); ?>">

        <label for="uuid">Unikalne ID (UUID) *</label>
        <input type="text" id="uuid" name="uuid" required value="<?php echo htmlspecialchars($form_data['uuid']); ?>" style="font-family: monospace;" maxlength="36">
        <small>Używaj tylko liter, cyfr i myślników. Musi być unikalne.</small>

        <label for="description">Opis (opcjonalny)</label>
        <textarea id="description" name="description"><?php echo htmlspecialchars($form_data['description']); ?></textarea>

        <label for="is_active" class="checkbox-label">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo ($form_data['is_active'] ?? 0) ? 'checked' : ''; ?>>
             Aktywny (link będzie działał dla klientów)
        </label>

        <button type="submit" class="button">Zapisz Zmiany</button>
    </form>
</div>
<?php else: ?>
    <p>Nie można załadować danych formularza.</p>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>
