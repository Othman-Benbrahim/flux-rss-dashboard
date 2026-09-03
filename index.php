<?php
require __DIR__ . '/db.php';

// Seul le compte propriétaire peut modifier le tableau de bord.
$is_logged_in = is_owner($pdo);
$csrf_token   = csrf_token();
$has_owner    = owner_id($pdo) !== null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title>Vision du Futur — Tableau de bord</title>
    
    <link href="https://cdn.jsdelivr.net/npm/gridstack@10.1.1/dist/gridstack.min.css" rel="stylesheet"/>
    <script src="https://cdn.ckeditor.com/ckeditor5/40.0.0/inline/ckeditor.js"></script>
    
    <style>
        /* Style Global */
        :root {
            --bubble-bg: #d1df8e;
            --bubble-border: rgba(0,0,0,0.15);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 20px;
            /* Ces propriétés sont cruciales pour le fond plein écran et fixe */
            background-size: cover;
            background-position: center center;
            background-attachment: fixed;
            min-height: 100vh;
        }

        /* Header */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.9);
            padding: 10px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: relative;
            z-index: 10;
        }
        h1 { margin: 0; color: #333; font-size: 20px; }
        .controls { display: flex; gap: 10px; }
        button {
            padding: 8px 15px;
            background-color: #f1f1f1;
            color: #333;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            font-weight: normal;
        }
        button:hover { background-color: #e6e6e6; }
        
        /* Onglets (Tabs) */
        .tabs-bar {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            overflow-x: auto;
            align-items: flex-end;
            padding: 0 10px;
        }
        .tab-button {
            background: #d4c0bc;
            color: #632323; 
            padding: 5px 15px; /* Plus petit */
            border: 1px solid #bbaaa8;
            border-bottom: none;
            margin-left: -1px;
            cursor: pointer;
            font-size: 13px; /* Plus petit */
            font-weight: normal; /* Normal */
            white-space: nowrap;
            display: flex;
            align-items: center;
            border-radius: 6px 6px 0 0;
        }
        .tab-button:first-child { margin-left: 0; }
        .tab-button.active {
            background: #ebd1cd;
            color: #7a1818;
            padding: 5px 15px; /* On enlève le padding surélevé */
            z-index: 1;
        }
        .tab-button:hover:not(.active) { background: #e0ccca; }
        .tab-button input[type="text"] {
            border: none; background: rgba(255,255,255,0.5); font-family: inherit; font-size: inherit; color: inherit; width: 120px; outline: none; border-bottom: 1px solid #7a1818; border-radius:3px; padding: 0 4px;
        }
        .tab-action-btn { font-size: 11px; color: #a99; padding: 2px; margin-left: 6px; cursor:pointer; }
        .tab-action-btn:hover { color: #555; }
        .tab-delete-btn { font-size: 10px; margin-left: 2px; color: #a99; padding: 2px; cursor:pointer; }
        .tab-delete-btn:hover { color: red; }
        .tab-add-btn {
            background: #eee;
            color: #555;
            padding: 6px 12px;
            margin-left: 10px;
            font-size: 12px;
            border-radius: 4px;
            margin-bottom: 4px;
        }

        
        /* Style des widgets (C'est là que l'on recrée l'UI de protopage) */
        .grid-stack-item-content {
            /* Vert kaki clair de Protopage */
            background-color: #f5f8e9; 
            border: 1px solid #c9ccbb;
            border-radius: 0;
            box-shadow: 2px 2px 5px rgba(0,0,0,0.1);
            padding: 0;
            overflow: hidden; /* Eviter de déborder de la case gridstack */
            display: flex;
            flex-direction: column;
        }

        /* Barre de titre du widget */
        .widget-header {
            background-color: #e8e4c7; /* Beige vieux papier */
            color: #214371; /* Bleu foncé */
            padding: 8px 15px;
            font-weight: bold;
            font-size: 13px;
            border-bottom: 1px solid #dcd7bc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0; /* Important ! */
            cursor: move;
        }
        .widget-controls { font-size: 11px; cursor: pointer; color: #666; }
        .widget-controls span { margin-left: 5px; }

        /* Contenu du widget */
        .widget-body {
            flex: 1; /* Remplit l'espace vacant laissé par le header */
            min-height: 0; /* Autorise le DOM à rétrécir en dessous de sa taille pour ne pas bloquer le resize Gridstack vers le HAUT */
            padding: 10px 15px;
            overflow-y: auto;
            box-sizing: border-box;
        }

        /* Liste de flux RSS de Protopage */
        .rss-item {
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #dcdcd1; /* Ligne pointillée */
            display: flex;
            gap: 10px;
            align-items: start;
        }
        .rss-item:last-child { border-bottom: none; }
        
        /* Titre de l'article */
        .rss-item a {
            color: #214371; /* Couleur distincte pour le titre */
            text-decoration: none;
            font-weight: normal;
            font-size: 13px;
            display: block;
        }
        .rss-item a:hover { text-decoration: underline; color: #8ab680; } /* Couleur au survol */

        /* Résumé et image miniature (cachés par défaut si présents) */
        .rss-item .mini-image { max-width: 50px; max-height: 50px; border-radius: 4px; display: none;}
        .rss-item .rss-desc { display: none; }

        /* ========= LE MODAL D'ÉDITION DE WIDGET (PROTOPAGE CLONE) ========= */
        #widget-edit-modal {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 3000;
        }
        .edit-modal-content {
            background: #b2ce74; /* Vert Protopage */
            width: 600px;
            border: 1px solid #9ab556;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .edit-modal-header {
            background: white; padding: 10px 15px; border-top-left-radius: 8px; border-top-right-radius: 8px;
            font-weight: bold; color: #333; border-bottom: 1px solid #ccc;
        }
        .edit-modal-body { padding: 20px; font-size: 13px; color: #222; }
        .edit-row { margin-bottom: 10px; }
        .edit-row input[type="text"], .edit-row input[type="number"], .edit-url-input {
            padding: 4px; border: 1px solid #999; border-radius: 3px;
        }
        .color-swatch {
            display: inline-block; width: 18px; height: 18px; margin-right: 4px; border: 1px solid #888; cursor: pointer;
        }
        .color-swatch.selected { border: 2px solid white; box-shadow: 0 0 0 1px #333; }
        .edit-modal-footer {
            padding: 15px; display: flex; justify-content: flex-end; gap: 10px;
        }
        .edit-modal-footer button {
            background: white; border: 1px solid #9ab556; padding: 6px 15px; border-radius: 20px; font-weight: bold; cursor: pointer;
        }

        .bookmark-edit-row { display: flex; align-items: center; gap: 5px; margin-bottom: 5px; }
        .bookmark-btn { background: #eee; border: 1px solid #ccc; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px; }
        .bookmark-btn:hover { background: #ddd; }

        /* ========= LE MOTEUR D'APERÇU AU HOVER ========= */
        #hover-preview {
            position: absolute;
            background-color: var(--bubble-bg);
            border: 1px solid var(--bubble-border);
            border-radius: 8px;
            padding: 12px;
            width: 320px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 2000;
            pointer-events: none;
            display: none;
            flex-direction: row;
            gap: 12px;
            align-items: flex-start;
        }

        /* Flèche du bas de la bulle */
        #hover-preview::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            margin-left: -10px;
            border-width: 10px 10px 0;
            border-style: solid;
            border-color: var(--bubble-bg) transparent transparent transparent;
        }
        #hover-preview::before {
            content: '';
            position: absolute;
            bottom: -11px;
            left: 50%;
            margin-left: -10px;
            border-width: 10px 10px 0;
            border-style: solid;
            border-color: var(--bubble-border) transparent transparent transparent;
        }

        #hover-preview .preview-image {
            width: 110px;
            height: 70px;
            object-fit: cover;
            border-radius: 4px;
            display: none;
            flex-shrink: 0;
            border: 1px solid rgba(0,0,0,0.1);
        }
        
        #hover-preview .preview-content {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        #hover-preview .preview-title {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 6px;
            color: #000;
        }
        
        #hover-preview .preview-summary {
            font-size: 12px;
            color: #111;
            line-height: 1.4;
        }

        /* ========= LE MODAL DE CONFIGURATION DU FOND ========= */
        #background-modal {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        #background-modal .modal-content {
            background: white;
            padding: 25px;
            border-radius: 10px;
            width: 400px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
        }
        #background-modal h2 { margin-top: 0; }
        .modal-body { margin-bottom: 20px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; }
        

        /* ========= MENUS DÉROULANTS DE L'EN-TÊTE ========= */
        .menu { position: relative; display: inline-block; }

        .menu-trigger {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background-color: #f1f1f1; color: #333;
            border: 1px solid #ccc; border-radius: 4px; cursor: pointer;
            font-size: 13px; font-family: inherit;
        }
        .menu-trigger:hover { background-color: #e6e6e6; }
        .menu-trigger:focus-visible { outline: 2px solid #214371; outline-offset: 2px; }
        .menu.open > .menu-trigger { background-color: #e0e0e0; border-color: #999; }
        .menu-trigger::after {
            content: '▾'; font-size: 10px; color: #777; margin-left: 2px;
        }
        .menu-trigger.no-caret::after { content: none; }

        .menu-panel {
            display: none; position: absolute; top: calc(100% + 6px); left: 0;
            min-width: 220px; background: #fff; border: 1px solid #ccc;
            border-radius: 6px; box-shadow: 0 6px 20px rgba(0,0,0,0.14);
            padding: 6px; z-index: 200;
        }
        .menu.open .menu-panel { display: block; }

        .menu-panel button {
            display: flex; align-items: center; gap: 10px; width: 100%;
            padding: 8px 10px; background: none; border: none; border-radius: 4px;
            text-align: left; font-size: 13px; color: #333; cursor: pointer;
            font-family: inherit; font-weight: normal;
        }
        .menu-panel button:hover:not(:disabled) { background-color: #eef2f7; }
        .menu-panel button:focus-visible { outline: 2px solid #214371; outline-offset: -2px; }
        .menu-panel button:disabled { color: #aaa; cursor: default; }
        .menu-panel button span { width: 18px; text-align: center; flex: none; }
        .menu-danger { color: #c62828 !important; }
        .menu-sep { height: 1px; background: #e5e5e5; margin: 6px 4px; }

        /* ========= ÉTAT LU / NON LU ========= */
        .rss-title-link { font-weight: 600; }
        .rss-title-link.rss-read { font-weight: 400; color: #8a8a8a; }
        .rss-title-link.rss-read:hover { color: #214371; }

        /* ========= WIDGET HTML / JS ========= */
        .html-widget-frame {
            width: 100%; height: 100%; border: 0; display: block; background: #fff;
        }
        .html-widget-empty { padding: 15px; color: #666; font-size: 12px; }

        /* ========= IMPORT OPML ========= */
        #opml-modal, #about-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 1000;
            justify-content: center; align-items: center;
        }
        #opml-modal .modal-content, #about-modal .modal-content {
            background: #fff; border-radius: 8px; width: 90%; max-width: 620px;
            max-height: 82vh; display: flex; flex-direction: column; overflow: hidden;
        }
        .opml-head {
            padding: 14px 18px; border-bottom: 1px solid #ddd;
            font-weight: bold; display: flex; justify-content: space-between; align-items: center;
        }
        .opml-body { padding: 18px; overflow-y: auto; flex: 1; }
        .opml-foot {
            padding: 12px 18px; border-top: 1px solid #ddd;
            display: flex; gap: 10px; justify-content: flex-end; align-items: center;
        }
        #opml-status { font-size: 12px; color: #666; margin: 10px 0; min-height: 16px; }

        #opml-selection { border: 1px solid #e0e0e0; border-radius: 6px; }
        .opml-folder { border-bottom: 1px solid #eee; }
        .opml-folder:last-child { border-bottom: none; }
        .opml-folder-head {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 12px; background: #f6f6f4; font-size: 13px;
        }
        .opml-count { font-size: 11px; color: #888; }
        .opml-feed {
            display: grid; grid-template-columns: 20px 1fr auto;
            gap: 8px; align-items: baseline;
            padding: 6px 12px 6px 24px; font-size: 12px; cursor: pointer;
        }
        .opml-feed:hover { background: #fafafa; }
        .opml-feed-existing { color: #aaa; cursor: default; }
        .opml-feed-title { font-weight: 600; }
        .opml-feed-url {
            grid-column: 2 / 3; font-size: 11px; color: #999;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .opml-badge {
            font-size: 10px; background: #eee; color: #777;
            padding: 2px 6px; border-radius: 3px; grid-row: 1;
        }
        .opml-select-links { font-size: 12px; margin-right: auto; }
        .opml-select-links button {
            background: none; border: none; color: #214371;
            text-decoration: underline; cursor: pointer; padding: 0 6px; font-size: 12px;
        }

        #edit-widget-html {
            width: 100%; min-height: 180px; font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: 12px; border: 1px solid #ccc; border-radius: 4px; padding: 8px;
            box-sizing: border-box; resize: vertical;
        }
        .html-warning {
            font-size: 11px; color: #7a5c00; background: #fff8e1;
            border-radius: 4px; padding: 8px 10px; margin-top: 8px; line-height: 1.5;
        }

        /* ========= BARRE DE FILTRES ========= */
        #filter-bar {
            display: none; background: rgba(255,255,255,0.92); border-radius: 10px;
            padding: 14px 18px; margin-bottom: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); position: relative; z-index: 9;
        }
        .filter-search-wrap { position: relative; }
        #filter-search {
            width: 100%; box-sizing: border-box; padding: 10px 42px 10px 36px;
            border: 1px solid #d5d5cc; border-radius: 8px; font-size: 14px;
            font-family: inherit; background: #fbfbf8; color: #222;
        }
        #filter-search:focus { outline: none; border-color: #214371; background: #fff; }
        .filter-search-icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            color: #999; font-size: 14px; pointer-events: none;
        }
        .filter-slash {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            border: 1px solid #ddd; border-radius: 4px; padding: 1px 6px;
            font-size: 11px; color: #aaa; pointer-events: none;
        }
        .filter-row {
            display: flex; align-items: center; gap: 8px;
            margin-top: 12px; flex-wrap: wrap;
        }
        .filter-label {
            font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase;
            color: #999; margin-right: 4px;
        }
        .filter-pill {
            padding: 5px 12px; border: 1px solid #d5d5cc; border-radius: 20px;
            background: #fbfbf8; color: #444; font-size: 12px; cursor: pointer;
            font-family: inherit; font-weight: normal;
        }
        .filter-pill:hover { background: #f0f0ea; }
        .filter-pill.active {
            background: #214371; border-color: #214371; color: #fff; font-weight: 600;
        }
        #filter-source, #filter-type {
            padding: 6px 10px; border: 1px solid #d5d5cc; border-radius: 6px;
            background: #fbfbf8; font-size: 12px; font-family: inherit; color: #444;
            max-width: 240px;
        }
        #filter-reset {
            display: none; margin-left: auto; padding: 5px 12px; font-size: 12px;
            background: none; border: 1px solid #d5d5cc; border-radius: 6px;
            color: #777; cursor: pointer;
        }
        #filter-empty {
            display: none; padding: 30px; text-align: center; color: #fff;
            background: rgba(0,0,0,0.25); border-radius: 10px; font-size: 14px;
        }

        /* ========= VUE FILTRÉE =========
           Le moteur de gridstack n'est pas touché : on remplace seulement le
           positionnement absolu par un flux en colonnes. Retirer la classe
           restaure la grille exactement telle qu'elle était. */
        .grid-stack.filtering {
            height: auto !important;
            column-count: 3; column-gap: 12px;
        }
        @media (max-width: 1100px) { .grid-stack.filtering { column-count: 2; } }
        @media (max-width: 700px)  { .grid-stack.filtering { column-count: 1; } }

        .grid-stack.filtering .grid-stack-item {
            position: relative !important;
            left: auto !important; top: auto !important;
            width: 100% !important; height: auto !important;
            transform: none !important;
            display: inline-block; margin: 0 0 12px 0 !important; padding: 0 !important;
            break-inside: avoid;
        }
        .grid-stack.filtering .grid-stack-item-content {
            position: relative !important;
            inset: auto !important; height: auto !important;
            max-height: 420px; overflow: auto;
        }
        .grid-stack.filtering .ui-resizable-handle,
        .grid-stack.filtering .widget-header { cursor: default; }
        .grid-stack.filtering .ui-resizable-handle { display: none !important; }

        .filtered-out { display: none !important; }

        /* ========= VIGNETTE DE REMPLACEMENT ========= */
        .rss-placeholder { border-radius: 3px; display: block; flex: none; }

        /* ========= PODCASTS ========= */
        .rss-audio-btn {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 5px; padding: 3px 9px; font-size: 11px;
            border: 1px solid #c9c9be; border-radius: 12px;
            background: #f4f4ee; color: #444; cursor: pointer; font-family: inherit;
        }
        .rss-audio-btn:hover { background: #e8e8df; }
        .rss-audio-player { width: 100%; height: 32px; margin-top: 6px; display: block; }

        /* ========= TAGS DU WIDGET ========= */
        .widget-tags { display: inline-flex; gap: 4px; margin-left: 6px; }
        .widget-tag {
            font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em;
            background: rgba(33,67,113,0.12); color: #214371;
            padding: 1px 5px; border-radius: 3px; font-weight: 600;
        }

        /* ========= PANNEAU DE FOND D'ÉCRAN ========= */
        #background-modal .modal-content { max-width: 520px; }
        .bg-section-title {
            font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase;
            color: #999; margin: 18px 0 8px 0;
        }
        .bg-section-title:first-child { margin-top: 0; }

        #bg-palette { display: flex; flex-wrap: wrap; gap: 8px; }
        .bg-swatch {
            position: relative; width: 34px; height: 34px; border-radius: 8px;
            border: 2px solid #ddd; cursor: pointer; display: inline-block;
            box-sizing: border-box; padding: 0;
        }
        .bg-swatch:hover { border-color: #999; }
        .bg-swatch.active { border-color: #214371; box-shadow: 0 0 0 2px rgba(33,67,113,0.2); }
        .bg-swatch-none {
            background: repeating-linear-gradient(45deg, #fff, #fff 6px, #eee 6px, #eee 12px);
            color: #999; font-size: 12px; line-height: 30px; text-align: center;
        }
        .bg-swatch-remove {
            position: absolute; top: -6px; right: -6px; width: 16px; height: 16px;
            border-radius: 50%; border: none; background: #c62828; color: #fff;
            font-size: 9px; line-height: 16px; padding: 0; cursor: pointer;
            display: none; text-align: center;
        }
        .bg-swatch:hover .bg-swatch-remove { display: block; }

        .bg-add-row { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
        #bg-color-input { width: 42px; height: 32px; padding: 0; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; }
        #bg-alpha-input { flex: 1; }
        #bg-alpha-value { font-size: 11px; color: #777; width: 42px; text-align: right; }
        #bg-status { font-size: 12px; color: #666; min-height: 16px; margin-top: 12px; }
        #bg-image-state { font-size: 12px; color: #777; margin-top: 6px; }

        /* ========= BANDEAU LECTURE SEULE ========= */
        #grid-readonly-notice {
            display: none; background: rgba(255,248,225,0.95); color: #7a5c00;
            border-radius: 8px; padding: 10px 14px; margin-bottom: 14px;
            font-size: 12px; line-height: 1.5;
        }

        /* ========= ADAPTATION AUX ÉCRANS ÉTROITS ========= */

        /* Tablette */
        @media (max-width: 1100px) {
            body { padding: 14px; }

            header { padding: 10px 16px; flex-wrap: wrap; gap: 10px; }
            header h1 { font-size: 20px; margin: 0; }
            .controls { flex-wrap: wrap; gap: 6px; }

            .menu-panel { min-width: 200px; }
        }

        /* Téléphone */
        @media (max-width: 700px) {
            body { padding: 10px; }

            header {
                flex-direction: column; align-items: stretch;
                padding: 10px 12px; margin-bottom: 14px;
            }
            header h1 {
                font-size: 17px; text-align: center;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .controls { justify-content: space-between; width: 100%; }
            .menu { flex: 1; }
            .menu-trigger {
                width: 100%; justify-content: center;
                padding: 9px 6px; font-size: 12px;
            }

            /* Le panneau se cale sur la largeur de l'écran plutôt que
               de déborder hors du cadre. */
            .menu-panel {
                position: fixed; left: 10px; right: 10px; top: auto;
                min-width: 0; width: auto;
            }

            .tabs-bar {
                padding: 0 4px; margin-bottom: 14px;
                -webkit-overflow-scrolling: touch;
            }
            .tab-button { flex: none; }

            #filter-bar { padding: 12px; }
            #filter-search { font-size: 16px; } /* 16px : évite le zoom automatique iOS */
            .filter-slash { display: none; }
            .filter-row { gap: 6px; }
            #filter-source, #filter-type { max-width: 100%; flex: 1; }
            #filter-reset { margin-left: 0; width: 100%; }

            /* Panneaux : plein écran utile, contenu défilant. */
            #background-modal .modal-content,
            #opml-modal .modal-content,
            #about-modal .modal-content,
            .edit-modal-content {
                width: calc(100% - 20px); max-width: none;
                max-height: 88vh; overflow-y: auto;
            }
            .edit-row { flex-wrap: wrap; }
            .edit-row label { width: 100% !important; margin-bottom: 4px; }
            .edit-row input[type="text"],
            .edit-row input[type="number"] { width: 100% !important; box-sizing: border-box; }
            #edit-widget-colors { margin-left: 0 !important; margin-top: 8px; flex-wrap: wrap; }

            .opml-feed { grid-template-columns: 20px 1fr; }
            .opml-badge { grid-column: 2; justify-self: start; }

            .bg-swatch { width: 40px; height: 40px; } /* cible tactile */
            .bg-add-row { flex-wrap: wrap; }

            /* Les vignettes d'articles gagnent en lisibilité. */
            .rss-item img, .rss-placeholder { width: 52px !important; height: 40px !important; }
            .widget-header { padding: 10px 12px; }
            .widget-tags { display: none; } /* place rendue au titre */
        }

        /* Écran très étroit : on retire le titre pour ne garder que les menus. */
        @media (max-width: 420px) {
            header h1 { display: none; }
        }
    </style>
</head>
<body>

    <header>
        <h1>🚀 Vision du Futur</h1>
        <div class="controls">
            <?php if ($is_logged_in): ?>
                <div class="menu" data-menu>
                    <button type="button" class="menu-trigger" aria-haspopup="true" aria-expanded="false">⊞ Ajouter</button>
                    <div class="menu-panel" role="menu">
                        <button type="button" role="menuitem" onclick="addWidget('rss')"><span>📰</span> Flux RSS</button>
                        <button type="button" role="menuitem" onclick="addWidget('bookmarks')"><span>🔖</span> Favoris</button>
                        <button type="button" role="menuitem" onclick="addWidget('note')"><span>📝</span> Note</button>
                        <button type="button" role="menuitem" onclick="addWidget('youtube')"><span>▶️</span> Vidéo YouTube</button>
                        <button type="button" role="menuitem" onclick="addWidget('image')"><span>📷</span> Photo</button>
                        <button type="button" role="menuitem" onclick="addWidget('html')"><span>🧩</span> Code HTML / JS</button>
                        <div class="menu-sep"></div>
                        <button type="button" role="menuitem" onclick="openOpmlModal()"><span>📥</span> Importer un OPML…</button>
                    </div>
                </div>

                <div class="menu" data-menu>
                    <button type="button" class="menu-trigger" aria-haspopup="true" aria-expanded="false">✓ Lecture</button>
                    <div class="menu-panel" role="menu">
                        <button type="button" role="menuitem" onclick="markAllRead()"><span>✓</span> Tout marquer comme lu</button>
                        <button type="button" role="menuitem" onclick="markAllUnread()"><span>↺</span> Tout remettre en non lu</button>
                    </div>
                </div>

                <div class="menu" data-menu>
                    <button type="button" class="menu-trigger" aria-haspopup="true" aria-expanded="false">🎨 Réglages</button>
                    <div class="menu-panel" role="menu">
                        <button type="button" role="menuitem" onclick="openBackgroundModal()"><span>🖼️</span> Image de fond</button>
                        <button type="button" role="menuitem" onclick="addTab()"><span>➕</span> Nouvel onglet</button>
                    </div>
                </div>

                <div class="menu" data-menu>
                    <button type="button" class="menu-trigger" aria-haspopup="true" aria-expanded="false">⋯</button>
                    <div class="menu-panel" role="menu">
                        <button type="button" role="menuitem" onclick="openAboutModal()"><span>❔</span> Aide / À propos</button>
                        <div class="menu-sep"></div>
                        <button type="submit" form="logout-form" role="menuitem" class="menu-danger"><span>⎋</span> Déconnexion</button>
                    </div>
                </div>

                <form id="logout-form" method="POST" action="logout.php" style="display:none;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                </form>
            <?php else: ?>
                <a href="login.php"><button style="background-color: #28a745; color: white; border: none;"><?= $has_owner ? 'Modifier' : 'Créer le tableau de bord' ?></button></a>
            <?php endif; ?>
        </div>
    </header>

    <div id="filter-bar">
        <div class="filter-search-wrap">
            <span class="filter-search-icon">🔍</span>
            <input type="search" id="filter-search" placeholder="Rechercher un article, une source, un sujet…" autocomplete="off">
            <span class="filter-slash">/</span>
        </div>
        <div class="filter-row">
            <span class="filter-label">Filtrer</span>
            <div id="filter-tags" style="display:flex; gap:8px; flex-wrap:wrap;"></div>
            <button type="button" id="filter-reset">Réinitialiser</button>
        </div>
        <div class="filter-row">
            <span class="filter-label">Source</span>
            <select id="filter-source"><option value="">Toutes les sources</option></select>
            <span class="filter-label" style="margin-left:12px;">Type</span>
            <select id="filter-type">
                <option value="">Tous les types</option>
                <option value="article">Articles</option>
                <option value="podcast">Podcasts</option>
                <option value="youtube">Vidéos</option>
            </select>
        </div>
    </div>

    <div id="grid-readonly-notice">
        Écran étroit : la grille est repliée et la disposition n'est pas modifiable.
        Élargissez la fenêtre pour réorganiser vos widgets.
    </div>

    <div id="filter-empty">Aucun widget ne correspond à ces filtres.</div>

    <div id="tabs-container" class="tabs-bar">
        <!-- Les onglets s'afficheront ici -->
    </div>

    <div class="grid-stack"></div>

    <div id="hover-preview">
        <img class="preview-image" src="" alt="Aperçu">
        <div class="preview-content">
            <div class="preview-title">Chargement...</div>
            <div class="preview-summary">Veuillez patienter...</div>
            <div class="preview-date" style="font-size: 11px; color: #555; margin-top: 5px;"></div>
        </div>
    </div>

    <div id="background-modal">
        <div class="modal-content">
            <h2>Fond d'écran</h2>
            <div class="modal-body">
                <div class="bg-section-title">Couleur</div>
                <div id="bg-palette"></div>

                <div class="bg-add-row">
                    <input type="color" id="bg-color-input" value="#cfe0ea" title="Choisir une teinte">
                    <input type="range" id="bg-alpha-input" min="10" max="100" value="80" title="Opacité">
                    <span id="bg-alpha-value">80 %</span>
                    <button type="button" onclick="addCustomColor()">Ajouter à la palette</button>
                </div>

                <div class="bg-section-title">Image</div>
                <input type="file" id="background-input" accept="image/*" style="width: 100%;">
                <div id="bg-image-state">Aucune image.</div>
                <div style="margin-top:10px; display:flex; gap:8px;">
                    <button type="button" style="background-color:#007bff; color:white;" onclick="saveBackground()">Appliquer l'image</button>
                    <button type="button" id="bg-image-delete" style="display:none;" onclick="deleteBackgroundImage()">Supprimer l'image</button>
                </div>

                <div id="bg-status"></div>
            </div>
            <div class="modal-footer">
                <button onclick="closeBackgroundModal()">Fermer</button>
            </div>
        </div>
    </div>

    <!-- NOUVEAU MODAL D'EDITION DE WIDGET -->
    <div id="widget-edit-modal">
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                📝 Modifier le widget
                <span style="float:right; cursor:pointer; color:#777;" onclick="closeWidgetModal()">✖</span>
            </div>
            <div class="edit-modal-body">
                <div class="edit-row" style="display:flex; align-items:center;">
                    <label style="width:130px; font-weight:bold;">Titre du widget</label>
                    <input type="text" id="edit-widget-title" style="width:180px;">
                    <div id="edit-widget-colors" style="margin-left:auto; display:flex;">
                        <!-- Les couleurs seront gérées en JS -->
                    </div>
                </div>
                
                <div id="rss-settings-section">
                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 15px 0 10px 0; padding-bottom: 4px;">Options du flux d'actualité</h4>
                    
                    <div class="edit-row">
                        <label style="font-weight:bold; margin-right: 15px;">Nombre maximum d'articles à afficher</label>
                        <input type="number" id="edit-widget-limit" style="width:50px;" value="5" min="1" max="20">
                    </div>
                    
                    <div class="edit-row" style="display:flex; align-items:center; margin-top:12px;">
                        <span style="font-weight:bold; width:130px;">Affichage</span>
                        <label><input type="radio" name="edit-display" value="titles"> Titres uniquement</label>
                        <label style="margin-left: 20px;"><input type="radio" name="edit-display" value="previews" checked> Titres et aperçus</label>
                    </div>

                    <div class="edit-row" style="display:flex; align-items:center; margin-top:12px;">
                        <span style="font-weight:bold; width:130px;">Mode</span>
                        <label><input type="radio" name="edit-mode" value="normal" checked> Flux normal</label>
                        <label style="margin-left: 20px;"><input type="radio" name="edit-mode" value="photos"> Photos uniquement</label>
                        <label style="margin-left: 20px;"><input type="radio" name="edit-mode" value="single"> Image/Vidéo unique</label>
                    </div>

                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 20px 0 10px 0; padding-bottom: 4px;">Configuration du flux d'actualité</h4>
                    <div style="font-size:11px; margin-bottom:5px;">URL de la page ou du site (ex: lemonde.fr)</div>
                    <div id="edit-widget-urls">
                        <div style="display:flex; align-items:center; margin-bottom:5px;">
                            <input type="text" id="edit-widget-url" class="edit-url-input" style="flex:1;">
                        </div>
                    </div>
                </div>

                <div id="bookmarks-settings-section" style="display:none;">
                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 15px 0 10px 0; padding-bottom: 4px;">Liste des Favoris</h4>
                    <div style="font-size:11px; margin-bottom:5px; display:flex;">
                        <div style="width:35px;"></div>
                        <div style="flex:1;">Label de lien (ex: BBC News)</div>
                        <div style="flex:1; margin-left:5px;">URL (ex: bbc.com)</div>
                        <div style="width:25px;"></div>
                    </div>
                    <div id="bookmarks-list-editor" style="max-height: 250px; overflow-y: auto; padding-right: 5px;">
                        <!-- Lignes de saisie dynamique -->
                    </div>
                    <button style="margin-top:10px; cursor: pointer; padding: 4px 10px; border-radius: 4px; border: 1px solid #ccc; background: #eee;" onclick="addBookmarkRow('', '')">+ Ajouter un lien</button>
                </div>

                <div id="youtube-settings-section" style="display:none;">
                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 15px 0 10px 0; padding-bottom: 4px;">Propriétés de la vidéo YouTube</h4>
                    <div style="font-size:11px; margin-bottom:5px;">URL de la vidéo (ex: https://www.youtube.com/watch?v=dQw4w9WgXcQ)</div>
                    <div style="display:flex; align-items:center; margin-bottom:5px;">
                        <input type="text" id="edit-widget-youtube" class="edit-url-input" style="flex:1;">
                    </div>
                </div>

                <div id="tags-settings-section" style="display:none;">
                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 15px 0 10px 0; padding-bottom: 4px;">Tags thématiques</h4>
                    <div style="font-size:11px; margin-bottom:5px;">Séparés par des virgules — 8 au maximum. Ils s'appliquent au flux entier.</div>
                    <input type="text" id="edit-widget-tags" placeholder="IA, Cybersécurité, Veille" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
                </div>

                <div id="html-settings-section" style="display:none;">
                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 15px 0 10px 0; padding-bottom: 4px;">Code HTML / JavaScript</h4>
                    <div style="font-size:11px; margin-bottom:5px;">Collez ici le code d'un widget tiers, ou le vôtre.</div>
                    <textarea id="edit-widget-html" spellcheck="false" placeholder="&lt;div&gt;Bonjour&lt;/div&gt;&#10;&lt;script&gt;console.log('ok');&lt;/script&gt;"></textarea>
                    <div class="html-warning">
                        Ce code s'exécute dans un cadre isolé (<code>iframe sandbox</code>), sans accès
                        à cette page, à vos cookies ni à votre session. En contrepartie, un widget
                        qui a besoin de dialoguer avec la page hôte ou de lire le stockage du domaine
                        ne fonctionnera pas.
                    </div>
                </div>

                <div id="image-settings-section" style="display:none;">
                    <h4 style="border-bottom: 2px solid #9dbd56; margin: 15px 0 10px 0; padding-bottom: 4px;">Propriétés de l'image</h4>
                    <div style="font-size:11px; margin-bottom:5px;">Téléversez une image directement depuis votre ordinateur</div>
                    <div style="display:flex; flex-direction:column; align-items:center; margin-bottom:5px; background: #eee; padding:10px; border:1px dashed #ccc;">
                        <input type="file" id="edit-widget-image-upload" accept="image/*" onchange="uploadWidgetImage(this)">
                        <span id="edit-widget-image-status" style="font-size:11px; color:green; margin-top:5px; display:none;">Téléchargé!</span>
                        <input type="hidden" id="edit-widget-image-url">
                    </div>
                </div>
            </div>
            <div class="edit-modal-footer">
                <button onclick="closeWidgetModal()">Annuler</button>
                <button onclick="saveWidgetModal()">Sauvegarder</button>
            </div>
        </div>
    </div>

    <div id="opml-modal">
        <div class="modal-content">
            <div class="opml-head">
                <span>📥 Importer un fichier OPML</span>
                <span style="cursor:pointer; color:#777;" onclick="closeOpmlModal()">✖</span>
            </div>
            <div class="opml-body">
                <p style="font-size:13px; color:#555; margin-top:0;">
                    Choisissez un fichier OPML exporté depuis un autre lecteur de flux.
                    Rien n'est enregistré tant que vous n'avez pas validé la sélection.
                </p>
                <input type="file" id="opml-file" accept=".opml,.xml,text/xml,application/xml" style="width:100%;">
                <div style="margin-top:10px;">
                    <button type="button" onclick="uploadOpml()">Lire le fichier</button>
                </div>
                <div id="opml-status"></div>
                <div id="opml-selection" style="display:none;"></div>
            </div>
            <div class="opml-foot">
                <span class="opml-select-links" id="opml-actions" style="display:none;">
                    <button type="button" onclick="opmlSelectAll(true)">Tout cocher</button>·<button type="button" onclick="opmlSelectAll(false)">Tout décocher</button>
                </span>
                <button type="button" onclick="closeOpmlModal()">Annuler</button>
                <button type="button" id="opml-import-btn" style="background-color:#007bff; color:white;" onclick="importOpml()" disabled>Importer</button>
            </div>
        </div>
    </div>

    <div id="about-modal">
        <div class="modal-content">
            <div class="opml-head">
                <span>❔ Aide / À propos</span>
                <span style="cursor:pointer; color:#777;" onclick="closeAboutModal()">✖</span>
            </div>
            <div class="opml-body" style="font-size:13px; color:#444; line-height:1.6;">
                <p><strong>Tableau de bord personnel</strong> — flux RSS, favoris, notes et widgets,
                organisés en onglets et disposés par glisser-déposer.</p>
                <ul style="padding-left:18px;">
                    <li>Faites glisser un widget par sa barre de titre pour le déplacer.</li>
                    <li>Tirez le coin inférieur droit pour le redimensionner.</li>
                    <li><em>edit</em> ouvre les réglages, <em>x</em> supprime le widget.</li>
                    <li>Un article cliqué passe automatiquement en « lu ».</li>
                    <li>Le widget <em>Code HTML / JS</em> s'exécute dans un cadre isolé, sans accès
                        à cette page ni à votre session.</li>
                </ul>
                <p style="color:#777; font-size:12px;">Seul le compte propriétaire peut modifier le tableau.
                Les visiteurs le voient en lecture seule.</p>
            </div>
            <div class="opml-foot">
                <button type="button" onclick="closeAboutModal()">Fermer</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/gridstack@10.1.1/dist/gridstack-all.js"></script>

    <script>
        // ========= 0. SÉCURITÉ : ÉCHAPPEMENT ET APPELS API =========
        // Tout ce qui vient d'un flux distant traverse esc() avant d'entrer
        // dans un innerHTML. Sans cela, un titre d'article contenant du HTML
        // s'exécuterait dans la page.
        const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

        function esc(value) {
            if (value === null || value === undefined) return '';
            return String(value).replace(/[&<>"']/g, function (c) { return HTML_ESCAPES[c]; });
        }

        // N'accepte qu'une adresse http(s) ou un fichier de notre dossier uploads.
        // Bloque javascript:, data:, vbscript: et les chemins fantaisistes.
        function safeUrl(value) {
            const raw = String(value === null || value === undefined ? '' : value).trim();
            if (/^https?:\/\//i.test(raw)) return raw;
            if (/^uploads\/[A-Za-z0-9._\/-]+$/.test(raw)) return raw;
            return '';
        }

        // Enveloppe fetch() : ajoute le jeton CSRF sur toute requête modifiante.
        function api(url, options) {
            const opts = Object.assign({}, options || {});
            opts.headers = Object.assign({}, opts.headers || {});
            opts.credentials = 'same-origin';

            const method = (opts.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD') {
                opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
            }
            return fetch(url, opts);
        }

        // ========= 1. VARIABLES GLOBALES =========
        const isLoggedIn = <?= json_encode($is_logged_in) ?>;
        const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
        let tabsById = {};
        const hoverPreview = document.getElementById('hover-preview');
        const bgModal = document.getElementById('background-modal');
        const bgInput = document.getElementById('background-input');
        
        let currentTabId = 1; // Tab actif par défaut (modifié plus tard par loadTabs)
        let isTabLoading = false;
        let loadedTabId = null; // onglet réellement présent dans la grille
        
        // Initialisation de la grille
        // Disposition de référence : 12 colonnes. C'est celle qui est
        // enregistrée en base. En dessous de 1100 px la grille se replie, et
        // toute écriture est bloquée (voir saveGridState) pour que la version
        // mobile n'écrase jamais la disposition d'origine.
        const GRID_REFERENCE_COLUMNS = 12;

        let grid = GridStack.init({
            cellHeight: '120px',
            margin: 15,
            animate: true,
            disableDrag: !isLoggedIn,
            disableResize: !isLoggedIn,
            column: GRID_REFERENCE_COLUMNS,
            columnOpts: {
                breakpointForWindow: true,
                breakpoints: [
                    { w: 700,  c: 1 },
                    { w: 1100, c: 6 }
                ]
            }
        });

        /**
         * La grille est-elle dans sa disposition de référence ?
         * Hors de celle-ci, les positions affichées sont recalculées par
         * gridstack et n'ont rien à faire en base.
         */
        function gridIsReference() {
            return typeof grid.getColumn !== 'function'
                || grid.getColumn() === GRID_REFERENCE_COLUMNS;
        }

        /**
         * Le glisser-déposé n'est proposé que là où il peut être enregistré.
         * Laisser l'utilisateur déplacer un widget sans rien persister serait
         * plus déroutant que de désactiver la manipulation.
         */
        function syncGridEditability() {
            const editable = isLoggedIn && gridIsReference()
                && !document.querySelector('.grid-stack.filtering');

            grid.enableMove(editable);
            grid.enableResize(editable);

            const notice = document.getElementById('grid-readonly-notice');
            if (notice) {
                notice.style.display = (isLoggedIn && !gridIsReference()) ? 'block' : 'none';
            }
        }

        let resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(syncGridEditability, 200);
        });

        // ========= 1.B GESTION DES ONGLETS (TABS) =========
        async function loadTabs() {
            // Le verrou est posé AVANT le premier await. Sans cela, un évènement
            // « change » de gridstack encore en attente peut déclencher une
            // sauvegarde pendant que currentTabId désigne déjà le nouvel onglet
            // alors que la grille contient encore les widgets de l'ancien.
            isTabLoading = true;
            try {
                const response = await fetch('api_tabs.php');
                const tabs = await response.json();

                if (tabs.length > 0) {
                    // S'assurer que currentTabId existe toujours parmi les tabs renvoyés
                    if (!tabs.find(t => t.id == currentTabId)) {
                        currentTabId = tabs[0].id;
                    }
                    renderTabs(tabs);
                    await loadWidgets(currentTabId);
                }
            } catch (error) {
                console.error("Erreur chargement tabs:", error);
            } finally {
                isTabLoading = false;
            }
        }

        function renderTabs(tabs) {
            const container = document.getElementById('tabs-container');
            let html = '';
            
            tabsById = {};

            tabs.forEach(tab => {
                tabsById[tab.id] = tab;
                const id = Number(tab.id);
                const isActive = (tab.id == currentTabId) ? 'active' : '';
                // Le titre n'est plus interpolé dans un onclick : seul l'identifiant
                // numérique y figure, et le libellé passe par esc().
                const deleteBtn = (isLoggedIn && tabs.length > 1) ? `<span class="tab-delete-btn" onclick="event.stopPropagation(); deleteTab(${id})" title="Supprimer cet onglet">✖</span>` : '';
                const renameBtn = (isLoggedIn) ? `<span class="tab-action-btn" onclick="triggerRename(${id}, event)" title="Renommer cet onglet">✎</span>` : '';

                html += `<div class="tab-button ${isActive}" onclick="switchTab(${id})">
                    <span class="tab-title-span" style="margin-right:8px;">${esc(tab.title)}</span>
                    <div style="display:inline-block;">${renameBtn}${deleteBtn}</div>
                </div>`;
            });
            
            if (isLoggedIn) {
                html += `<button class="tab-add-btn" onclick="addTab()">+ New tab</button>`;
            }
            container.innerHTML = html;
        }

        function switchTab(tabId) {
            if (currentTabId === tabId || isTabLoading) return;
            isTabLoading = true; // verrou posé avant même de changer d'onglet
            currentTabId = tabId;
            loadTabs();
        }

        async function addTab() {
            try {
                const res = await api('api_tabs.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({title: "Nouvel onglet"})
                });
                const data = await res.json();
                if (data.success) {
                    currentTabId = data.id; 
                    await loadTabs(); 
                    // Auto open editor
                    const titleSpan = document.querySelector(`.tab-button.active .tab-title-span`);
                    if(titleSpan) renameTabInline(data.id, titleSpan, new Event('click'));
                } else { alert(data.error); }
            } catch(e) { console.error(e); }
        }

        function triggerRename(id, e) {
            e.stopPropagation();
            const tabButton = e.target.closest('.tab-button');
            const titleSpan = tabButton.querySelector('.tab-title-span');
            renameTabInline(id, titleSpan, e);
        }

        async function renameTabInline(id, element, e) {
            if (e) e.stopPropagation();
            if (element.querySelector('input')) return;
            
            const oldTitle = element.innerText;
            element.innerHTML = '<input type="text">';
            element.querySelector('input').value = oldTitle;
            const input = element.querySelector('input');
            input.focus();
            input.select();
            
            input.addEventListener('click', ev => ev.stopPropagation());
            
            const submitRename = async () => {
                const newTitle = input.value.trim();
                element.textContent = newTitle || oldTitle;

                if (newTitle && newTitle !== oldTitle) {
                    try {
                        const res = await api('api_tabs.php', {
                            method: 'PUT',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({id: id, title: newTitle})
                        });
                        const data = await res.json();
                        if (data.success) {
                            loadTabs();
                        } else { alert(data.error); loadTabs(); }
                    } catch(e) { console.error(e); }
                }
            };
            
            input.addEventListener('blur', submitRename);
            input.addEventListener('keydown', (ev) => {
                if(ev.key === 'Enter') input.blur();
                if(ev.key === 'Escape') { input.value = oldTitle; input.blur(); }
            });
        }

        async function deleteTab(id) {
            const tab = tabsById[id];
            const title = tab ? tab.title : '';
            if (!confirm(`Supprimer l'onglet "${title}" et tous ses widgets ?`)) return;

            try {
                const res = await api(`api_tabs.php?id=${Number(id)}`, { method: 'DELETE' });
                const data = await res.json();
                if (data.success) {
                    loadTabs();
                } else { alert(data.error); }
            } catch(e) { console.error(e); }
        }

        // ========= 2. FONCTION POUR CHARGER LES WIDGETS =========
        // Le verrou isTabLoading est tenu par loadTabs(), seul appelant.
        async function loadWidgets(tabId) {
            if (!tabId) return;
            loadedTabId = null; // la grille ne correspond à aucun onglet pendant le chargement
            try {
                // On vide la grille existante
                grid.removeAll();

                const response = await fetch(`api_widgets.php?tab_id=${Number(tabId)}`);
                const widgets = await response.json();

                for (let w of widgets) {
                    await renderWidget(w);
                }
                loadedTabId = tabId; // la grille reflète maintenant cet onglet
                if (typeof rebuildFilterBar === 'function') rebuildFilterBar();
                syncGridEditability();
            } catch (error) {
                console.error("Erreur lors du chargement des widgets :", error);
            }
        }

        // Le chargement et l'application du fond d'écran vivent désormais dans
        // assets/background.js : loadBackground() y est défini sur window.

        // Vignette de remplacement quand un article n'a pas d'image.
        // La teinte est dérivée du nom d'hôte : deux articles du même site
        // produisent la même couleur, ce qui aide à lire la grille.
        function feedPlaceholder(sourceUrl, label, width, height) {
            let host = '';
            try {
                host = new URL(/^https?:\/\//i.test(sourceUrl) ? sourceUrl : 'https://' + sourceUrl)
                    .hostname.replace(/^www\./, '');
            } catch (e) { host = ''; }

            const seed = host || String(label || '?');
            let hash = 0;
            for (let i = 0; i < seed.length; i++) {
                hash = (hash * 31 + seed.charCodeAt(i)) >>> 0;
            }
            const hue = hash % 360;

            const initials = (seed.replace(/[^a-z0-9]/gi, '').slice(0, 2) || '?').toUpperCase();

            return `<svg class="rss-placeholder" viewBox="0 0 60 45" width="${Number(width)}" height="${Number(height)}" aria-hidden="true">
                        <rect width="60" height="45" fill="hsl(${hue}, 42%, 84%)"/>
                        <text x="30" y="29" text-anchor="middle" font-size="17" font-weight="600"
                              font-family="system-ui, sans-serif" fill="hsl(${hue}, 45%, 33%)">${esc(initials)}</text>
                    </svg>`;
        }

        // Lecture d'un épisode : le bouton cède la place à un lecteur au clic.
        // Aucun <audio> n'est créé tant qu'on n'en demande pas, ce qui évite
        // autant de connexions que d'épisodes affichés.
        document.addEventListener('click', function (event) {
            const button = event.target.closest('.rss-audio-btn');
            if (!button) return;

            event.preventDefault();
            const url = safeUrl(button.dataset.audio);
            if (!url) return;

            const player = document.createElement('audio');
            player.className = 'rss-audio-player';
            player.controls = true;
            player.preload = 'none';
            player.src = url;
            button.replaceWith(player);
            player.play().catch(() => {});
        });

        // Document minimal autour du code d'un widget HTML.
        // <base target="_blank"> est nécessaire : le bac à sable interdit la
        // navigation du cadre parent, donc un lien sans cible resterait sans effet.
        function wrapHtmlWidget(code) {
            return '<!DOCTYPE html><html><head><meta charset="utf-8">'
                 + '<meta name="viewport" content="width=device-width, initial-scale=1">'
                 + '<base target="_blank">'
                 + '<style>html,body{margin:0;padding:8px;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;'
                 + 'font-size:13px;color:#222;background:#fff;box-sizing:border-box;}'
                 + 'img,iframe,video{max-width:100%;}</style></head><body>'
                 + code
                 + '</body></html>';
        }

        // ========= 4. FONCTION POUR CONSTRUIRE LE WIDGET (UI DE PROTOPAGE) =========
        async function renderWidget(widgetData) {
            
            const color = (widgetData.settings && widgetData.settings.color) ? widgetData.settings.color : '#e8e4c7';
            const bodyColor = color; // On force le fond du body à être pareil pour l'harmonie, ou au pire pastel
            const limit = (widgetData.settings && widgetData.settings.limit) ? widgetData.settings.limit : 5;
            const displayMode = (widgetData.settings && widgetData.settings.display) ? widgetData.settings.display : 'previews';

            const controlsHtml = isLoggedIn ? `
                    <div class="widget-controls" style="display:flex; align-items:baseline; font-size:11px; color:#666;">
                        <span style="cursor: pointer; margin-right: 8px;" onclick="openWidgetModal('${widgetData.id}')">edit</span>
                        <span style="font-weight: bold; cursor: pointer; color:#444;" onclick="removeWidget('${widgetData.id}')">x</span>
                    </div>
            ` : '';

            // Favicon extraction
            let faviconHtml = '<span>📰</span>';
            if (widgetData.settings && widgetData.settings.url) {
                try {
                    const domain = new URL((widgetData.settings.url.startsWith('http') ? '' : 'https://') + widgetData.settings.url).hostname;
                    faviconHtml = `<img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(domain)}&sz=32" style="width:14px; height:14px; vertical-align:middle; margin-right:4px;" alt="">`;
                } catch(e) {}
            }

            // Création de la structure HTML du widget à la protopage
            const widgetTitle = widgetData.settings && widgetData.settings.title ? widgetData.settings.title : (widgetData.type === 'note' ? 'Note personnelle' : (widgetData.type==='youtube' ? 'Vidéos' : (widgetData.type==='image' ? 'Photos' : (widgetData.type==='html' ? 'Widget' : 'Actualités'))));
            
            let widgetContent = `<div class="widget-body" id="rss-body-${widgetData.id}">Chargement...</div>`;
            
            if (widgetData.type === 'note') {
                const noteText = widgetData.settings && widgetData.settings.text ? widgetData.settings.text : 'Cliquez ici pour écrire une note...';
                widgetContent = `<div class="widget-body" style="padding:15px; font-size:14px; color:#222; outline:none;" id="note-body-${widgetData.id}">${noteText}</div>`;
                faviconHtml = '<span>📝</span>';
            } else if (widgetData.type === 'youtube') {
                const url = widgetData.settings && widgetData.settings.url ? widgetData.settings.url : '';
                let videoId = '';
                const match = url.match(/(?:\?v=|\/embed\/|\/watch\?v=|\/youtu\.be\/|\/v\/|\/e\/|watch\?v=|v=|youtu\.be\/|\/shorts\/)([^#\&\?]*).*/);
                if (match && match[1]) { videoId = match[1]; }
                
                faviconHtml = '<span>▶️</span>';
                // L'identifiant doit être strictement alphanumérique avant d'entrer dans un src.
                if (!/^[A-Za-z0-9_-]{5,20}$/.test(videoId)) { videoId = ''; }

                if (videoId) {
                    widgetContent = `<div class="widget-body" id="rss-body-${widgetData.id}" style="background:#000; overflow:hidden;">
                        <iframe width="100%" height="100%" src="https://www.youtube.com/embed/${videoId}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                    </div>`;
                } else {
                    widgetContent = `<div class="widget-body" style="padding:15px; color:#666; font-size:12px;">Aucune URL YouTube configurée. Cliquez sur edit.</div>`;
                }
            } else if (widgetData.type === 'html') {
                faviconHtml = '<span>🧩</span>';
                const code = (widgetData.settings && widgetData.settings.html) ? widgetData.settings.html : '';

                if (code.trim()) {
                    // Isolation, pas assainissement.
                    //
                    // sandbox sans allow-same-origin donne à l'iframe une origine
                    // opaque : le code ne peut atteindre ni le DOM parent, ni les
                    // cookies, ni le stockage du domaine, ni le jeton CSRF.
                    //
                    // allow-scripts et allow-same-origin ne doivent JAMAIS figurer
                    // ensemble : combinés, ils permettraient au code de retirer son
                    // propre bac à sable, ce qui annulerait toute la protection.
                    widgetContent = `<div class="widget-body" id="rss-body-${widgetData.id}" style="padding:0; overflow:hidden;">
                        <iframe class="html-widget-frame"
                                sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms"
                                referrerpolicy="strict-origin-when-cross-origin"
                                loading="lazy"
                                title="${esc(widgetTitle)}"
                                srcdoc="${esc(wrapHtmlWidget(code))}"></iframe>
                    </div>`;
                } else {
                    widgetContent = `<div class="widget-body html-widget-empty">Aucun code saisi. Cliquez sur edit.</div>`;
                }
            } else if (widgetData.type === 'image') {
                faviconHtml = '<span>📷</span>';
                const url = safeUrl(widgetData.settings && widgetData.settings.url);
                if (url) {
                    widgetContent = `<div class="widget-body" id="rss-body-${widgetData.id}" style="background:#000; text-align:center;">
                        <img src="${esc(url)}" style="max-width:100%; max-height:100%; width:100%; height:100%; object-fit:contain; object-position:center; display:block;" alt="">
                    </div>`;
                } else {
                    widgetContent = `<div class="widget-body" style="padding:15px; color:#666; font-size:12px;">Aucune image uploadée. Cliquez sur edit.</div>`;
                }
            }

            const widgetTags = (widgetData.settings && Array.isArray(widgetData.settings.tags))
                ? widgetData.settings.tags.slice(0, 3) : [];
            const tagsHtml = widgetTags.length
                ? `<span class="widget-tags">${widgetTags.map(t => `<span class="widget-tag">${esc(t)}</span>`).join('')}</span>`
                : '';

            const widgetHtml = `
                <div class="widget-header" style="background-color: ${esc(color)};">
                    <div style="font-weight:normal;">${faviconHtml} <span>${esc(widgetTitle)}</span>${tagsHtml}</div>
                    ${controlsHtml}
                </div>
                ${widgetContent}
            `;

            // On ajoute le widget à la grille
            const node = grid.addWidget({
                id: widgetData.id,
                x: widgetData.x, y: widgetData.y,
                w: widgetData.w, h: widgetData.h,
                content: widgetHtml,
                type: widgetData.type,
                settings: widgetData.settings
            });

            // Attributs lus par la barre de filtres. Les tags sont normalisés en
            // minuscules pour la comparaison ; les libellés d'origine sont
            // conservés à part pour l'affichage.
            // Selon la version de gridstack, addWidget() renvoie soit le nœud
            // interne (avec .el), soit directement l'élément du DOM. On accepte
            // les deux plutôt que de dépendre d'une version.
            const itemEl = (node && node.el) ? node.el
                         : ((node && node.nodeType === 1) ? node : null);

            if (itemEl) {
                itemEl.dataset.widgetType = widgetData.type;

                const tagList = (widgetData.settings && Array.isArray(widgetData.settings.tags))
                    ? widgetData.settings.tags : [];
                const labels = {};
                const keys = tagList.map(t => {
                    const key = String(t).toLowerCase();
                    labels[key] = String(t);
                    return key;
                });

                itemEl.dataset.tags = keys.join('|');
                itemEl.dataset.tagLabels = JSON.stringify(labels);
                itemEl.dataset.sourceLabel = widgetTitle;

                if (widgetData.type === 'rss' && widgetData.settings && widgetData.settings.url) {
                    try {
                        const raw = widgetData.settings.url;
                        itemEl.dataset.source = new URL(/^https?:\/\//i.test(raw) ? raw : 'https://' + raw)
                            .hostname.replace(/^www\./, '');
                    } catch (e) {
                        itemEl.dataset.source = widgetTitle;
                    }
                }
            }

            // Initialiser CKEditor pour le widget note si admin
            if (widgetData.type === 'note' && isLoggedIn) {
                // Petite pause pour s'assurer que l'élément est dans le DOM
                setTimeout(() => {
                    const noteElement = document.querySelector(`#note-body-${widgetData.id}`);
                    if (noteElement) {
                        InlineEditor
                            .create(noteElement, {
                                toolbar: [ 'heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'undo', 'redo' ]
                            })
                            .then( editor => {
                                // Sauvegarder lors de la perte de focus de l'éditeur
                                editor.ui.focusTracker.on( 'change:isFocused', ( evt, name, isFocused ) => {
                                    if ( !isFocused ) {
                                        saveNoteContent(widgetData.id, editor.getData());
                                    }
                                });
                            })
                            .catch( err => console.error( err ) );
                    }
                }, 100);
            }

            // Si c'est un flux RSS, on appelle notre proxy PHP !
            if (widgetData.type === 'rss' && widgetData.settings && widgetData.settings.url) {
                const bodyContainer = document.getElementById(`rss-body-${widgetData.id}`);
                
                // Appel asynchrone à notre API RSS
                fetch(`api_rss.php?url=${encodeURIComponent(widgetData.settings.url)}&limit=${encodeURIComponent(limit)}`)
                    .then(async res => {
                        const body = await res.text();
                        try {
                            return JSON.parse(body);
                        } catch (e) {
                            // Réponse non-JSON : on remonte le début du corps,
                            // qui contient en général le message d'erreur PHP.
                            return { error: `Réponse inattendue du serveur (HTTP ${res.status}) : ${body.slice(0, 200)}` };
                        }
                    })
                    .then(data => {
                        // Renseigné par api_rss.php : article, podcast ou youtube.
                        const host = bodyContainer.closest('.grid-stack-item');
                        if (host && data && data.feed_type) {
                            host.dataset.feedType = data.feed_type;
                        }

                        if (data && data.items) {
                            const limit = (widgetData.settings && widgetData.settings.limit) ? widgetData.settings.limit : 5;
                            const displayType = (widgetData.settings && widgetData.settings.display) ? widgetData.settings.display : 'previews';
                            const widgetMode = (widgetData.settings && widgetData.settings.mode) ? widgetData.settings.mode : 'normal';

                            const itemsToShow = data.items.slice(0, limit);
                            let rssHtml = '';
                            
                            if (widgetMode === 'photos' || widgetMode === 'single') {
                                // Flex layout pour les photos
                                bodyContainer.style.display = 'flex';
                                bodyContainer.style.flexWrap = 'wrap';
                                bodyContainer.style.padding = '5px';
                            }

                            // Tout champ ci-dessous provient d'un site tiers : rien n'entre
                            // dans le HTML sans passer par esc(), et les adresses par safeUrl().
                            itemsToShow.forEach(item => {
                                const title      = esc(item.title);
                                const summary    = esc(item.description || '');
                                const dateAttr   = esc(item.date || '');
                                const link       = esc(safeUrl(item.link));
                                const imgUrl     = esc(safeUrl(item.image_url));
                                const audioUrl   = esc(safeUrl(item.audio_url));
                                const duration   = esc(item.duration || '');
                                const readClass  = item.read ? ' rss-read' : '';

                                // Un épisode de podcast n'a souvent ni lien d'article ni page
                                // dédiée : il reste écoutable, donc on l'affiche. On n'écarte
                                // que ce qui n'offre ni lien ni audio.
                                if (!link && !audioUrl) return;

                                // Sans lien, le titre reste un simple texte : un <a href="">
                                // vide rechargerait la page au clic.
                                const openAttrs = link
                                    ? `href="${link}" target="_blank" rel="noopener noreferrer"`
                                    : 'role="text"';
                                const titleTag = link ? 'a' : 'span';

                                if (widgetMode === 'photos' || widgetMode === 'single') {
                                    const w = widgetMode === 'single' ? '100%' : '80px';
                                    const h = widgetMode === 'single' ? 'auto' : '60px';
                                    // Plus d'article escamoté faute d'image : la vignette prend le relais.
                                    const visual = imgUrl
                                        ? `<img src="${imgUrl}" alt="" style="width: ${w}; height: ${h}; object-fit: cover; border-radius: 4px; opacity:0.9;">`
                                        : feedPlaceholder(widgetData.settings.url, title, widgetMode === 'single' ? 220 : 80, widgetMode === 'single' ? 140 : 60);

                                    rssHtml += `<${titleTag} ${openAttrs} title="${title}" class="rss-title-link rss-photo-link${readClass}" data-summary="${summary}" data-image="${imgUrl}" data-date="${dateAttr}" style="display:inline-block; margin:2px; width:${widgetMode==='single'?'100%':'auto'};">
                                                  ${visual}
                                                </${titleTag}>`;
                                } else {
                                    const imagePart = imgUrl
                                        ? `<img src="${imgUrl}" alt="" style="width: 45px; height: 35px; object-fit: cover; border-radius: 3px; opacity:0.9;">`
                                        : feedPlaceholder(widgetData.settings.url, title, 45, 35);

                                    const textPart = displayType === 'titles'
                                        ? `<${titleTag} ${openAttrs} class="rss-title-link${readClass}" data-summary="${summary}" data-image="${imgUrl}" data-date="${dateAttr}" style="line-height:35px; display:block;">${title}</${titleTag}>`
                                        : `<${titleTag} ${openAttrs} class="rss-title-link${readClass}" data-summary="${summary}" data-image="${imgUrl}" data-date="${dateAttr}">${title}</${titleTag}>
                                           <div style="font-size: 11px; color: #777; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%;">${summary}</div>`;

                                    const audioPart = audioUrl
                                        ? `<button type="button" class="rss-audio-btn" data-audio="${audioUrl}">▶ ${duration || 'Écouter'}</button>`
                                        : '';

                                    rssHtml += `
                                        <div class="rss-item">
                                            ${imagePart}
                                            <div style="flex: 1; min-width: 0;">
                                                ${textPart}
                                                ${audioPart}
                                            </div>
                                        </div>`;
                                }
                            });
                            bodyContainer.innerHTML = rssHtml;
                            
                            // === LE MOTEUR D'APERÇU AU HOVER ===
                            // Une fois la liste chargée, on applique l'écouteur d'événement au survol
                            applyHoverPreviews(bodyContainer);

                            // Les articles viennent d'arriver : la recherche doit les voir.
                            if (typeof rebuildFilterBar === 'function') rebuildFilterBar();
                        } else if (data && data.error) {
                            bodyContainer.textContent = data.error;
                        } else {
                            bodyContainer.textContent = "Ce flux n'a renvoyé aucun article.";
                        }
                    })
                    .catch(err => {
                        console.error('api_rss.php :', err);
                        bodyContainer.textContent = "Flux injoignable : " + err.message;
                    });
            } else if (widgetData.type === 'bookmarks') {
                const bodyContainer = document.getElementById(`rss-body-${widgetData.id}`);
                const links = (widgetData.settings && widgetData.settings.links) ? widgetData.settings.links : [];
                
                let html = '';
                links.forEach(link => {
                    let linkUrl = String(link.url || '').trim();
                    if (linkUrl && !/^https?:\/\//i.test(linkUrl)) {
                        // Un schéma autre que http(s) est écarté, pas complété.
                        if (/^[a-z][a-z0-9+.-]*:/i.test(linkUrl)) return;
                        linkUrl = 'https://' + linkUrl.replace(/^\/+/, '');
                    }
                    linkUrl = safeUrl(linkUrl);
                    if (!linkUrl) return;

                    let iconHtml = '<span style="margin-right:8px;">🌐</span>';
                    try {
                        const domain = new URL(linkUrl).hostname;
                        iconHtml = `<img src="https://www.google.com/s2/favicons?domain=${encodeURIComponent(domain)}&sz=16" alt="" style="width:16px; height:16px; vertical-align:middle; margin-right:8px;">`;
                    } catch(e) {}

                    const label = esc(link.label || linkUrl);

                    html += `
                        <div style="margin-bottom:8px; padding-bottom:8px; border-bottom:1px dashed #dcdcd1; display:flex; align-items:center;">
                            ${iconHtml}
                            <a href="${esc(linkUrl)}" target="_blank" rel="noopener noreferrer" style="color:#214371; text-decoration:none; font-size:13px;" onmouseover="this.style.textDecoration='underline'; this.style.color='#8ab680'" onmouseout="this.style.textDecoration='none'; this.style.color='#214371'">${label}</a>
                        </div>
                    `;
                });
                
                if (links.length === 0) {
                    html = "<div style='color:#777; font-size:12px; font-style:italic;'>Aucun favori configuré. Cliquez sur edit.</div>";
                }
                bodyContainer.innerHTML = html;
            }
        }

        // ========= 5. FONCTION POUR GÉRER L'APERÇU AU HOVER =========
        function applyHoverPreviews(container) {
            const links = container.querySelectorAll('.rss-title-link');
            
            links.forEach(link => {
                link.addEventListener('mouseenter', function(e) {
                    const title = this.innerText;
                    const summary = this.getAttribute('data-summary');
                    const imageUrl = this.getAttribute('data-image');
                    const dateStr = this.getAttribute('data-date');
                    
                    // --- Mettre à jour la couleur pour correspondre au parent ---
                    const widgetHeader = this.closest('.grid-stack-item-content').querySelector('.widget-header');
                    if(widgetHeader && widgetHeader.style.backgroundColor) {
                        document.documentElement.style.setProperty('--bubble-bg', widgetHeader.style.backgroundColor);
                    } else {
                        document.documentElement.style.setProperty('--bubble-bg', '#d1df8e');
                    }
                    
                    hoverPreview.style.display = 'flex';
                    
                    // Remplir l'aperçu avec les données locales (immédiat)
                    hoverPreview.querySelector('.preview-title').textContent = title || "Sans titre";
                    hoverPreview.querySelector('.preview-summary').textContent = (summary || "").substring(0, 180) + "...";
                    
                    const dateEl = hoverPreview.querySelector('.preview-date');
                    if(dateStr) {
                        const d = new Date(dateStr);
                        dateEl.textContent = !isNaN(d) ? d.toLocaleDateString() + ' à ' + d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) : dateStr;
                        dateEl.style.display = 'block';
                    } else {
                        dateEl.style.display = 'none';
                    }
                    
                    const img = hoverPreview.querySelector('.preview-image');
                    if (imageUrl) {
                        img.src = imageUrl;
                        img.style.display = 'block';
                    } else {
                        img.style.display = 'none';
                    }

                    // Positionner l'aperçu au-dessus du curseur avec un léger décalage
                    // On doit le mesurer APRES l'affichage (display:flex) pour avoir ses vraies dimensions
                    const bubbleHeight = hoverPreview.offsetHeight;
                    const bubbleWidth = hoverPreview.offsetWidth;
                    
                    hoverPreview.style.top = `${e.pageY - bubbleHeight - 15}px`;
                    hoverPreview.style.left = `${e.pageX - (bubbleWidth / 2)}px`;
                });

                link.addEventListener('mouseleave', function() {
                    hoverPreview.style.display = 'none';
                });
            });
        }

        // ========= 6. FONCTION DE SAUVEGARDE AUTO DES WIDGETS =========
        grid.on('change', function(event, items) {
            if (!items || !isLoggedIn || isTabLoading) return;
            saveGridState();
        });

        // Suppression native
        grid.on('removed', function(event, items) {
           if (isLoggedIn && !isTabLoading) saveGridState();
        });

        function saveGridState() {
            if (!isLoggedIn || !currentTabId) return Promise.resolve(); // Seul l'admin sauvegarde

            // Garde-fou : sur écran étroit, gridstack recalcule x et w pour
            // tenir en 1 ou 6 colonnes. Enregistrer ces valeurs écraserait
            // définitivement la disposition établie sur grand écran.
            if (!gridIsReference()) {
                console.warn('Sauvegarde ignorée : grille repliée (mode étroit).');
                return Promise.resolve();
            }

            // Garde-fou : on n'écrit que si la grille affichée correspond bien à
            // l'onglet courant. Sinon la sauvegarde porterait les widgets d'un
            // onglet sur un autre.
            if (loadedTabId !== currentTabId) {
                console.warn('Sauvegarde ignorée : grille et onglet désynchronisés.');
                return Promise.resolve();
            }
            // Utiliser la map directe assure que la taille w/h de rétrécissement est envoyée avec certitude,
            // et empêche grid.save() d'omettre les propriétés cruciales personnalisées.
            const savedData = grid.engine.nodes.map(n => ({
                id: n.id,
                x: n.x, y: n.y, w: n.w, h: n.h,
                type: n.type || 'text',
                settings: n.settings || {}
            }));

            return api(`api_widgets.php?tab_id=${Number(currentTabId)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(savedData)
            });
        }

        // Action sur la blur d'une note
        function saveNoteContent(id, contentData) {
            if (!isLoggedIn) return;
            const node = grid.engine.nodes.find(n => String(n.id) === String(id));
            if (node) {
                if (!node.settings) node.settings = {};
                node.settings.text = typeof contentData === 'string' ? contentData : contentData.innerHTML;
                saveGridState();
            }
        }

        function removeWidget(id) {
            if (!confirm('Supprimer ce widget ?')) return;
            const node = grid.engine.nodes.find(n => String(n.id) === String(id));
            if (node) {
                grid.removeWidget(node.el);
            }
        }

        /* ===== GESTION DES LISTES BOOKMARKS ====== */
        function renderBookmarksEditor(links) {
            const container = document.getElementById('bookmarks-list-editor');
            container.innerHTML = '';
            links.forEach(link => addBookmarkRow(link.label, link.url));
        }

        function addBookmarkRow(label, url) {
            const container = document.getElementById('bookmarks-list-editor');
            const row = document.createElement('div');
            row.className = 'bookmark-edit-row';
            row.innerHTML = `
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <button class="bookmark-btn" onclick="moveBookmark(this, -1)" title="Monter">▲</button>
                    <button class="bookmark-btn" onclick="moveBookmark(this, 1)" title="Descendre">▼</button>
                </div>
                <input type="text" class="bookmark-label-input" style="flex:1;" placeholder="Titre du lien (ex: Google)">
                <input type="text" class="bookmark-url-input" style="flex:1;" placeholder="https://...">
                <button class="bookmark-btn" style="color:red; font-size:13px;" onclick="this.parentElement.remove()" title="Supprimer">✖</button>
            `;
            // Valeurs posées par propriété et non par interpolation HTML.
            row.querySelector('.bookmark-label-input').value = label || '';
            row.querySelector('.bookmark-url-input').value = url || '';
            container.appendChild(row);
        }

        function moveBookmark(btn, dir) {
            const row = btn.closest('.bookmark-edit-row');
            if (dir === -1 && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (dir === 1 && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            }
        }

        async function uploadWidgetImage(inputElement) {
            const file = inputElement.files[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('widget_image', file);
            formData.append('csrf_token', CSRF_TOKEN); // multipart : pas de préflight, jeton en champ

            const statusEl = document.getElementById('edit-widget-image-status');
            statusEl.innerText = 'Téléchargement en cours...';
            statusEl.style.display = 'block';
            statusEl.style.color = '#777';

            try {
                const response = await api('api_upload_widget_image.php', { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    document.getElementById('edit-widget-image-url').value = data.url;
                    statusEl.innerText = 'Image téléchargée avec succès !';
                    statusEl.style.color = 'green';
                } else {
                    statusEl.innerText = 'Erreur : ' + data.error;
                    statusEl.style.color = 'red';
                }
            } catch (err) { console.error(err); }
        }

        /* ===== GESTION DU MODAL WIDGET ====== */
        const pastelColors = ['#e8e4c7','#d2e1b4','#a2d2df','#c7a3c3','#e6b2c6','#eac59b','#e4dfd0','#d9d3ac','#b8d3c1','#e6c8ce'];
        let editingWidgetId = null;

        function renderColorSwatches(selectedColor) {
            const container = document.getElementById('edit-widget-colors');
            container.innerHTML = '';
            pastelColors.forEach(color => {
                const isSelected = (color === selectedColor) ? 'selected' : '';
                container.innerHTML += `<div class="color-swatch ${isSelected}" style="background-color: ${color};" onclick="selectModalColor('${color}')" data-color="${color}"></div>`;
            });
        }

        function selectModalColor(color) {
            document.querySelectorAll('#edit-widget-colors .color-swatch').forEach(el => el.classList.remove('selected'));
            document.querySelector(`.color-swatch[data-color="${color}"]`).classList.add('selected');
            document.querySelector('.edit-modal-content').style.backgroundColor = color;
        }

        function openWidgetModal(id) {
            editingWidgetId = id;
            const node = grid.engine.nodes.find(n => String(n.id) === String(id));
            if (!node || !node.settings) return;
            
            document.getElementById('edit-widget-title').value = node.settings.title || (node.type === 'bookmarks' ? 'Favoris' : (node.type === 'note' ? 'Note personnelle' : (node.type === 'youtube' ? 'Vidéo Youtube' : (node.type === 'image' ? 'Images' : (node.type === 'html' ? 'Widget' : 'Actualités')))));
            
            // Masquer d'abord toutes les sous-sections
            document.getElementById('rss-settings-section').style.display = 'none';
            document.getElementById('bookmarks-settings-section').style.display = 'none';
            document.getElementById('youtube-settings-section').style.display = 'none';
            document.getElementById('image-settings-section').style.display = 'none';
            document.getElementById('html-settings-section').style.display = 'none';
            document.getElementById('tags-settings-section').style.display =
                (node.type === 'rss' || !node.type) ? 'block' : 'none';
            document.getElementById('edit-widget-tags').value =
                Array.isArray(node.settings.tags) ? node.settings.tags.join(', ') : '';

            if (node.type === 'html') {
                document.getElementById('html-settings-section').style.display = 'block';
                document.getElementById('edit-widget-html').value = node.settings.html || '';
            } else if (node.type === 'bookmarks') {
                document.getElementById('bookmarks-settings-section').style.display = 'block';
                const links = node.settings.links || [{label: '', url: ''}];
                renderBookmarksEditor(links);
            } else if (node.type === 'youtube') {
                document.getElementById('youtube-settings-section').style.display = 'block';
                document.getElementById('edit-widget-youtube').value = node.settings.url || '';
            } else if (node.type === 'image') {
                document.getElementById('image-settings-section').style.display = 'block';
                document.getElementById('edit-widget-image-url').value = node.settings.url || '';
                document.getElementById('edit-widget-image-upload').value = ''; // Reset input file
                document.getElementById('edit-widget-image-status').style.display = 'none';
            } else if (node.type === 'note') {
                // Rien à afficher (Title + Color Only)
            } else {
                document.getElementById('rss-settings-section').style.display = 'block';
                
                document.getElementById('edit-widget-limit').value = node.settings.limit || 5;
                document.getElementById('edit-widget-url').value = node.settings.url || '';
                
                const display = node.settings.display || 'previews';
                document.querySelector(`input[name="edit-display"][value="${display}"]`).checked = true;

                const mode = node.settings.mode || 'normal';
                document.querySelector(`input[name="edit-mode"][value="${mode}"]`).checked = true;
            }

            const color = node.settings.color || '#e8e4c7';
            renderColorSwatches(color);
            document.querySelector('.edit-modal-content').style.backgroundColor = color;

            document.getElementById('widget-edit-modal').style.display = 'flex';
        }

        function closeWidgetModal() {
            document.getElementById('widget-edit-modal').style.display = 'none';
            editingWidgetId = null;
        }

        function saveWidgetModal() {
            if (!editingWidgetId) return;
            const node = grid.engine.nodes.find(n => String(n.id) === String(editingWidgetId));
            if (node) {
                if (!node.settings) node.settings = {};
                
                node.settings.title = document.getElementById('edit-widget-title').value.trim();
                
                if (node.type === 'bookmarks') {
                    const rows = document.querySelectorAll('.bookmark-edit-row');
                    const links = [];
                    rows.forEach(row => {
                         const label = row.querySelector('.bookmark-label-input').value.trim();
                         const url = row.querySelector('.bookmark-url-input').value.trim();
                         if (label || url) { links.push({label, url}); }
                    });
                    node.settings.links = links;
                } else if (node.type === 'youtube') {
                    node.settings.url = document.getElementById('edit-widget-youtube').value.trim();
                } else if (node.type === 'html') {
                    node.settings.html = document.getElementById('edit-widget-html').value;
                } else if (node.type === 'image') {
                    node.settings.url = document.getElementById('edit-widget-image-url').value.trim();
                } else if (node.type === 'rss') {
                    node.settings.limit = parseInt(document.getElementById('edit-widget-limit').value) || 5;
                    node.settings.url = document.getElementById('edit-widget-url').value.trim();
                    node.settings.display = document.querySelector('input[name="edit-display"]:checked').value;
                    node.settings.mode = document.querySelector('input[name="edit-mode"]:checked').value;
                    // Les tags sont normalisés côté serveur (casse, doublons, plafond de 8).
                    node.settings.tags = document.getElementById('edit-widget-tags').value
                        .split(',').map(t => t.trim()).filter(Boolean);
                }
                // Pour type === 'note', on ne sauvegarde que le titre/couleur fait plus haut
                
                const selectedSwatch = document.querySelector('#edit-widget-colors .selected');
                if(selectedSwatch) {
                    node.settings.color = selectedSwatch.getAttribute('data-color');
                }

                saveGridState().then(() => {
                    location.reload(); // Rechargement pour re-rendu propre
                });
            }
        }

        // ========= 7. GESTION DES MODALS ET DE L'UPLOAD DE FOND =========
        async function addWidget(type) {
            const newWidget = { type: type, x: 0, y: 0, w: 3, h: 4, settings: {} };
            
            if (type === 'rss') {
                newWidget.settings.url = 'https://www.lemonde.fr/rss/une.xml';
                newWidget.settings.title = 'Actualités';
            } else if (type === 'bookmarks') {
                newWidget.settings.title = 'Mes Favoris';
                newWidget.settings.links = [
                    {label: 'Google', url: 'https://google.com'}
                ];
            } else if (type === 'note') {
                newWidget.settings.title = 'Note personnelle';
                newWidget.settings.text = 'Cliquez ici pour rédiger votre note...';
            } else if (type === 'youtube') {
                newWidget.settings.title = 'Vidéos';
                newWidget.settings.url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'; // Rickroll default
                newWidget.w = 4; newWidget.h = 4;
            } else if (type === 'html') {
                newWidget.settings.title = 'Widget';
                newWidget.settings.html = '<div style="text-align:center; padding:20px;">\n  Collez ici le code de votre widget.\n</div>';
                newWidget.w = 3; newWidget.h = 4;
            } else if (type === 'image') {
                newWidget.settings.title = 'Photos';
                newWidget.settings.url = '';
                newWidget.w = 3; newWidget.h = 4;
            }
            
            try {
                // Créer le widget en BDD d'abord pour avoir un VRAI ID persistant, ce qui corrige
                // le problème de perte de dimension "w" et "h" si redimensionné sans ID assigné !
                const res = await api(`api_create_widget.php?tab_id=${Number(currentTabId)}`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(newWidget)
                });
                const data = await res.json();
                
                if (data.success && data.id) {
                    newWidget.id = data.id;
                    // On adopte les réglages tels que le serveur les a normalisés,
                    // pour que le client et la base ne divergent jamais.
                    if (data.settings) { newWidget.settings = data.settings; }
                    renderWidget(newWidget).then(() => {
                        const node = grid.engine.nodes.find(n => n.id === newWidget.id);
                        if (node) { grid.trigger('change', [node]); }
                    });
                } else {
                    alert("Erreur lors de la persistance initiale !");
                }
            } catch(e) { console.error(e); }
        }



        // ========= DÉMARRAGE GLOBAL =========
        document.addEventListener('DOMContentLoaded', () => {
            // 1. Charger l'image de fond
            loadBackground();
            // 2. Charger les ONGLET (qui chargera ensuite les widgets associés)
            loadTabs();
            syncGridEditability();
        });

        function openAboutModal() { document.getElementById('about-modal').style.display = 'flex'; }
        function closeAboutModal() { document.getElementById('about-modal').style.display = 'none'; }

    </script>

    <!-- Chargés après le script principal : ils s'appuient sur ses fonctions globales. -->
    <script src="assets/menu.js"></script>
    <script src="assets/opml.js"></script>
    <script src="assets/read-state.js"></script>
    <script src="assets/filters.js"></script>
    <script src="assets/background.js"></script>
</body>
</html>