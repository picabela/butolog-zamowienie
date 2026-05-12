<?php
// /zamowienie/form.php
require_once 'includes/db.php'; // Zawiera $pdo
require_once 'includes/functions.php'; // Zawiera get_setting() i get_active_setting()

if (session_status() == PHP_SESSION_NONE) { session_start(); }

// --- NOWOŚĆ: Pobierz stare dane z sesji, jeśli istnieją ---
$old_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']); // Wyczyść dane z sesji po ich odczytaniu
$old_sender_name = htmlspecialchars($old_data['sender_name'] ?? '');
$old_sender_email = htmlspecialchars($old_data['sender_email'] ?? '');
$old_sender_phone = htmlspecialchars($old_data['sender_phone'] ?? '');
$old_parcel_size = $old_data['parcel_size'] ?? 'medium';
$old_locker_id = htmlspecialchars($old_data['return_locker_id'] ?? '');
$old_locker_street = htmlspecialchars($old_data['return_locker_street'] ?? '');
$old_locker_postcode = htmlspecialchars($old_data['return_locker_postcode'] ?? '');
$old_locker_city = htmlspecialchars($old_data['return_locker_city'] ?? '');
// Przygotuj dane JS do odtworzenia informacji o Geowidgecie
$js_repopulate_data = json_encode([
    'id' => $old_locker_id,
    'street' => $old_locker_street,
    'postcode' => $old_locker_postcode,
    'city' => $old_locker_city
]);
// ---------------------------------------------------

$form_uuid = $_GET['uuid'] ?? null;
if (!$form_uuid) { die("Błąd: Brak identyfikatora formularza."); }

try {
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE uuid = ? AND is_active = TRUE");
    $stmt->execute([$form_uuid]);
    $form = $stmt->fetch();
} catch (\PDOException $e) {
    error_log("DB Error fetching form (uuid: {$form_uuid}): " . $e->getMessage());
    die("Wystąpił błąd podczas ładowania formularza.");
}

if (!$form) { die("Formularz nie znaleziony lub nieaktywny."); }

$service_name = htmlspecialchars($form['service_name']);
$base_price_decimal = (float)$form['price']; // Cena bazowa
$form_id = $form['id'];

$error_message = $_SESSION['error_message'] ?? null;
unset($_SESSION['error_message']);

// --- Pobierz Aktywny Token Geowidgetu, Tryb Sandbox i Dopłatę ---
$inpost_sandbox_enabled = get_setting('inpost_sandbox_enabled', $pdo);
$geowidget_token = get_active_setting('geowidget_token', $pdo); // Pobiera aktywny token (sandbox lub prod)
$large_parcel_surcharge_str = get_setting('large_parcel_surcharge', $pdo);
$large_parcel_surcharge = ($large_parcel_surcharge_str !== null) ? (float)$large_parcel_surcharge_str : 5.00; // Domyślnie 5.00 PLN

// Ustal URL bazowy dla zasobów Geowidgetu
$geowidget_base_url = $inpost_sandbox_enabled
    ? 'https://sandbox-global-geowidget-sdk.easypack24.net'
    : 'https://geowidget.inpost.pl';

// Sprawdź, czy token został pobrany
if (empty($geowidget_token)) {
     $mode_text = $inpost_sandbox_enabled ? 'sandbox' : 'production';
     error_log("Krytyczny błąd w form.php: Brak aktywnego tokenu Geowidget ({$mode_text}) w bazie danych.");
     die("Błąd konfiguracji mapy paczkomatów. Skontaktuj się z administratorem.");
}
// ------------------------------------------------------------

