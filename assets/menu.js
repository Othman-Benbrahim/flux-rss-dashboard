/**
 * assets/menu.js — menus déroulants de l'en-tête.
 *
 * Chargé après le script principal d'index.php : les fonctions globales
 * (addWidget, openBackgroundModal…) sont donc déjà définies.
 */
(function () {
    'use strict';

    var openMenu = null;

    function panelOf(menu) {
        return menu.querySelector('.menu-panel');
    }

    function close(menu) {
        if (!menu) return;
        menu.classList.remove('open');
        var trigger = menu.querySelector('.menu-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        if (openMenu === menu) openMenu = null;
    }

    function closeAll() {
        document.querySelectorAll('[data-menu].open').forEach(close);
        openMenu = null;
    }

    function open(menu) {
        closeAll();
        menu.classList.add('open');
        var trigger = menu.querySelector('.menu-trigger');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        openMenu = menu;

        // Si le panneau déborde à droite, on l'aligne sur le bord droit.
        var panel = panelOf(menu);
        if (panel) {
            panel.style.left = '';
            panel.style.right = '';
            var rect = panel.getBoundingClientRect();
            if (rect.right > window.innerWidth - 8) {
                panel.style.left = 'auto';
                panel.style.right = '0';
            }
        }
    }

    function toggle(menu) {
        if (menu.classList.contains('open')) {
            close(menu);
        } else {
            open(menu);
        }
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-menu] > .menu-trigger');

        if (trigger) {
            event.preventDefault();
            toggle(trigger.parentElement);
            return;
        }

        // Un clic sur une entrée referme le menu ; l'action suit son cours.
        if (event.target.closest('.menu-panel')) {
            closeAll();
            return;
        }

        closeAll(); // clic à l'extérieur
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openMenu) {
            var trigger = openMenu.querySelector('.menu-trigger');
            closeAll();
            if (trigger) trigger.focus();
            return;
        }

        if (!openMenu) return;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            var items = Array.prototype.slice.call(
                openMenu.querySelectorAll('.menu-panel button:not([disabled])')
            );
            if (!items.length) return;

            var current = items.indexOf(document.activeElement);
            var next = event.key === 'ArrowDown'
                ? (current + 1) % items.length
                : (current <= 0 ? items.length - 1 : current - 1);
            items[next].focus();
        }
    });

    // Ouverture au clavier depuis le déclencheur.
    document.addEventListener('keydown', function (event) {
        var trigger = event.target.closest('[data-menu] > .menu-trigger');
        if (!trigger) return;

        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open(trigger.parentElement);
            var first = trigger.parentElement.querySelector('.menu-panel button');
            if (first) first.focus();
        }
    });
})();
