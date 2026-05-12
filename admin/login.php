<?php
// /zamowienie/admin/login.php - Logika logowania


// Wymagaj połączenia z bazą i konfiguracji
require_once '../includes/db.php'; // ../ bo jesteśmy w folderze /admin/

// Upewnij się, że sesja jest aktywna
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sprawdź, czy dane zostały przesłane metodą POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Jeśli nie, przekieruj do formularza logowania
    header("Location: index.php");
    exit;
}

// Pobierz login i hasło z formularza
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Podstawowa walidacja (czy nie są puste)
if (empty($username) || empty($password)) {
    $_SESSION['login_error'] = "Nazwa użytkownika i hasło są wymagane.";
    header("Location: index.php");
    exit;
}

// Wyszukaj użytkownika w bazie danych
try {
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    // Sprawdź, czy użytkownik istnieje i czy hasło się zgadza
    if ($admin && password_verify($password, $admin['password_hash'])) {
        // Hasło poprawne - zaloguj użytkownika

        // Regeneruj ID sesji dla bezpieczeństwa
        session_regenerate_id(true);

        // Ustaw zmienne sesji
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];

        // Usuń ewentualny stary komunikat błędu
        unset($_SESSION['login_error']);

        // Przekieruj do panelu głównego
        header("Location: dashboard.php");
        exit;

    } else {
        // Użytkownik nie istnieje lub hasło jest niepoprawne
        $_SESSION['login_error'] = "Nieprawidłowa nazwa użytkownika lub hasło.";
        header("Location: index.php");
        exit;
    }

} catch (\PDOException $e) {
    // Błąd bazy danych
    error_log("DB Error during admin login: " . $e->getMessage());
    $_SESSION['login_error'] = "Wystąpił błąd serwera podczas próby logowania. Spróbuj ponownie później.";
    header("Location: index.php");
    exit;
}
?>