// Formatuj cenę bazową
$base_price_formatted = number_format($base_price_decimal, 2, ',', ' ');

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zamówienie Naprawy: <?php echo $service_name; ?></title>
    <link rel="stylesheet" href="assets/style.css">

    <!-- Skrypty i Style Geowidgetu (dynamiczne URL) -->
    <link rel="stylesheet" href="<?php echo $geowidget_base_url; ?>/inpost-geowidget.css"/>
    <script src='<?php echo $geowidget_base_url; ?>/inpost-geowidget.js' defer></script>

    <style>
        /* Styl dla kontenera Geowidgetu */
        #geowidget-container-inline { margin-top: 10px; border: 1px solid #d7ccc8; border-radius: 6px; padding: 5px; min-height: 450px; height: 60vh; position: relative; overflow: hidden; }
        /* Informacja o wybranym punkcie */
        #selected-point-info { margin-top: 15px; padding: 12px 15px; background-color: #efebe9; border: 1px solid #d7ccc8; border-radius: 6px; font-size: 0.95em; color: #5d4037; display: block; text-align: left; position: relative; padding-right: 80px; }
        #selected-point-info strong { display: block; margin-bottom: 5px; font-weight: 600; }
        #selected-point-info.selected { background-color: #dcedc8; border-color: #c5e1a5; color: #33691e; }
        /* Styl linku reset */
        #reset-selection-link { position: absolute; bottom: 8px; right: 10px; font-size: 0.85em; color: var(--link-color, #795548); text-decoration: underline; cursor: pointer; display: none; }
        #selected-point-info.selected #reset-selection-link { display: inline; }
        #reset-selection-link:hover { color: var(--primary-color, #5d4037); }
        /* Komunikat o konieczności wyboru */
        #locker-error-message { color: #c62828; font-size: 0.9em; margin-top: 5px; display: none; }
        /* Style dla Wyboru rozmiaru paczki */
        .parcel-size-options { margin-top: 25px; margin-bottom: 15px; padding: 15px; background-color: #f9f9f9; border: 1px solid #eee; border-radius: 6px; }
        .parcel-size-options legend { font-weight: 600; margin-bottom: 10px; color: #6d4c41; }
        .parcel-size-options label { display: block; margin-bottom: 8px; cursor: pointer; font-weight: normal; }
        .parcel-size-options input[type="radio"] { margin-right: 8px; vertical-align: middle; }
        .parcel-size-options small { display: block; margin-left: 25px; color: #777; font-size: 0.85em; }
        /* Cena całkowita */
        #total-price-display { font-size: 1.3em; font-weight: bold; color: var(--primary-color, #5d4037); }
        /* Style dla Modala Podsumowania */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); display: none; justify-content: center; align-items: center; z-index: 1000; opacity: 0; transition: opacity 0.3s ease-in-out; }
        .modal-overlay.visible { display: flex; opacity: 1; }
        .modal-content { background-color: #fff; padding: 25px 30px; border-radius: 8px; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3); width: 90%; max-width: 550px; height: auto; max-height: 90vh; position: relative; display: flex; flex-direction: column; }
        .modal-close-button { position: absolute; top: 10px; right: 15px; font-size: 2em; font-weight: bold; color: #888; cursor: pointer; line-height: 1; z-index: 1010; }
        .modal-close-button:hover { color: #333; }
        #summary-content { text-align: left; margin-top: 15px; }
        #summary-content p { margin: 8px 0; font-size: 1.1em; line-height: 1.5; border-bottom: 1px solid #f0f0f0; padding-bottom: 8px; }
        #summary-content p:last-child { border-bottom: none; }
        #summary-content p strong { color: #6d4c41; display: inline-block; min-width: 150px; }
        #summary-content hr { border: none; border-top: 1px solid #eee; margin: 15px 0; }
        #summary-content h3 { text-align: right; font-size: 1.4em; color: var(--primary-color, #5d4037); margin-top: 20px; }
        #summary-content h3 span { color: #2e7d32; }
        .modal-actions { margin-top: 25px; display: flex; justify-content: space-between; gap: 15px; }
        .modal-actions .button { flex: 1; margin-top: 0; }
        .button.button-secondary { background-color: #aaa; }
        .button.button-secondary:hover { background-color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Zamówienie: <?php echo $service_name; ?></h1>
        <p>Proszę wypełnić poniższe dane, aby opłacić usługę i wygenerować etykietę nadawczą InPost.</p>

        <?php if ($error_message): ?>
            <div class="error"><strong>Wystąpił błąd:</strong><br><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="p24_process.php" method="POST" id="repairForm">
            <!-- Ukryte pola podstawowe -->
            <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
            <input type="hidden" id="base_amount" value="<?php echo $base_price_decimal; ?>">
            <input type="hidden" id="surcharge_amount" value="<?php echo $large_parcel_surcharge; ?>">
            <input type="hidden" name="amount" id="final_amount" value="<?php echo $base_price_decimal; ?>">
            <input type="hidden" name="service_name" value="<?php echo $service_name; ?>">
            
            <!-- Ukryte pola paczkomatu zwrotnego (wypełniane przez JS lub PHP po błędzie) -->
            <input type="hidden" name="return_locker_id" id="selected_locker_id" value="<?php echo $old_locker_id; ?>">
            <input type="hidden" name="return_locker_street" id="selected_locker_street" value="<?php echo $old_locker_street; ?>">
            <input type="hidden" name="return_locker_postcode" id="selected_locker_postcode" value="<?php echo $old_locker_postcode; ?>">
            <input type="hidden" name="return_locker_city" id="selected_locker_city" value="<?php echo $old_locker_city; ?>">

            <!-- Sekcja Dane Nadawcy -->
            <h2>Twoje dane</h2>
            <p>Podaj swoje dane kontaktowe.</p>
            <label for="sender_name">Imię i nazwisko *</label>
            <input type="text" id="sender_name" name="sender_name" value="<?php echo $old_sender_name; ?>" required placeholder="np. Jan Kowalski">
            
            <label for="sender_email">E-mail *</label>
            <input type="email" id="sender_email" name="sender_email" value="<?php echo $old_sender_email; ?>" required placeholder="np. jan.kowalski@example.com">
            
            <label for="sender_phone">Telefon *</label>
            <input type="tel" id="sender_phone" name="sender_phone" 
                   value="<?php echo $old_sender_phone; ?>" 
                   required 
                   pattern="[0-9]{9}" 
                   title="Proszę podać 9 cyfr bez prefixu, spacji i myślników (np. 600123456)."
                   placeholder="np. 600123456">

            <!-- Sekcja Wybór Rozmiaru Paczki -->
            <fieldset class="parcel-size-options">
                <legend>Rozmiar paczki nadawczej *</legend>
                <small>Upewnij się jaki rozmiar będzie miała Twoja przesyłka</small>
                <label>
                    <input type="radio" name="parcel_size" value="medium" <?php echo ($old_parcel_size === 'medium') ? 'checked' : ''; ?> onchange="updateTotalPrice()">
                    Średni (Gabaryt B)
                    <small>Max. 19 x 38 x 64 cm (standardowe pudełko na buty)</small>
                </label>
                <label>
                    <input type="radio" name="parcel_size" value="large" <?php echo ($old_parcel_size === 'large') ? 'checked' : ''; ?> onchange="updateTotalPrice()">
                    Duży (Gabaryt C) (+ <?php echo number_format($large_parcel_surcharge, 2, ',', ' '); ?> PLN)
                    <small>Max. 41 x 38 x 64 cm (większe produkty porównywalne np. do ekspresu do kawy)</small>
                </label>
            </fieldset>

            <!-- Sekcja Paczkomat Zwrotny -->
            <h2>Paczkomat do zwrotu naprawy *</h2>
            <p>Wybierz na mapie Paczkomat lub PaczkoPunkt InPost, do którego odeślemy naprawione obuwie. Wyświetl podpowiedzi wpisując fragment nazwy miasta/ulicy. Zatwierdź klikając przycisk "Wybierz". </p>
            <div id="selected-point-info">
                Nie wybrano punktu.
                <span id="reset-selection-link">Usuń wybór</span>
            </div>
            <div id="geowidget-container-inline">
                <inpost-geowidget
                    id="geowidget-inline"
                    token="<?php echo htmlspecialchars($geowidget_token); ?>"
                    language="pl" country="PL" config="parcelCollect"
                    onpoint="afterPointSelected">
                 </inpost-geowidget>
            </div>
            <div id="locker-error-message">Proszę wybrać Paczkomat lub PaczkoPunkt.</div>

             <!-- Podsumowanie Ceny -->
             <div class="info-box">
                 Całkowity koszt usługi: <strong id="total-price-display"><?php echo $base_price_formatted; ?> PLN</strong>
             </div>

            <!-- Przycisk Submita -->
            <button type="submit" class="button" id="submit-button">Opłać (<span id="button-price"><?php echo $base_price_formatted; ?></span> PLN) i Generuj Etykietę</button>
        </form>
    </div>

    <!-- Okno Modalne Podsumowania (bez zmian) -->
    <div id="summaryModal" class="modal-overlay">
        <div class="modal-content summary-modal">
            <span id="closeSummaryModal" class="modal-close-button">&times;</span>
            <h2>Podsumowanie zamówienia</h2>
            <div id="summary-content">
                <p><strong>Dane Nadawcy:</strong> <span id="summary_sender_name"></span></p>
                <p><strong>E-mail:</strong> <span id="summary_sender_email"></span></p>
                <p><strong>Telefon:</strong> <span id="summary_sender_phone"></span></p>
                <hr>
                <p><strong>Rozmiar Paczki:</strong> <span id="summary_parcel_size"></span></p>
                <p><strong>Paczkomat zwrotny:</strong> <span id="summary_return_locker_wrapper"><span id="summary_return_locker"></span><br><small id="summary_return_locker_address"></small></span></p>
                <hr>
                <h3>Do zapłaty: <span id="summary_total_price"></span> PLN</h3>
            </div>
            <div class="modal-actions">
                <button type="button" id="editButton" class="button button-secondary">Powrót do edycji</button>
                <button type="button" id="confirmPaymentButton" class="button">Potwierdź i przejdź do płatności</button>
            </div>
        </div>
    </div>
    
    <!-- Skrypt JavaScript -->
    <script>
        // Definicje zmiennych (bez zmian)
        const selectedLockerIdInput = document.getElementById('selected_locker_id');
        const selectedPointInfoDiv = document.getElementById('selected-point-info');
        const lockerErrorMessage = document.getElementById('locker-error-message');
        const repairForm = document.getElementById('repairForm');
        const geowidgetElement = document.getElementById('geowidget-inline');
        const resetLink = document.getElementById('reset-selection-link');
        let geowidgetApi = null;
        let lastSelectedPoint = null;
        
        const selectedLockerStreet = document.getElementById('selected_locker_street');
        const selectedLockerPostcode = document.getElementById('selected_locker_postcode');
        const selectedLockerCity = document.getElementById('selected_locker_city');
        
        const baseAmountInput = document.getElementById('base_amount');
        const surchargeInput = document.getElementById('surcharge_amount');
        const finalAmountInput = document.getElementById('final_amount');
        const totalPriceDisplay = document.getElementById('total-price-display');
        const buttonPriceSpan = document.getElementById('button-price');
        const parcelSizeRadios = document.querySelectorAll('input[name="parcel_size"]');
        
        const summaryModal = document.getElementById('summaryModal');
        const closeSummaryModalBtn = document.getElementById('closeSummaryModal');
        const editButton = document.getElementById('editButton');
        const confirmPaymentButton = document.getElementById('confirmPaymentButton');
        const summarySenderName = document.getElementById('summary_sender_name');
        const summarySenderEmail = document.getElementById('summary_sender_email');
        const summarySenderPhone = document.getElementById('summary_sender_phone');
        const summaryParcelSize = document.getElementById('summary_parcel_size');
        const summaryReturnLocker = document.getElementById('summary_return_locker');
        const summaryReturnLockerAddress = document.getElementById('summary_return_locker_address');
        const summaryTotalPrice = document.getElementById('summary_total_price');

        /**
         * Aktualizuje cenę na podstawie wybranego rozmiaru paczki
         */
        function updateTotalPrice() {
            const basePrice = parseFloat(baseAmountInput.value) || 0;
            const surcharge = parseFloat(surchargeInput.value) || 0;
            let finalPrice = basePrice;
            let selectedSize = 'medium';

            parcelSizeRadios.forEach(radio => {
                if (radio.checked && radio.value === 'large') {
                    finalPrice += surcharge;
                    selectedSize = 'large';
                }
            });

            finalAmountInput.value = finalPrice.toFixed(2);
            const formattedPrice = finalPrice.toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if(totalPriceDisplay) totalPriceDisplay.textContent = formattedPrice + ' PLN';
            if(buttonPriceSpan) buttonPriceSpan.textContent = formattedPrice;
            console.log("Updated price. Size:", selectedSize, "Price:", finalPrice);
        }

        /**
         * Inicjalizacja Geowidgetu
         */
        if (geowidgetElement) {
           geowidgetElement.addEventListener('inpost.geowidget.init', (event) => {
               if(event.detail && event.detail.api) { geowidgetApi = event.detail.api; console.log("Geowidget API init."); }
           });
           geowidgetElement.addEventListener('inpost.geowidget.point.select', (event) => {
               const point = event.detail;
               if(point && point.name) { afterPointSelected(point); }
               else { console.error("Event 'point.select' did not return point data:", event.detail); }
           });
        }

        /**
         * Funkcja wywoływana po wybraniu punktu w Geowidgecie
         */
        function afterPointSelected(point) {
            console.log('Selected:', point);
            if (point && point.name) {
                lastSelectedPoint = point; // Zapisz cały obiekt
                selectedLockerIdInput.value = point.name; // Zapisz ID

                let infoHtml = `<strong>Wybrany Paczkomat: ${point.name}</strong>`;
                let addressHtml = '';
                
                // Zapisz dane adresowe do ukrytych pól
                if (point.address_details) {
                    const street = point.address_details.street || '';
                    const building_number = point.address_details.building_number || '';
                    const post_code = point.address_details.post_code || '';
                    const city = point.address_details.city || '';
                    
                    const fullStreet = `${street} ${building_number}`.trim();
                    selectedLockerStreet.value = fullStreet;
                    selectedLockerPostcode.value = post_code;
                    selectedLockerCity.value = city;

                    addressHtml += `${fullStreet}<br>${post_code} ${city}`;
                } else if (point.location_description) { 
                     addressHtml += `${point.location_description}`;
                     selectedLockerStreet.value = ''; selectedLockerPostcode.value = ''; selectedLockerCity.value = '';
                }
                
                infoHtml += addressHtml;
                infoHtml += '<span id="reset-selection-link" style="position: absolute; bottom: 8px; right: 10px; font-size: 0.85em; color: #795548; text-decoration: underline; cursor: pointer;">Usuń wybór</span>';

                selectedPointInfoDiv.innerHTML = infoHtml;
                selectedPointInfoDiv.style.display = 'block';
                selectedPointInfoDiv.classList.add('selected');
                lockerErrorMessage.style.display = 'none';

                const newResetLink = document.getElementById('reset-selection-link');
                if(newResetLink) { newResetLink.addEventListener('click', resetPointSelection); }
            } else {
                 console.error('Invalid point data received.');
                 resetPointSelection();
            }
        }

        /**
         * Funkcja resetująca wybór Paczkomatu
         */
        function resetPointSelection(event) {
             if(event) event.preventDefault();
             
             lastSelectedPoint = null;
             // Wyczyść wszystkie ukryte pola
             selectedLockerIdInput.value = '';
             selectedLockerStreet.value = '';
             selectedLockerPostcode.value = '';
             selectedLockerCity.value = '';

             selectedPointInfoDiv.innerHTML = 'Nie wybrano punktu. <span id="reset-selection-link" style="position: absolute; bottom: 8px; right: 10px; font-size: 0.85em; color: #795548; text-decoration: underline; cursor: pointer; display: none;">Usuń wybór</span>';
             selectedPointInfoDiv.classList.remove('selected');
             lockerErrorMessage.style.display = 'none';

             const currentResetLink = document.getElementById('reset-selection-link');
             if(currentResetLink) { currentResetLink.addEventListener('click', resetPointSelection); }

             if (geowidgetApi && typeof geowidgetApi.clearPoint === 'function') {
                 try { geowidgetApi.clearPoint(); console.log("clearPoint() called."); }
                 catch (e) { console.error("Błąd clearPoint():", e); }
             } else { console.warn("API Geowidgetu lub clearPoint() niedostępne."); }
        }

       // Podpięcie listenera reset przy ładowaniu
       const initialResetLink = document.getElementById('reset-selection-link');
       if(initialResetLink) {
          initialResetLink.addEventListener('click', resetPointSelection);
          if (!selectedLockerIdInput.value) { initialResetLink.style.display = 'none'; }
       }

      /**
       * Walidacja formularza i otwarcie modala podsumowania
       */
      if (repairForm) {
          repairForm.addEventListener('submit', function(event) {
              event.preventDefault(); // Zawsze zatrzymaj domyślne wysłanie
              let isValid = true;

              // 1. Walidacja Paczkomatu
              if (!selectedLockerIdInput.value) {
                  lockerErrorMessage.style.display = 'block';
                  if (geowidgetElement) { geowidgetElement.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                  alert('Proszę wybrać Paczkomat zwrotny na mapie.');
                  isValid = false;
              } else {
                  lockerErrorMessage.style.display = 'none';
              }
              
              // 2. Walidacja pól 'required' HTML5
              if (!repairForm.reportValidity()) {
                  isValid = false;
              }

              // 3. Jeśli wszystko OK, pokaż podsumowanie
              if (isValid) {
                  // Wypełnij dane modala
                  summarySenderName.textContent = document.getElementById('sender_name').value;
                  summarySenderEmail.textContent = document.getElementById('sender_email').value;
                  summarySenderPhone.textContent = document.getElementById('sender_phone').value;
                  
                  const selectedSizeRadio = document.querySelector('input[name="parcel_size"]:checked');
                  summaryParcelSize.textContent = selectedSizeRadio.value === 'large' ? 'Duży (Gabaryt C)' : 'Średni (Gabaryt B)';
                  
                  // Użyj zapisanych danych obiektu 'point' LUB danych z ukrytych pól (po błędzie)
                  if(lastSelectedPoint && lastSelectedPoint.name) {
                      summaryReturnLocker.textContent = lastSelectedPoint.name;
                      let addressHtml = '';
                      if (lastSelectedPoint.address_details) {
                           addressHtml += `${lastSelectedPoint.address_details.street || ''} ${lastSelectedPoint.address_details.building_number || ''}, `;
                           addressHtml += `${lastSelectedPoint.address_details.post_code || ''} ${lastSelectedPoint.address_details.city || ''}`;
                      } else if (lastSelectedPoint.location_description) { addressHtml += `${lastSelectedPoint.location_description}`; }
                      summaryReturnLockerAddress.innerHTML = addressHtml;
                  } else {
                       // Fallback na dane z ukrytych pól (po błędzie)
                       summaryReturnLocker.textContent = selectedLockerIdInput.value;
                       let addressHtml = `${selectedLockerStreet.value}, ${selectedLockerPostcode.value} ${selectedLockerCity.value}`;
                       summaryReturnLockerAddress.innerHTML = addressHtml.replace(/^, |^ |^,/, '').trim(); // Wyczyść, jeśli puste
                  }

                  const finalPrice = document.getElementById('final_amount').value;
                  summaryTotalPrice.textContent = parseFloat(finalPrice).toLocaleString('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                  
                  // Pokaż modal
                  summaryModal.classList.add('visible');
              }
          });
      }

      /**
       * Listenery dla przycisków modala podsumowania
       */
      if (closeSummaryModalBtn) {
          closeSummaryModalBtn.addEventListener('click', () => {
              summaryModal.classList.remove('visible');
          });
      }
      
      if (editButton) {
          editButton.addEventListener('click', () => {
              summaryModal.classList.remove('visible');
          });
      }
      
      if (confirmPaymentButton) {
          confirmPaymentButton.addEventListener('click', () => {
              confirmPaymentButton.disabled = true;
              confirmPaymentButton.textContent = 'Przetwarzanie...';
              
              // Teraz wyślij formularz
              console.log('Potwierdzono, wysyłanie formularza...');
              repairForm.submit(); 
          });
      }
      
      if (summaryModal) {
          summaryModal.addEventListener('click', (event) => {
              if (event.target === summaryModal) { // Kliknięto tło
                  summaryModal.classList.remove('visible');
              }
          });
      }

       /**
        * Logika ponownego wypełniania (Repopulate)
        */
       document.addEventListener('DOMContentLoaded', () => {
            // 1. Odtwórz informacje o wybranym paczkomacie (jeśli są)
            const repopulateData = <?php echo $js_repopulate_data; ?>;
            if (repopulateData && repopulateData.id) {
                console.log("Odtwarzanie danych Geowidget:", repopulateData);
                
                 // Prostsze odtworzenie - po prostu wypełnij div
                 let infoHtml = `<strong>Wybrany Paczkomat: ${repopulateData.id}</strong>`;
                 let addressHtml = `${repopulateData.street}, ${repopulateData.postcode} ${repopulateData.city}`;
                 addressHtml = addressHtml.replace(/^, |^ |^,/, '').trim(); // Wyczyść, jeśli puste
                 
                 if (addressHtml.length > 2) { // Sprawdź czy adres jest sensowny
                    infoHtml += `<br>${addressHtml}`;
                 }
                 
                 infoHtml += '<span id="reset-selection-link" style="position: absolute; bottom: 8px; right: 10px; font-size: 0.85em; color: #795548; text-decoration: underline; cursor: pointer;">Usuń wybór</span>';
                 
                 selectedPointInfoDiv.innerHTML = infoHtml;
                 selectedPointInfoDiv.style.display = 'block';
                 selectedPointInfoDiv.classList.add('selected');
                 
                 // Podepnij listener do linku reset
                 const newResetLink = document.getElementById('reset-selection-link');
                 if(newResetLink) { newResetLink.addEventListener('click', resetPointSelection); }
            }

            // 2. Zaktualizuj cenę (na wypadek, gdyby wybrano 'large')
            updateTotalPrice();
       });

    </script>
</body>
</html>

