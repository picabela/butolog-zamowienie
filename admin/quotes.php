<?php
// /zamowienie/admin/quotes.php

// Wymagaj sprawdzenia autoryzacji
require_once 'includes/auth_check.php';
// Wczytaj połączenie z bazą i funkcje pomocnicze
require_once '../includes/db.php';
require_once '../includes/functions.php'; // Potrzebna funkcja generate_uuid()

$page_title = "Zarządzanie Wycenami"; // Ustaw tytuł strony
include 'includes/header.php'; // Wczytaj nagłówek HTML

// Sprawdź, czy są jakieś komunikaty z sesji (np. po udanej akcji)
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
// Usuń komunikaty z sesji po ich pobraniu, aby nie wyświetlały się ponownie
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Pobierz token CSRF (do linków akcji i formularza)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Pobieranie listy istniejących wycen ---
try {
    // Łączymy wyceny (quotes) z formularzami (forms), aby uzyskać nazwę usługi i cenę
    $stmt = $pdo->query(
        "SELECT q.*, f.service_name, f.price
         FROM quotes q
         LEFT JOIN forms f ON q.form_id = f.id
         ORDER BY q.created_at DESC" // Sortuj od najnowszych
    );
    $quotes = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("DB Error fetching quotes list: " . $e->getMessage());
    $error_message = "Nie udało się pobrać listy wycen.";
    $quotes = []; // Ustaw pustą tablicę, aby reszta strony działała
}

?>

<h2><?php echo $page_title; ?></h2>

<?php if ($success_message): ?><div class="success-message"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
<?php if ($error_message): ?><div class="error-message"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<div class="add-form-section" style="margin-bottom: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #eee; border-radius: 5px;">
    <h3>Utwórz Nową Wycenę</h3>
    <form action="handle_quote_action.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="create_quote">

        <label for="form_uuid">UUID Formularza (Usługi) *</label>
        <input type="text" id="form_uuid" name="form_uuid" required placeholder="Wklej UUID formularza z zakładki 'Formularze'">
        <small>To określi, jaka usługa i cena bazowa zostaną użyte w wycenie.</small>

        <label for="client_email">E-mail Klienta *</label>
        <input type="email" id="client_email" name="client_email" required placeholder="np. klient@example.com">
        <small>Adres, na który zostanie wysłana wycena z linkiem do płatności.</small>

        <button type="submit" class="button add-button">Utwórz Wycenę</button>
    </form>
</div>

<h3>Istniejące Wyceny</h3>
<?php if (!empty($quotes)): ?>
    <div class="table-wrapper"> <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Data Utworzenia</th>
                    <th>E-mail Klienta</th>
                    <th>Powiązana Usługa</th>
                    <th style="text-align: right;">Cena</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $quote): ?>
                    <tr>
                        <td><?php echo $quote['id']; ?></td>
                        <td><?php echo date('Y-m-d H:i', strtotime($quote['created_at'])); ?></td>
                        <td style="word-break: break-all;"><?php echo htmlspecialchars($quote['client_email']); ?></td>
                        <td><?php echo htmlspecialchars($quote['service_name'] ?? 'Brak (formularz usunięty?)'); ?></td>
                        <td style="text-align: right;"><?php echo number_format($quote['price'] ?? 0, 2, ',', ' '); ?> PLN</td>
                        <td style="text-align: center;">
                            <?php if ($quote['status'] == 'sent'): ?>
                                <span class="status-sent" title="Wysłana">Wysłana</span>
                            <?php else: ?>
                                <span class="status-draft">Szkic</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; white-space: nowrap;">
                            <a href="edit_quote.php?uuid=<?php echo $quote['uuid']; ?>" title="Edytuj i Wyślij">✏️ Edytuj</a> |
                            
                            <a href="handle_quote_action.php?action=send_quote&uuid=<?php echo $quote['uuid']; ?>&token=<?php echo $csrf_token; ?>" 
                               title="Wyślij wycenę" class="action-send"
                               onclick="return confirm('Czy na pewno chcesz wysłać tę wycenę do klienta <?php echo htmlspecialchars($quote['client_email']); ?>?');">
                                ✉️ Wyślij
                            </a> |

                            <a href="handle_quote_action.php?action=delete_quote&uuid=<?php echo $quote['uuid']; ?>&token=<?php echo $csrf_token; ?>" 
                               onclick="return confirm('Czy na pewno chcesz usunąć tę wycenę?');" 
                               title="Usuń">🗑️ Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <p>Nie utworzono jeszcze żadnych wycen.</p>
<?php endif; ?>

<?php
// Wczytaj stopkę HTML
include 'includes/footer.php';
?>