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

## Ground rules

These keep the translations consistent with each other and with the site.

**Name the variant.** Use the full locale (`pt_PT`, `pt_BR`, `fr_FR`,
`fr_CA`) and follow that variant's spelling and vocabulary throughout. Set
`Plural-Forms` in the `.po` header for your language and fill in every
plural form.

**Leave alone what the site already localises.** Dates, month names,
numbers (thousands and decimal separators) and country names come from
ICU/CLDR and follow the selected language on their own; they are not in the
`.po` file. A country name can still be overridden by translating its
English name, but only do that for a real reason.

**Do not translate names.** NickServ, ChanServ, OperServ, channel and nick
names, IRC commands (`/msg …`, `SET CHANSTATS ON`), mode letters and
configuration keys stay as they are. Channel status names (`op`, `halfop`,
`voice`) are IRC jargon; keep them unless your language has an established
word (Portuguese keeps `op` but says `dono` for `owner`).

**Keep the placeholders.** Every `%s` and `%d` in the English must appear
in the translation. If your word order needs them swapped, number them:
`%2$s … %1$s`.

**Match the tone.** Sentence case, plain words, no exclamation marks. The
notes in italic are full sentences; labels and headings are not. Aim for a
length close to the English: table headers and buttons have little room.

**Read the notes.** Some entries in `magirc.pot` carry a `#.` comment
explaining where the string appears or what constrains it. Poedit and
Lokalize show these next to the string.

### Units and abbreviations

The table columns show durations in a compact form (`1mo 12d`, `5d 3h`,
`2h 10m`, `45s`), built from six strings: `%dy`, `%dmo`, `%dd`, `%dh`,
`%dm` and `%ds`. Languages do not agree on these letters (French writes
days as `j`, German as `T`, Turkish uses `d` for minutes), so each
translation picks its own, following these rules:

* Use the abbreviations your language already uses in writing; prefer the
  SI symbols where the language uses them (`h`, `min`, `s`).
* **All six must be different from each other.** Months and minutes are the
  usual clash: English uses `mo` and `m`, Portuguese `m` and `min`.
* Keep them short and without a trailing dot, unless your language requires
  one. Keep or drop the space between number and letter as your language
  does, but do the same for all six.

For reference:

| Unit | `en` | `pt_PT` |
|---|---|---|
| year | `%dy` | `%da` |
| month | `%dmo` | `%dm` |
| day | `%dd` | `%dd` |
| hour | `%dh` | `%dh` |
| minute | `%dm` | `%dmin` |
| second | `%ds` | `%ds` |

The longer forms ("1 month, 12 days", "10 years, 243 days ago") are full
words with plural forms; `%s, %s` joins the two parts and can become your
language's "and" (Portuguese: `%s e %s`).

## Before you submit

* `msgfmt -c --statistics` on your `.po` reports no errors, no fuzzy and no
  untranslated strings.
* Add your locale to `display.locale` in `conf/magirc.php`, pick it in the
  language menu, and look through every page: network, servers, a server, channels,
  a channel, users, a user, operators, countries and, if you have chanstats,
  activity. Check that nothing overflows on a phone-sized window.
* Open a pull request with the `.po` and the compiled `.mo`, and add your
  language to the list below.

Shipped translations: `pt_PT` (Portuguese, Portugal).

The 1.x translations do not carry over; the strings changed too much.
