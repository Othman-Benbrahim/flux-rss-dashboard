/**
 * assets/background.js — fond d'écran : couleur ou image.
 *
 * Couleur et image sont exclusives, côté serveur comme ici : poser l'une
 * efface l'autre. Un troisième état, « aucun fond », les efface toutes deux.
 */
(function () {
    'use strict';

    // Teintes reprises des en-têtes de widgets, avec une composante alpha
    // pour rester douces sous les widgets.
    var DEFAULT_PALETTE = [
        '#e8e4c7cc', '#d2e1b4cc', '#a2d2dfcc', '#c7a3c3cc', '#e6b2c6cc',
        '#eac59bcc', '#e4dfd0cc', '#d9d3accc', '#b8d3c1cc', '#e6c8cecc'
    ];

    var customPalette = [];
    var current = { image: null, color: null };

    function el(id) {
        return document.getElementById(id);
    }

    function setStatus(message, isError) {
        var node = el('bg-status');
        if (!node) return;
        node.textContent = message || '';
        node.style.color = isError ? '#c62828' : '#666';
    }

    // Une couleur ne quitte jamais ce filtre avant d'entrer dans une propriété CSS.
    function validColor(color) {
        return /^#[0-9a-f]{6}([0-9a-f]{2})?$/i.test(String(color || ''));
    }

    /**
     * Applique l'état courant au document.
     */
    function applyBackground() {
        if (current.image) {
            document.body.style.backgroundImage = 'url("' + encodeURI(current.image) + '")';
            document.body.style.backgroundAttachment = 'fixed';
            document.body.style.backgroundSize = 'cover';
            document.body.style.backgroundColor = '';
        } else {
            document.body.style.backgroundImage = 'none';
            document.body.style.backgroundColor = validColor(current.color) ? current.color : '';
        }
    }

    window.applyBackgroundState = applyBackground;

    /**
     * Lit les réglages et met à jour l'affichage et le panneau.
     */
    window.loadBackground = async function () {
        try {
            var response = await fetch('api_user_settings.php');
            var settings = await response.json();

            current.image = settings ? safeUrl(settings.background_image_url) || null : null;
            current.color = settings && validColor(settings.background_color) ? settings.background_color : null;
            customPalette = (settings && Array.isArray(settings.custom_colors))
                ? settings.custom_colors.filter(validColor) : [];

            applyBackground();
            renderPalette();
            renderImageState();
        } catch (error) {
            console.error('api_user_settings.php :', error);
        }
    };

    // -----------------------------------------------------------------------
    // Palette
    // -----------------------------------------------------------------------
    function renderPalette() {
        var container = el('bg-palette');
        if (!container) return;

        var html = '';

        html += '<button type="button" class="bg-swatch bg-swatch-none' + (!current.color && !current.image ? ' active' : '')
             + '" data-color="" title="Aucun fond">✖</button>';

        DEFAULT_PALETTE.forEach(function (color) {
            html += swatchHtml(color, false);
        });

        customPalette.forEach(function (color) {
            html += swatchHtml(color, true);
        });

        container.innerHTML = html;

        container.querySelectorAll('.bg-swatch').forEach(function (swatch) {
            swatch.addEventListener('click', function (event) {
                if (event.target.closest('.bg-swatch-remove')) return; // clic sur la croix
                setColor(swatch.dataset.color);
            });
        });

        container.querySelectorAll('.bg-swatch-remove').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                removeCustomColor(button.parentElement.dataset.color);
            });
        });
    }

    function swatchHtml(color, removable) {
        var active = (current.color || '').toLowerCase() === color.toLowerCase() && !current.image;

        return '<span class="bg-swatch' + (active ? ' active' : '') + '" data-color="' + esc(color)
             + '" title="' + esc(color) + '" style="background-color:' + esc(color) + ';">'
             + (removable ? '<button type="button" class="bg-swatch-remove" title="Retirer de la palette">✖</button>' : '')
             + '</span>';
    }

    async function post(payload) {
        var response = await api('api_background.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        return response.json();
    }

    async function setColor(color) {
        if (color !== '' && !validColor(color)) return;

        try {
            var data = await post({ color: color === '' ? null : color });
            if (data.error) { setStatus(data.error, true); return; }

            current.color = data.color || null;
            current.image = null; // le serveur a effacé l'image
            applyBackground();
            renderPalette();
            renderImageState();
            setStatus(current.color ? 'Couleur appliquée.' : 'Fond réinitialisé.');
        } catch (error) {
            setStatus('Enregistrement impossible : ' + error.message, true);
        }
    }

    window.addCustomColor = async function () {
        var hex = el('bg-color-input').value;
        var alpha = Math.round((Number(el('bg-alpha-input').value) / 100) * 255);
        var color = hex + ('0' + alpha.toString(16)).slice(-2);

        if (!validColor(color)) { setStatus('Couleur invalide.', true); return; }

        try {
            var data = await post({ add_color: color });
            if (data.error) { setStatus(data.error, true); return; }

            customPalette = data.palette || [];
            renderPalette();
            setStatus('Couleur ajoutée à la palette.');
        } catch (error) {
            setStatus('Ajout impossible : ' + error.message, true);
        }
    };

    async function removeCustomColor(color) {
        try {
            var data = await post({ remove_color: color });
            if (data.error) { setStatus(data.error, true); return; }

            customPalette = data.palette || [];
            renderPalette();
            setStatus('Couleur retirée de la palette.');
        } catch (error) {
            setStatus('Suppression impossible : ' + error.message, true);
        }
    }

    // -----------------------------------------------------------------------
    // Image
    // -----------------------------------------------------------------------
    function renderImageState() {
        var info = el('bg-image-state');
        if (!info) return;

        info.textContent = current.image ? 'Image active.' : 'Aucune image.';
        el('bg-image-delete').style.display = current.image ? 'inline-block' : 'none';
    }

    window.saveBackground = async function () {
        var file = el('background-input').files[0];
        if (!file) { setStatus('Choisissez d\'abord un fichier image.', true); return; }

        // Contrôle avant envoi : plus lisible qu'un refus serveur après coup.
        if (file.size > 5 * 1024 * 1024) {
            setStatus('Image trop lourde (' + (file.size / 1048576).toFixed(1) + ' Mo). Maximum 5 Mo.', true);
            return;
        }

        var formData = new FormData();
        formData.append('background_file', file);
        formData.append('csrf_token', CSRF_TOKEN);

        setStatus('Envoi en cours…');

        try {
            var response = await api('api_upload_background.php', { method: 'POST', body: formData });
            var body = await response.text();
            var data;

            try {
                data = JSON.parse(body);
            } catch (e) {
                setStatus('Réponse inattendue du serveur (HTTP ' + response.status + ') : '
                    + body.slice(0, 160), true);
                return;
            }

            if (data.error) { setStatus(data.error, true); return; }

            el('background-input').value = '';
            await loadBackground();
            setStatus('Image de fond appliquée.');
        } catch (error) {
            setStatus('Envoi impossible : ' + error.message, true);
        }
    };

    window.deleteBackgroundImage = async function () {
        try {
            var response = await api('api_upload_background.php', { method: 'DELETE' });
            var data = await response.json();

            if (data.error) { setStatus(data.error, true); return; }

            await loadBackground();
            setStatus('Image supprimée.');
        } catch (error) {
            setStatus('Suppression impossible : ' + error.message, true);
        }
    };

    // -----------------------------------------------------------------------
    // Panneau
    // -----------------------------------------------------------------------
    window.openBackgroundModal = function () {
        setStatus('');
        renderPalette();
        renderImageState();
        el('background-modal').style.display = 'flex';
    };

    window.closeBackgroundModal = function () {
        el('background-modal').style.display = 'none';
    };

    document.addEventListener('DOMContentLoaded', function () {
        var alpha = el('bg-alpha-input');
        if (alpha) {
            alpha.addEventListener('input', function () {
                el('bg-alpha-value').textContent = alpha.value + ' %';
            });
        }
    });
})();
