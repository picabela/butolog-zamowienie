<?php
// /zamowienie/admin/index.php - Strona logowania

// Rozpocznij sesję, jeśli jeszcze nie jest aktywna
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sprawdź, czy admin jest już zalogowany
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    // Jeśli tak, przekieruj do głównego panelu
    header("Location: dashboard.php");
    exit;
}

// Sprawdź, czy jest komunikat o błędzie logowania z login.php
$login_error = null;
if (isset($_SESSION['login_error'])) {
    $login_error = $_SESSION['login_error'];
    unset($_SESSION['login_error']); // Usuń błąd po wyświetleniu
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie do Panelu Admina - BUTOLOG</title>
    <link rel="stylesheet" href="../assets/admin_style.css">
    <style>
        /* Dodatkowe style dla formularza logowania */
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f4f4f4;
        }
        .login-container {
            background-color: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-container h1 {
            margin-bottom: 30px;
            color: #333;
            border-bottom: none; /* Usuwamy standardową kreskę nagłówka */
        }
        .login-container label {
            display: block;
            text-align: left;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        .login-container input[type="text"],
        .login-container input[type="password"] {
            width: calc(100% - 22px); /* Pełna szerokość minus padding */
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .login-container .button {
            width: 100%;
            padding: 12px;
            font-size: 1.1em;
        }
        .error-message {
            margin-bottom: 20px; /* Odsuń błąd od pól */
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Panel Administracyjny</h1>
        <p>Logowanie</p>

        <?php if ($login_error): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($login_error); ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <label for="username">Nazwa użytkownika:</label>
            <input type="text" id="username" name="username" required autofocus>

            <label for="password">Hasło:</label>
            <input type="password" id="password" name="password" required>

            <button type="submit" class="button">Zaloguj się</button>
        </form>
    </div>
</body>
</html>