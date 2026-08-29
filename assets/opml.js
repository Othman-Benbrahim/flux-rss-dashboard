/**
 * assets/opml.js — import OPML avec écran de sélection.
 *
 * Deux temps : api_opml_parse.php lit le fichier et renvoie l'arborescence
 * sans rien écrire ; api_opml_import.php crée les widgets une fois la
 * sélection faite.
 */
(function () {
    'use strict';

    var parsed = null;

    function el(id) {
        return document.getElementById(id);
    }

    function setStatus(message, isError) {
        var node = el('opml-status');
        node.textContent = message || '';
        node.style.color = isError ? '#c62828' : '#666';
    }

    window.openOpmlModal = function () {
        parsed = null;
        el('opml-file').value = '';
        el('opml-selection').innerHTML = '';
        el('opml-selection').style.display = 'none';
        el('opml-actions').style.display = 'none';
        setStatus('');
        el('opml-modal').style.display = 'flex';
    };

    window.closeOpmlModal = function () {
        el('opml-modal').style.display = 'none';
    };

    // --- Étape 1 : lecture du fichier --------------------------------------
    window.uploadOpml = async function () {
        var file = el('opml-file').files[0];
        if (!file) {
            setStatus('Choisissez un fichier OPML.', true);
            return;
        }

        setStatus('Lecture du fichier…');

        var formData = new FormData();
        formData.append('opml_file', file);
        formData.append('csrf_token', CSRF_TOKEN);

        try {
            var response = await api('api_opml_parse.php', { method: 'POST', body: formData });
            var data = await response.json();

            if (data.error) {
                setStatus(data.error, true);
                return;
            }

            parsed = data;
            renderSelection(data);
        } catch (error) {
            console.error('api_opml_parse.php :', error);
            setStatus('Lecture impossible : ' + error.message, true);
        }
    };

    // --- Étape 2 : écran de sélection --------------------------------------
    function renderSelection(data) {
        var container = el('opml-selection');
        var html = '';
        var index = 0;

        data.folders.forEach(function (folder, folderIndex) {
            var available = folder.feeds.filter(function (f) { return !f.existing; }).length;

            html += '<div class="opml-folder">';
            html += '<div class="opml-folder-head">';
            html += '<label><input type="checkbox" class="opml-folder-check" data-folder="' + folderIndex + '"'
                 + (available ? '' : ' disabled') + '> <strong>' + esc(folder.name) + '</strong></label>';
            html += '<span class="opml-count">' + folder.feeds.length + ' flux'
                 + (available < folder.feeds.length
                     ? ' · ' + (folder.feeds.length - available) + ' déjà présent(s)'
                     : '') + '</span>';
            html += '</div>';

            folder.feeds.forEach(function (feed) {
                html += '<label class="opml-feed' + (feed.existing ? ' opml-feed-existing' : '') + '">';
                html += '<input type="checkbox" class="opml-feed-check" data-folder="' + folderIndex + '"'
                     + ' data-index="' + index + '"' + (feed.existing ? ' disabled' : '') + '>';
                html += '<span class="opml-feed-title">' + esc(feed.title) + '</span>';
                html += '<span class="opml-feed-url">' + esc(feed.url) + '</span>';
                if (feed.existing) {
                    html += '<span class="opml-badge">déjà présent</span>';
                }
                html += '</label>';

                feed._index = index;
                index++;
            });

            html += '</div>';
        });

        container.innerHTML = html;
        container.style.display = 'block';
        el('opml-actions').style.display = 'flex';

        // Cases de dossier : cochent ou décochent leur groupe.
        container.querySelectorAll('.opml-folder-check').forEach(function (box) {
            box.addEventListener('change', function () {
                var selector = '.opml-feed-check[data-folder="' + box.dataset.folder + '"]:not([disabled])';
                container.querySelectorAll(selector).forEach(function (feedBox) {
                    feedBox.checked = box.checked;
                });
                updateCount();
            });
        });

        container.querySelectorAll('.opml-feed-check').forEach(function (box) {
            box.addEventListener('change', updateCount);
        });

        var message = data.total + ' flux trouvés.';
        if (data.truncated) {
            message += ' Fichier tronqué : seuls les 500 premiers sont proposés.';
        }
        setStatus(message);
        updateCount();
    }

    function selectedFeeds() {
        if (!parsed) return [];

        var flat = [];
        parsed.folders.forEach(function (folder) {
            folder.feeds.forEach(function (feed) { flat.push(feed); });
        });

        var chosen = [];
        document.querySelectorAll('.opml-feed-check:checked').forEach(function (box) {
            var feed = flat[Number(box.dataset.index)];
            if (feed) chosen.push({ title: feed.title, url: feed.url });
        });

        return chosen;
    }

    function updateCount() {
        var count = document.querySelectorAll('.opml-feed-check:checked').length;
        var button = el('opml-import-btn');

        button.textContent = count ? 'Importer ' + count + ' flux' : 'Importer';
        button.disabled = count === 0 || count > 60;

        if (count > 60) {
            setStatus('Maximum 60 flux par import. Décochez-en ' + (count - 60) + '.', true);
        }
    }

    window.opmlSelectAll = function (state) {
        document.querySelectorAll('.opml-feed-check:not([disabled])').forEach(function (box) {
            box.checked = state;
        });
        document.querySelectorAll('.opml-folder-check:not([disabled])').forEach(function (box) {
            box.checked = state;
        });
        updateCount();
    };

    // --- Étape 3 : import ---------------------------------------------------
    window.importOpml = async function () {
        var feeds = selectedFeeds();
        if (!feeds.length) return;

        el('opml-import-btn').disabled = true;
        setStatus('Import en cours…');

        try {
            var response = await api('api_opml_import.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tab_id: Number(currentTabId), feeds: feeds })
            });
            var data = await response.json();

            if (data.error) {
                setStatus(data.error, true);
                el('opml-import-btn').disabled = false;
                return;
            }

            closeOpmlModal();
            await loadWidgets(currentTabId);
        } catch (error) {
            console.error('api_opml_import.php :', error);
            setStatus('Import impossible : ' + error.message, true);
            el('opml-import-btn').disabled = false;
        }
    };
})();
