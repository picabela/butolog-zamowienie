<?php
// /zamowienie/admin/orders.php

require_once 'includes/auth_check.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

$page_title = "Zarządzanie Zamówieniami";
include 'includes/header.php';
include 'includes/admin_ui_assets.php';

$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['success_message'], $_SESSION['error_message']);

// Pobierz token CSRF (będzie potrzebny do linków usuwania)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- Pobieranie listy zamówień z bazy danych ---
try {
    // Używamy LEFT JOIN na wypadek, gdyby formularz został usunięty
    $stmt = $pdo->query(
        "SELECT o.*, f.service_name
         FROM orders o
         LEFT JOIN forms f ON o.form_id = f.id
         ORDER BY o.created_at DESC"
    );
    $orders = $stmt->fetchAll();
} catch (\PDOException $e) {
    error_log("DB Error fetching orders list: " . $e->getMessage());
    $error_message = "Nie udało się pobrać listy zamówień.";
    $orders = [];
}

?>

<h2><?php echo $page_title; ?></h2>

<?php if ($success_message): ?>
    <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<h3>Lista Zamówień</h3>
<?php if (!empty($orders)): ?>
    
    <!-- === POPRAWKA RESPONSIVE: Dodano wrapper dla tabeli === -->
    <div class="table-wrapper admin-table-wrapper orders-table-wrapper">
        <table class="admin-compact-table orders-table">
            <thead>
                <tr>
                    <th>ID Zam. (Session)</th>
                    <th>Data Utworzenia</th>
                    <th>Klient</th>
                    <th>Usługa</th>
                    <th style="text-align: right;">Kwota</th>
                    <th style="text-align: center;">Status Płatności</th>
                    <th style="text-align: center;">Status Naprawy</th>
                    <th>Nr Przesyłki InPost</th>
                    <th>Wysyłka zwrotna</th>
                    <th style="text-align: center;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td data-label="ID Zam. (Session)" style="word-break: break-all;"><?php echo htmlspecialchars($order['session_id']); ?></td>
                        <td data-label="Data Utworzenia"><?php echo date('Y-m-d H:i', strtotime($order['created_at'])); ?></td>
                        <td data-label="Klient">
                            <?php echo htmlspecialchars($order['sender_name']); ?><br>
                            <small><?php echo htmlspecialchars($order['sender_email']); ?></small>
                        </td>
                        <td data-label="Usługa"><?php echo htmlspecialchars($order['service_name'] ?? 'Usunięty formularz'); ?></td>
                        <td data-label="Kwota" style="text-align: right;"><?php echo number_format($order['amount'], 2, ',', ' '); ?> PLN</td>
                        <td data-label="Status Płatności" style="text-align: center;">
                            <?php 
                                // Opcjonalne kolorowanie statusów
                                $payment_status_color = 'inherit';
                                if ($order['payment_status'] == 'paid') $payment_status_color = 'green';
                                if ($order['payment_status'] == 'pending') $payment_status_color = '#cc8400';
                                if ($order['payment_status'] == 'failed' || $order['payment_status'] == 'cancelled') $payment_status_color = 'red';
                                echo '<span style="color: ' . $payment_status_color . '; font-weight: bold;">' . htmlspecialchars(ucfirst($order['payment_status'])) . '</span>';
                            ?>
                        </td>
                        <td data-label="Status Naprawy" style="text-align: center;">
                            <?php echo htmlspecialchars(ucfirst($order['repair_status'])); ?>
                        </td>
                        <td data-label="Nr Przesyłki InPost">
                            <?php if (!empty($order['inpost_tracking_number'])): ?>
                                <a href="https://inpost.pl/sledzenie-przesylek?number=<?php echo htmlspecialchars($order['inpost_tracking_number']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($order['inpost_tracking_number']); ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td data-label="Wysyłka zwrotna">
                            <?php
                                $return_locker_id_clean = str_replace('PL', '', $order['return_locker_id'] ?? '');
                                $return_address_parts = array_filter([
                                    $order['return_locker_street'] ?? '',
                                    trim(($order['return_locker_postcode'] ?? '') . ' ' . ($order['return_locker_city'] ?? ''))
                                ], fn($v) => $v !== '');
                                $return_address = implode(', ', $return_address_parts);
                            ?>
                            <strong><?php echo htmlspecialchars($order['sender_name']); ?></strong><br>
                            <?php if (!empty($order['sender_phone'])): ?>
                                <small>tel. <?php echo htmlspecialchars($order['sender_phone']); ?></small><br>
                            <?php endif; ?>
                            <?php if (!empty($return_locker_id_clean)): ?>
                                <small><strong>Paczkomat:</strong> <?php echo htmlspecialchars($return_locker_id_clean); ?></small><br>
                                <?php if (!empty($return_address)): ?>
                                    <small><?php echo htmlspecialchars($return_address); ?></small><br>
                                <?php endif; ?>
                            <?php else: ?>
                                <small>Brak paczkomatu zwrotnego</small><br>
                            <?php endif; ?>
                            <?php if (!empty($order['inpost_shipment_id'])): ?>
                                <a href="../fetch_label.php?shipment_id=<?php echo urlencode($order['inpost_shipment_id']); ?>" target="_blank" rel="noopener" title="Pobierz etykietę PDF">📄 Pobierz etykietę PDF</a>
                            <?php endif; ?>
                        </td>
                        <td data-label="Akcje" style="text-align: center;">
                            <a href="view_order.php?id=<?php echo $order['id']; ?>" title="Zobacz szczegóły">👁️</a> |
                            <a href="edit_order_status.php?id=<?php echo $order['id']; ?>" title="Zmień status">✏️</a> |
                            
                            <a href="handle_order_action.php?action=delete_order&id=<?php echo $order['id']; ?>&token=<?php echo $csrf_token; ?>" 
                               onclick="return confirm('Czy na pewno chcesz nieodwracalnie usunąć to zamówienie?');" 
                               title="Usuń zamówienie">🗑️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div> <!-- === ZAMKNIĘCIE WRAPPERA === -->
<?php else: ?>
    <p>Nie znaleziono żadnych zamówień.</p>
    <p>Gdy klient wypełni formularz naprawy i spróbuje dokonać płatności, zamówienie pojawi się tutaj ze statusem "pending".</p>
<?php endif; ?>

<?php
include 'includes/footer.php';
?>

