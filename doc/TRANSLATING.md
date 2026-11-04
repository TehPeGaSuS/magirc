# Translating MagIRC

The interface strings are English and pass through gettext. MagIRC loads
`locale/<locale>/LC_MESSAGES/magirc.mo` (or `magirc.po`, compiled on the
fly) for the locale set in `display.locale`.

Strings are marked in the templates with `{{ 'Text'|t }}`, `__('Text')` and
`_n('singular', 'plural', n)`, and in PHP through `Translator::t()`.

To create or refresh a translation:

1. Extract the template:

       python3 tools/extract-strings.py        # writes locale/magirc.pot

2. Start or update your language:

       msginit -l pt_PT -i locale/magirc.pot -o locale/pt_PT/LC_MESSAGES/magirc.po   # new
       msgmerge -U locale/pt_PT/LC_MESSAGES/magirc.po locale/magirc.pot             # update

3. Translate the `.po` file with any editor (Poedit, Lokalize, a text editor)
   and compile it:

       msgfmt -o locale/pt_PT/LC_MESSAGES/magirc.mo locale/pt_PT/LC_MESSAGES/magirc.po

Commit both the `.po` and the compiled `.mo`; MagIRC prefers the `.mo` and
falls back to parsing the `.po` when only that exists.

Shipped translations: `pt_PT` (Portuguese, Portugal).

The 1.x translations do not carry over; the strings changed too much.
