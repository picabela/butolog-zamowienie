<?php
$default_quote_subject = get_default_quote_subject();
$default_quote_body = get_default_quote_body();
?>

<fieldset id="quote-template-settings" class="quote-template-settings">
    <legend>Domyślny szablon wyceny e-mail</legend>
    <div class="setting-group">
        <label for="global_quote_subject">Domyślny temat e-maila z wyceną</label>
        <input type="text" id="global_quote_subject" name="global_quote_subject" value="<?php echo get_current_setting_value('global_quote_subject', $current_settings, htmlspecialchars($default_quote_subject)); ?>">

        <label for="global_quote_body">Domyślna treść e-maila z wyceną</label>
        <div class="editor-mode-switch quote-template-mode-switch" role="group" aria-label="Tryb edytora domyślnego szablonu e-mail">
            <button type="button" class="editor-mode-button is-active" data-template-editor-mode="visual" aria-pressed="true">Edytor wizualny</button>
            <button type="button" class="editor-mode-button" data-template-editor-mode="html" aria-pressed="false">HTML</button>
        </div>
        <div id="global_quote_body_visual_tools" class="visual-editor-toolbar" aria-label="Narzędzia edytora wizualnego szablonu">
            <button type="button" data-command="bold"><strong>B</strong></button>
            <button type="button" data-command="italic"><em>I</em></button>
            <button type="button" data-command="underline"><u>U</u></button>
            <button type="button" data-command="formatBlock" data-value="<h2>">Nagłówek</button>
            <button type="button" data-command="formatBlock" data-value="<p>">Akapit</button>
            <button type="button" data-command="insertUnorderedList">Lista</button>
            <button type="button" data-command="createLink">Link</button>
        </div>
        <div id="global_quote_body_visual_editor" class="visual-email-editor quote-template-visual-editor" contenteditable="true" aria-label="Wizualna treść domyślnego szablonu e-mail"></div>
        <textarea id="global_quote_body" name="global_quote_body" class="monospace quote-template-area" aria-label="Domyślna treść e-maila z wyceną w HTML"><?php echo get_current_setting_value('global_quote_body', $current_settings, htmlspecialchars($default_quote_body)); ?></textarea>

        <small>Domyślnie edytujesz szablon wizualnie. Przełącz na HTML, aby ręcznie poprawić znaczniki. Ten szablon jest używany dla nowych wycen i wysyłek bez zapisanej, indywidualnie edytowanej treści. Dostępne zmienne: <code>{{EMAIL_KLIENTA}}</code>, <code>{{NAZWA_USLUGI}}</code>, <code>{{CENA}}</code>, <code>{{LINK_DO_PLATNOSCI}}</code>.</small>
    </div>
</fieldset>
