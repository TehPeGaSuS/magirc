# Translating MagIRC

The interface strings are English and pass through gettext. MagIRC loads
`locale/<locale>/LC_MESSAGES/magirc.mo` (or `magirc.po`, compiled on the
fly) for the locale set in `display.locale`.

Strings are marked in the templates with `{{ 'Text'|t }}`, `__('Text')` and
`_n('singular', 'plural', n)`, and in PHP through `Translator::t()`.

To create or refresh a translation:

1. Extract the template:

       xgettext --language=Python --keyword=t --keyword=__ --keyword=_n:1,2 \
           --from-code=UTF-8 -o locale/magirc.pot templates/default/*.twig
       xgettext --language=PHP --keyword=t --keyword=plural:1,2 -j \
           --from-code=UTF-8 -o locale/magirc.pot src/Web/*.php

2. Start or update your language:

       msginit -l pt_PT -i locale/magirc.pot -o locale/pt_PT/LC_MESSAGES/magirc.po   # new
       msgmerge -U locale/pt_PT/LC_MESSAGES/magirc.po locale/magirc.pot             # update

3. Translate the `.po` file with any editor (Poedit, Lokalize, a text editor)
   and compile it:

       msgfmt -o locale/pt_PT/LC_MESSAGES/magirc.mo locale/pt_PT/LC_MESSAGES/magirc.po

The 1.x translations do not carry over; the strings changed too much.
