#!/usr/bin/env python3
"""Extract translatable strings from the templates and PHP sources into
locale/magirc.pot. Run from the project root:

    python3 tools/extract-strings.py
    msgmerge -U locale/pt_PT/LC_MESSAGES/magirc.po locale/magirc.pot

Recognised forms:
  templates:  'text'|t   __('text')   _n('one', 'many', n)
  PHP:        ->t('text')   ->plural('one', 'many', n)   and the
              ['%d x ago', '%d xs ago'] pairs in TemplateExtension::ago()
"""
import glob
import re
import sys
from collections import OrderedDict
from datetime import datetime, timezone

ROOT = __import__("os").path.dirname(__import__("os").path.dirname(__import__("os").path.abspath(__file__)))
OUT = f"{ROOT}/locale/magirc.pot"

STR = r"'((?:[^'\\]|\\.)*)'"
entries: "OrderedDict[tuple, set]" = OrderedDict()


def add(msgid: str, plural: str | None, src: str) -> None:
    entries.setdefault((msgid.replace("\\'", "'"), plural.replace("\\'", "'") if plural else None), set()).add(src)


for f in sorted(glob.glob(f"{ROOT}/templates/**/*.twig", recursive=True)):
    text = open(f, encoding="utf-8").read()
    rel = f[len(ROOT) + 1:]
    for m in re.finditer(STR + r"\|t\b", text):
        add(m.group(1), None, rel)
    for m in re.finditer(r"__\(" + STR + r"\)", text):
        add(m.group(1), None, rel)
    for m in re.finditer(r"_n\(" + STR + r",\s*" + STR, text):
        add(m.group(1), m.group(2), rel)

for f in sorted(glob.glob(f"{ROOT}/src/**/*.php", recursive=True)):
    text = open(f, encoding="utf-8").read()
    rel = f[len(ROOT) + 1:]
    for m in re.finditer(r"->t\(" + STR + r"\)", text):
        add(m.group(1), None, rel)
    for m in re.finditer(r"->plural\(" + STR + r",\s*" + STR, text):
        add(m.group(1), m.group(2), rel)
    for m in re.finditer(r"\[" + STR + r", " + STR + r"\]\]", text):  # ago() unit pairs
        if "ago" in m.group(1):
            add(m.group(1), m.group(2), rel)
    # Status names and error page titles/messages are looked up by value.
    for m in re.finditer(r"'([~&@%+])' => '([a-z]+)'", text):
        add(m.group(2), None, rel)
    for m in re.finditer(r"\[\d{3}, " + STR + r", (?:[^,\]]*?\?: )?" + STR + r"\]", text):
        add(m.group(1), None, rel)
        add(m.group(2), None, rel)


def q(s: str) -> str:
    return '"' + s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n") + '"'


with open(OUT, "w", encoding="utf-8") as out:
    out.write(f'''# MagIRC translation template.
msgid ""
msgstr ""
"Project-Id-Version: MagIRC\\n"
"POT-Creation-Date: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M+0000')}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

''')
    for (msgid, plural), sources in entries.items():
        out.write("#: " + " ".join(sorted(sources)) + "\n")
        out.write(f"msgid {q(msgid)}\n")
        if plural:
            out.write(f"msgid_plural {q(plural)}\nmsgstr[0] \"\"\nmsgstr[1] \"\"\n\n")
        else:
            out.write('msgstr ""\n\n')

print(f"{len(entries)} strings written to {OUT}", file=sys.stderr)
