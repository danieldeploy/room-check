# Large local PT/EN lexical resources

These files are generated for Room Check and are used only for local lexical
verification. They introduce no runtime network dependency.

Sources are pinned to cspell-dicts commit 51cd13717e52a6e14b4a36055b7b49a88f267106:

- English: dictionaries/en_GB-MIT/src/generated/base.txt (MIT). During the
  build, proper-name/acronym style entries are excluded and the existing Room
  Check EN core vocabulary is merged in.
- Portuguese (Portugal): dictionaries/pt_PT/src/hunspell/Portuguese-European.dic
  plus Portuguese-European.aff. The Hunspell rules are expanded with
  hunspell-reader 10.0.1, then normalized to a one-word-per-line lexicon. The
  existing Room Check PT core vocabulary is merged in.

The corresponding upstream licenses are kept in ./licenses. The generated word
files are sorted UTF-8 text. Each .index.json stores byte ranges by the first two
Unicode characters so PHP can look up only the relevant slices without loading
hundreds of thousands of words into memory.

Rebuild with:

    bash tools/build-large-lexicons.sh
