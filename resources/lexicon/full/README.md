# Large local PT/EN lexical resources

These files are generated for Room Check and are used only for local lexical
verification. They introduce no runtime network dependency.

Sources are pinned to cspell-dicts commit 51cd13717e52a6e14b4a36055b7b49a88f267106:

- English: dictionaries/en_GB-MIT/src/generated/base.txt (MIT). Ordinary
  lowercase entries form the EN lexicon. Title-case entries that do not also
  exist as ordinary lowercase words form a separate neutral proper-name list.
- Portuguese (Portugal): dictionaries/pt_PT/src/hunspell/Portuguese-European.dic
  plus Portuguese-European.aff. The Hunspell rules are expanded with
  hunspell-reader 10.0.1, then normalized to a one-word-per-line lexicon.
  Unambiguous title-case lemmas also contribute to the neutral proper-name list.

The proper-name list is deliberately language-neutral: names of people,
countries and places must not by themselves decide whether a sentence is PT or
EN. Ordinary words that are also names remain in their normal language lexicon.

The corresponding upstream licenses are kept in ./licenses. The generated PT/EN
word files are sorted UTF-8 text. Each .index.json stores byte ranges by the
first two Unicode characters so PHP can look up only the relevant slices without
loading hundreds of thousands of words into memory.

Rebuild with:

    bash tools/build-large-lexicons.sh
