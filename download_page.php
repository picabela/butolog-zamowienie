<?php
// /zamowienie/download_page.php

// Sprawdź, czy przekazano ID przesyłki w adresie URL (?shipment_id=...)
if (!isset($_GET['shipment_id']) || !ctype_digit($_GET['shipment_id'])) {
    // Jeśli nie ma ID lub nie jest to liczba, wyświetl błąd i zakończ
    die("Błąd: Brak lub nieprawidłowe ID przesyłki w adresie URL.");
}
// Pobierz ID przesyłki i zabezpiecz je (chociaż ctype_digit już to robi)
$shipmentId = htmlspecialchars($_GET['shipment_id']);

// Stwórz link do skryptu, który faktycznie pobierze etykietę (fetch_label.php)
// Ten link będzie użyty w przycisku poniżej
$downloadActionLink = "fetch_label.php?shipment_id=" . $shipmentId;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pobierz etykietę InPost - BUTOLOG</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Dodatkowe style, aby wycentrować zawartość */
        .container {
            text-align: center; /* Wycentruj tekst i przycisk w kontenerze */
            margin-top: 50px; /* Dodaj trochę marginesu od góry */
        }
        .button.download-button {
            background-color: #28a745; /* Zielony kolor dla przycisku pobierania */
            font-size: 1.2em; /* Większy tekst na przycisku */
            padding: 15px 40px; /* Większy padding */
        }
        .button.download-button:hover {
            background-color: #218838; /* Ciemniejszy zielony przy najechaniu */
        }
        .shipment-id-info {
            font-size: 0.9em;
            color: #6c757d; /* Szary kolor dla ID */
            margin-bottom: 25px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Pobieranie etykiety nadawczej</h1>
        <p>Twoja etykieta InPost jest gotowa do pobrania.</p>
        <p class="shipment-id-info">ID: <?php echo $shipmentId; ?></p>
        <p>Kliknij poniższy przycisk, aby pobrać etykietę w formacie PDF.</p>

        <a href="<?php echo htmlspecialchars($downloadActionLink); ?>" class="button download-button">
            Pobierz etykietę (PDF)
        </a>
        <p>Wydrukuj etykietę i przyklej na przesyłkę a następnie zanieś do dowolnego paczkomatu.</p>

        <p style="margin-top: 30px;"><a href="https://butolog.pl/" style="color: #8d6e63;">Wróć na butolog.pl</a></p>
    </div>
</body>
</html>