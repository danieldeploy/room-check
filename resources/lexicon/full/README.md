# Validated local PT/EN lexical resources

Room Check does not maintain its own language, technical-term, person-name or
country word lists. Runtime validation uses generated resources from maintained
external sources and has no network dependency.

Sources:

- English (EN-GB): pinned CSpell dictionaries, commit 51cd13717e52a6e14b4a36055b7b49a88f267106. The upstream
  keep-case information is preserved in en_GB_case_sensitive.* so forms such as
  I / I'm / I'd / I'll / I've are not lost by lowercase lookup.
- Portuguese (PT-PT): CSpell/Hunspell Portuguese-European dictionary from the
  same pinned CSpell commit, expanded with hunspell-reader 10.0.1.
- Person names: Onomaverse v2026.06 frequency and name-equivalence
  datasets (CC BY 4.0), CSpell's maintained people-names source, and the Global
  Popular Names Dataset pinned at commit ddf0a8605ef11212e3d85e7abcbf2f102e6f7470. The latter source
  is distributed under GPL-3.0; its license is bundled in ./licenses.
- Country names: Unicode CLDR JSON commit 29e2b5461f7347f4e5605fd3396a55a7a7cb7f4e, locale data for en and
  pt-PT, under the bundled Unicode License V3.

Unknown brands, technical labels, codes or intentionally unvalidated text must
be written inside double quotes. Quoted spans are preserved exactly and skipped
by language validation/translation. Person names recognized by the generated
name resource do not require quotes. Country names remain language-bearing and
are translated normally.

Rebuild with:

    bash tools/build-large-lexicons.sh
