# Large local PT/EN lexical resources

These files are generated for Room Check and are used only for local lexical
verification. They introduce no runtime network dependency.

Sources are pinned to cspell-dicts commit 51cd13717e52a6e14b4a36055b7b49a88f267106:

- English: dictionaries/en_GB-MIT/src/generated/base.txt (MIT). Lowercase
  entries form the normal EN lexicon. The upstream file is explicitly
  case-sensitive (keep-case), so valid exact-case entries are preserved in
  en_GB_case_sensitive.txt instead of being flattened or recreated manually.
  The existing title-case extraction continues to form the neutral proper-name
  fallback.
- Portuguese (Portugal): dictionaries/pt_PT/src/hunspell/Portuguese-European.dic
  plus Portuguese-European.aff. The Hunspell rules are expanded with
  hunspell-reader 10.0.1, then normalized to a one-word-per-line lexicon.
  Unambiguous title-case lemmas also contribute to the neutral proper-name list.

The compact Room Check core lists are not merged into these full dictionaries;
they exist only as a runtime fail-safe for an incomplete deployment. Exact-case
CSpell entries prove that a spelling is valid but are neutral by themselves, so
proper names and acronyms do not incorrectly decide whether a sentence is PT or
EN. Sentence language and ordinary PT/EN lexical evidence remain decisive.

The corresponding upstream licenses are kept in ./licenses. Generated word
files are sorted UTF-8 text. Each .index.json stores byte ranges by the first two
Unicode characters so PHP can look up only the relevant slices without loading
hundreds of thousands of words into memory.

Rebuild with:

    bash tools/build-large-lexicons.sh
