/**
 * assets/read-state.js — suivi des articles lus.
 *
 * Un article est marqué lu quand on clique son lien, ou en bloc depuis
 * l'en-tête. L'état est rendu par la classe .rss-read, appliquée au rendu par
 * renderWidget() à partir du champ « read » renvoyé par api_rss.php.
 */
(function () {
    'use strict';

    /**
     * Envoie une liste d'adresses à marquer comme lues.
     */
    async function markRead(urls) {
        if (!isLoggedIn || !urls.length) return false;

        try {
            var response = await api('api_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ urls: urls })
            });
            var data = await response.json();

            if (data.error) {
                console.warn('api_read.php :', data.error);
                return false;
            }
            return true;
        } catch (error) {
            console.error('api_read.php :', error);
            return false;
        }
    }

    window.markArticleRead = markRead;

    /**
     * Adresses de tous les articles actuellement affichés dans l'onglet.
     */
    function visibleArticleUrls() {
        var urls = [];
        document.querySelectorAll('.rss-title-link').forEach(function (link) {
            var href = link.getAttribute('href');
            if (href && /^https?:\/\//i.test(href) && urls.indexOf(href) === -1) {
                urls.push(href);
            }
        });
        return urls;
    }

    /**
     * « Tout marquer comme lu » : porte sur l'onglet visible, pas sur le
     * tableau entier — un bouton qui efface l'état d'onglets qu'on ne voit pas
     * serait une mauvaise surprise.
     */
    window.markAllRead = async function () {
        if (!isLoggedIn) return;

        var urls = visibleArticleUrls();
        if (!urls.length) {
            alert('Aucun article affiché dans cet onglet.');
            return;
        }

        // Le plafond serveur est de 500 adresses par appel.
        var batches = [];
        for (var i = 0; i < urls.length; i += 500) {
            batches.push(urls.slice(i, i + 500));
        }

        var ok = true;
        for (var b = 0; b < batches.length; b++) {
            if (!await markRead(batches[b])) ok = false;
        }

        if (ok) {
            document.querySelectorAll('.rss-title-link').forEach(function (link) {
                link.classList.add('rss-read');
            });
        } else {
            alert('Le marquage a échoué. Rechargez la page et réessayez.');
        }
    };

    /**
     * Remise à zéro : tous les articles redeviennent non lus.
     */
    window.markAllUnread = async function () {
        if (!isLoggedIn) return;
        if (!confirm('Remettre tous les articles en « non lu » ?')) return;

        try {
            var response = await api('api_read.php', { method: 'DELETE' });
            var data = await response.json();

            if (data.error) {
                alert(data.error);
                return;
            }

            document.querySelectorAll('.rss-title-link').forEach(function (link) {
                link.classList.remove('rss-read');
            });
        } catch (error) {
            console.error('api_read.php :', error);
        }
    };

    /**
     * Clic sur un article : marquage immédiat, sans attendre la réponse
     * serveur — le lien s'ouvre dans un autre onglet de toute façon.
     */
    document.addEventListener('click', function (event) {
        var link = event.target.closest('.rss-title-link');
        // Un épisode sans lien est rendu en <span> : rien à marquer.
        if (!link || !isLoggedIn || link.tagName !== 'A') return;

        var href = link.getAttribute('href');
        if (!href || !/^https?:\/\//i.test(href)) return;

        link.classList.add('rss-read');
        markRead([href]);
    });
})();
