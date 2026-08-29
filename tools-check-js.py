#!/usr/bin/env python3
"""tools-check-js.py — garde-fou contre les suppressions accidentelles.

Vérifie que toute fonction appelée depuis le script inline d'index.php ou
depuis un attribut onclick du HTML est bien définie quelque part : dans le
script inline, ou exportée sur window par un fichier de assets/.

Usage : python3 tools-check-js.py
"""
import os
import re
import sys

src = open('index.php', encoding='utf-8').read()
inline = re.findall(r'<script>\n(.*?)</script>', src, re.S)[-1]


def strip_noise(code):
    """Retire commentaires et littéraux : sinon « affichage (…) » dans un
    commentaire français passe pour un appel de fonction."""
    code = re.sub(r'/\*.*?\*/', ' ', code, flags=re.S)
    code = re.sub(r'//[^\n]*', ' ', code)
    code = re.sub(r'`(?:\\.|[^`\\])*`', '``', code, flags=re.S)
    code = re.sub(r"'(?:\\.|[^'\\\n])*'", "''", code)
    code = re.sub(r'"(?:\\.|[^"\\\n])*"', '""', code)
    return code


# Les balises PHP courtes produisent des valeurs, pas du code JS.
inline = re.sub(r'<\?=.*?\?>', 'null', inline, flags=re.S)

clean = strip_noise(inline)

# Limite connue de strip_noise : les gabarits imbriqués (`… ${`…`} …`) ne sont
# pas correctement retirés, si bien que des mots français suivis d'une
# parenthèse à l'intérieur d'un gabarit ressortent comme des appels. On les
# ignore explicitement plutôt que d'écrire un vrai analyseur lexical.
KNOWN_FALSE_POSITIVES = {'serveur', 'affichage', 'afficher', 'lien', 'locales',
                         'défaut', 'interne', 'http', 'hsl'}

assets = ''
for name in sorted(os.listdir('assets')):
    assets += open(os.path.join('assets', name), encoding='utf-8').read()

defined = set(re.findall(r'function\s+([A-Za-z_$][\w$]*)\s*\(', clean))
defined |= set(re.findall(r'(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function|\()', clean))
defined |= set(re.findall(r'window\.([A-Za-z_$][\w$]*)\s*=', assets))
defined |= set(re.findall(r'function\s+([A-Za-z_$][\w$]*)\s*\(', assets))

BUILTINS = {
    'if', 'for', 'while', 'switch', 'catch', 'function', 'return', 'typeof', 'new',
    'await', 'async', 'delete', 'void', 'in', 'of', 'do', 'else', 'try',
    'Number', 'String', 'Boolean', 'Array', 'Object', 'JSON', 'Math', 'Date', 'URL',
    'URLSearchParams', 'FormData', 'FileReader', 'Blob', 'Promise', 'Error', 'RegExp',
    'Set', 'Map', 'WeakMap', 'Symbol', 'parseInt', 'parseFloat', 'isNaN', 'isFinite',
    'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval', 'requestAnimationFrame',
    'fetch', 'alert', 'confirm', 'prompt', 'encodeURI', 'decodeURI',
    'encodeURIComponent', 'decodeURIComponent', 'GridStack', 'InlineEditor', 'Event',
}

problems = []

called = set(re.findall(r'(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(', clean))
for name in sorted(called - defined - BUILTINS - KNOWN_FALSE_POSITIVES):
    problems.append(('script inline', name))

# Gestionnaires inline du HTML : onclick="maFonction(...)"
for handler in re.findall(r'on[a-z]+\s*=\s*"([^"]*)"', src):
    for name in re.findall(r'(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(', handler):
        if name not in defined and name not in BUILTINS:
            problems.append(('attribut HTML', name))

print('Fonctions définies : %d' % len(defined))

if problems:
    print('APPELS SANS DÉFINITION :')
    for where, name in sorted(set(problems)):
        print('   · %-14s %s' % (where, name))
    sys.exit(1)

print('Aucun appel orphelin.')
