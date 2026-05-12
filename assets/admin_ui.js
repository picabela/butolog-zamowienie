document.addEventListener('DOMContentLoaded', function () {
    const settingsForm = document.querySelector('.settings-form');

    if (!settingsForm) {
        return;
    }

    settingsForm.querySelectorAll(':scope > fieldset').forEach(function (fieldset, index) {
        const legend = fieldset.querySelector(':scope > legend');
        const title = legend ? legend.textContent.trim() : 'Ustawienia';
        const details = document.createElement('details');
        details.className = 'settings-card';
        details.open = index === 0;

        const summary = document.createElement('summary');
        summary.textContent = title;

        fieldset.parentNode.insertBefore(details, fieldset);
        details.appendChild(summary);
        details.appendChild(fieldset);
    });

    settingsForm.addEventListener('invalid', function (event) {
        let panel = event.target.closest('details');

        while (panel) {
            panel.open = true;
            panel = panel.parentElement.closest('details');
        }
    }, true);

    const templateTextarea = document.getElementById('global_quote_body');
    const templateVisualEditor = document.getElementById('global_quote_body_visual_editor');
    const templateToolbar = document.getElementById('global_quote_body_visual_tools');
    const templateModeButtons = document.querySelectorAll('[data-template-editor-mode]');

    if (!templateTextarea || !templateVisualEditor || !templateToolbar || !templateModeButtons.length) {
        return;
    }

    let currentTemplateMode = 'html';

    function htmlToTemplateVisual() {
        templateVisualEditor.innerHTML = templateTextarea.value;
    }

    function templateVisualToHtml() {
        templateTextarea.value = templateVisualEditor.innerHTML.trim();
    }

    function setTemplateEditorMode(mode) {
        if (mode === currentTemplateMode) {
            return;
        }

        if (mode === 'html') {
            templateVisualToHtml();
            templateVisualEditor.style.display = 'none';
            templateToolbar.style.display = 'none';
            templateTextarea.style.display = 'block';
        } else {
            htmlToTemplateVisual();
            templateTextarea.style.display = 'none';
            templateVisualEditor.style.display = 'block';
            templateToolbar.style.display = 'flex';
        }

        currentTemplateMode = mode;

        templateModeButtons.forEach(function (button) {
            const isActive = button.dataset.templateEditorMode === mode;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    templateModeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            setTemplateEditorMode(button.dataset.templateEditorMode);
        });
    });

    templateToolbar.addEventListener('click', function (event) {
        const button = event.target.closest('button[data-command]');
        if (!button) {
            return;
        }

        const command = button.dataset.command;
        let value = button.dataset.value || null;

        if (command === 'createLink') {
            value = window.prompt('Podaj adres linku:', 'https://');
            if (!value) {
                return;
            }
        }

        templateVisualEditor.focus();
        document.execCommand(command, false, value);
        templateVisualToHtml();
    });

    templateVisualEditor.addEventListener('input', templateVisualToHtml);

    settingsForm.addEventListener('submit', function () {
        if (currentTemplateMode === 'visual') {
            templateVisualToHtml();
        }
    });

    htmlToTemplateVisual();
    setTemplateEditorMode('visual');
});
