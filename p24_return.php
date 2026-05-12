<?php
// /zamowienie/p24_return.php
// Prosta strona powrotna dla klienta

// Można opcjonalnie spróbować odczytać session_id z parametrów GET,
// które P24 *może* dodać do URL, ale nie jest to gwarantowane.
// $session_id = $_GET['sessionId'] ?? null; // Przykładowo, nie używamy tego dalej

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Powrót z Przelewy24 - BUTOLOG</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Dodatkowe style specyficzne dla tej strony */
        .container {
            text-align: center; /* Wycentruj zawartość */
        }
        .icon {
            font-size: 3em;
            margin-bottom: 20px;
        }
        .info {
            background-color: #e3f2fd; /* Jaśniejszy niebieski dla informacji */
            color: #0d47a1;
            border-color: #bbdefb;
            text-align: left; /* Tekst wewnątrz info-boxu wyrównany do lewej */
        }
         .back-link {
            display: inline-block;
            margin-top: 30px;
            color: #795548;
            text-decoration: none;
            border: 1px solid #d7ccc8;
            padding: 10px 20px;
            border-radius: 5px;
            transition: background-color 0.3s ease, color 0.3s ease;
         }
         .back-link:hover {
            background-color: #efebe9;
            color: #5d4037;
         }

    </style>
</head>
<body>
    <div class="container">
        <div class="icon">⏳</div>

        <h1>Dziękujemy!</h1>
        <p>Zakończyłeś proces płatności przez Przelewy24.</p>

        <div class="info">
            <p><strong>Co teraz?</strong></p>
            <ul>
                <li>Jeśli Twoja płatność zostanie pomyślnie przetworzona i zaksięgowana, <strong>otrzymasz od nas e-mail</strong> z potwierdzeniem oraz linkiem do strony pobierania etykiety nadawczej InPost.</li>
                <li>Proces księgowania może zająć od kilku sekund (np. BLIK) do nawet kilku godzin (przelew tradycyjny). Status płatności możesz sprawdzić w wiadomości e-mail otrzymanej od Przelewy24</li>
                <li>Prosimy o sprawdzenie skrzynki e-mail (również folderu SPAM) pod adresem podanym w formularzu.</li>
            </ul>
        </div>

        <a href="https://butolog.pl/" class="back-link">Wróć na butolog.pl</a>

    </div>
</body>
</html>