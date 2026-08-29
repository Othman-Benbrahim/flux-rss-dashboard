/**
 * assets/filters.js — barre de filtres.
 *
 * Tout se joue sur ce qui est déjà chargé dans la page : les widgets présents
 * dans l'onglet courant et les articles qu'ils affichent. Aucun appel réseau,
 * aucune table d'articles.
 *
 * Deux portées distinctes :
 *   · tag, source et type filtrent les WIDGETS (le flux porte l'étiquette) ;
 *   · la recherche filtre les ARTICLES à l'intérieur des widgets retenus,
 *     et masque ceux qui ne contiennent plus rien.
 *
 * Le moteur de gridstack n'est jamais touché : la vue filtrée est une
 * présentation alternative (colonnes CSS), obtenue par une classe sur le
 * conteneur. Sortir du filtre restaure donc exactement la grille d'origine,
 * sans risque d'écriture en base.
 */
(function () {
    'use strict';

    var state = { query: '', tag: '', source: '', type: '' };
    var debounce = null;

    function el(id) {
        return document.getElementById(id);
    }

    function gridEl() {
        return document.querySelector('.grid-stack');
    }

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, ''); // accents ignorés dans la recherche
    }

    function isFiltering() {
        return state.query !== '' || state.tag !== '' || state.source !== '' || state.type !== '';
    }

    // -----------------------------------------------------------------------
    // Construction de la barre à partir des widgets présents
    // -----------------------------------------------------------------------
    window.rebuildFilterBar = function () {
        var bar = el('filter-bar');
        if (!bar) return;

        var items = document.querySelectorAll('.grid-stack-item[data-widget-type="rss"]');

        // Sans aucun flux dans l'onglet, la barre n'a rien à filtrer.
        bar.style.display = items.length ? 'block' : 'none';
        if (!items.length) {
            resetFilters(true);
            return;
        }

        var tags = {};
        var sources = {};

        items.forEach(function (item) {
            (item.dataset.tags || '').split('|').forEach(function (tag) {
                if (tag) tags[tag] = item.dataset.tagLabels
                    ? (JSON.parse(item.dataset.tagLabels)[tag] || tag)
                    : tag;
            });

            var source = item.dataset.source || '';
            if (source) sources[source] = item.dataset.sourceLabel || source;
        });

        renderTagPills(tags);
        renderSourceOptions(sources);
        applyFilters();
    };

    function renderTagPills(tags) {
        var container = el('filter-tags');
        var names = Object.keys(tags).sort();

        var html = '<button type="button" class="filter-pill' + (state.tag === '' ? ' active' : '')
                 + '" data-tag="">Tous</button>';

        names.forEach(function (key) {
            html += '<button type="button" class="filter-pill' + (state.tag === key ? ' active' : '')
                 + '" data-tag="' + esc(key) + '">' + esc(tags[key]) + '</button>';
        });

        container.innerHTML = html;

        // Le tag sélectionné a pu disparaître en changeant d'onglet.
        if (state.tag && names.indexOf(state.tag) === -1) {
            state.tag = '';
            container.querySelector('.filter-pill').classList.add('active');
        }

        container.querySelectorAll('.filter-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                state.tag = pill.dataset.tag;
                container.querySelectorAll('.filter-pill').forEach(function (p) {
                    p.classList.toggle('active', p === pill);
                });
                applyFilters();
            });
        });
    }

    function renderSourceOptions(sources) {
        var select = el('filter-source');
        var keys = Object.keys(sources).sort(function (a, b) {
            return sources[a].localeCompare(sources[b]);
        });

        var html = '<option value="">Toutes les sources</option>';
        keys.forEach(function (key) {
            html += '<option value="' + esc(key) + '">' + esc(sources[key]) + '</option>';
        });

        select.innerHTML = html;

        if (state.source && keys.indexOf(state.source) === -1) {
            state.source = '';
        }
        select.value = state.source;
    }

    // -----------------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------------
    function widgetMatches(item) {
        var type = item.dataset.widgetType;

        // Un filtre thématique n'a de sens que pour un flux : les notes,
        // favoris et widgets libres sortent de la vue tant qu'il est actif.
        if (state.tag || state.source || state.type) {
            if (type !== 'rss') return false;
        }

        if (state.tag) {
            var tags = (item.dataset.tags || '').split('|');
            if (tags.indexOf(state.tag) === -1) return false;
        }

        if (state.source && item.dataset.source !== state.source) return false;
        if (state.type && (item.dataset.feedType || 'article') !== state.type) return false;

        return true;
    }

    /**
     * Filtre les articles d'un widget. Renvoie le nombre de restants.
     */
    function filterArticles(item, needle) {
        var articles = item.querySelectorAll('.rss-item, .rss-photo-link');

        if (!needle) {
            articles.forEach(function (a) { a.classList.remove('filtered-out'); });
            return articles.length;
        }

        var kept = 0;
        articles.forEach(function (article) {
            var haystack = normalize(article.innerText) + ' ' + normalize(article.getAttribute('title') || '');
            var hit = haystack.indexOf(needle) !== -1;
            article.classList.toggle('filtered-out', !hit);
            if (hit) kept++;
        });

        return kept;
    }

    function applyFilters() {
        var container = gridEl();
        if (!container) return;

        var needle = normalize(state.query);
        var items = document.querySelectorAll('.grid-stack-item');
        var visible = 0;

        items.forEach(function (item) {
            var show = widgetMatches(item);

            if (show && needle) {
                var title = normalize(item.querySelector('.widget-header span')
                    ? item.querySelector('.widget-header span').innerText : '');
                var titleHit = title.indexOf(needle) !== -1;
                var kept = filterArticles(item, titleHit ? '' : needle);

                // Un widget sans article et sans titre correspondant sort de la vue,
                // sauf s'il n'a pas d'articles du tout (note, favoris…) : dans ce cas
                // on cherche dans son contenu.
                if (!titleHit && kept === 0) {
                    var hasArticles = item.querySelectorAll('.rss-item, .rss-photo-link').length > 0;
                    show = hasArticles ? false : normalize(item.innerText).indexOf(needle) !== -1;
                }
            } else if (show) {
                filterArticles(item, '');
            }

            item.classList.toggle('filtered-out', !show);
            if (show) visible++;
        });

        var active = isFiltering();
        container.classList.toggle('filtering', active);

        // En vue filtrée, le glisser-déposé n'aurait aucun sens : les positions
        // affichées ne sont plus celles de la grille. On délègue à
        // syncGridEditability, qui tient compte aussi de la largeur d'écran —
        // sinon quitter un filtre réactiverait le déplacement en mode replié.
        if (typeof syncGridEditability === 'function') {
            syncGridEditability();
        } else if (typeof grid !== 'undefined' && grid) {
            grid.enableMove(!active && isLoggedIn);
            grid.enableResize(!active && isLoggedIn);
        }

        el('filter-empty').style.display = (active && visible === 0) ? 'block' : 'none';
        el('filter-reset').style.display = active ? 'inline-block' : 'none';
    }

    function resetFilters(silent) {
        state = { query: '', tag: '', source: '', type: '' };

        if (el('filter-search')) el('filter-search').value = '';
        if (el('filter-source')) el('filter-source').value = '';
        if (el('filter-type')) el('filter-type').value = '';

        document.querySelectorAll('#filter-tags .filter-pill').forEach(function (p, i) {
            p.classList.toggle('active', i === 0);
        });

        if (!silent) applyFilters();
    }

    window.resetFilters = resetFilters;

    // -----------------------------------------------------------------------
    // Écouteurs
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        var search = el('filter-search');
        if (!search) return;

        search.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(function () {
                state.query = search.value.trim();
                applyFilters();
            }, 180);
        });

        search.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                search.value = '';
                state.query = '';
                applyFilters();
                search.blur();
            }
        });

        el('filter-source').addEventListener('change', function () {
            state.source = this.value;
            applyFilters();
        });

        el('filter-type').addEventListener('change', function () {
            state.type = this.value;
            applyFilters();
        });

        el('filter-reset').addEventListener('click', function () { resetFilters(); });

        // « / » place le curseur dans la recherche, comme dans la référence.
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;

            var tag = (event.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || event.target.isContentEditable) return;

            event.preventDefault();
            search.focus();
            search.select();
        });
    });
})();
