<?php
// /zamowienie/admin/includes/header.php

// Upewnij się, że sesja jest aktywna
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Pobierz nazwę zalogowanego użytkownika (jeśli istnieje)
$admin_username = $_SESSION['admin_username'] ?? 'Gość';

// Ustal, która strona jest aktywna, aby podświetlić link w menu
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Użyj zmiennej $page_title, jeśli jest ustawiona, lub domyślnego tytułu -->
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Panel Admina'; ?> - BUTOLOG</title>
    <link rel="stylesheet" href="../assets/admin_style.css">
</head>
<body>
    <header>
        <h1>BUTOLOG - Panel Admina</h1>
        <nav>
            <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                <span>Witaj, <?php echo htmlspecialchars($admin_username); ?>!</span>
                
                <!-- Dodajemy klasę 'active' do linku aktywnej strony -->
                <a href="dashboard.php" class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">Dashboard</a>
                
                <!-- === DODANO LINK DO WYCEN === -->
                <a href="quotes.php" class="<?php echo in_array($current_page, ['quotes.php', 'edit_quote.php']) ? 'active' : ''; ?>">Wyceny</a>
                <!-- ============================ -->
                
                <a href="forms.php" class="<?php echo in_array($current_page, ['forms.php', 'edit_form.php']) ? 'active' : ''; ?>">Formularze</a>
                <a href="orders.php" class="<?php echo in_array($current_page, ['orders.php', 'view_order.php', 'edit_order_status.php']) ? 'active' : ''; ?>">Zamówienia</a>
                <a href="settings.php" class="<?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">Ustawienia</a>
                <a href="logout.php">Wyloguj</a>
            <?php else: ?>
                <span>Panel Logowania</span>
            <?php endif; ?>
        </nav>
    </header>
    <main> <!-- Otwarcie głównego kontenera treści strony -->

