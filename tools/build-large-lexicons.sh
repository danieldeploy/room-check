#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/resources/lexicon/full"
LICENSES="$OUT/licenses"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

CSPELL_REF="51cd13717e52a6e14b4a36055b7b49a88f267106"
BASE_URL="https://raw.githubusercontent.com/streetsidesoftware/cspell-dicts/${CSPELL_REF}"

mkdir -p "$OUT" "$LICENSES"

curl --fail --silent --show-error --location \
  "$BASE_URL/dictionaries/en_GB-MIT/src/generated/base.txt" \
  -o "$TMP/en_GB-base.txt"
curl --fail --silent --show-error --location \
  "$BASE_URL/dictionaries/pt_PT/src/hunspell/Portuguese-European.dic" \
  -o "$TMP/Portuguese-European.dic"
curl --fail --silent --show-error --location \
  "$BASE_URL/dictionaries/pt_PT/src/hunspell/Portuguese-European.aff" \
  -o "$TMP/Portuguese-European.aff"
curl --fail --silent --show-error --location \
  "$BASE_URL/dictionaries/en_GB-MIT/LICENSE" \
  -o "$LICENSES/en_GB-MIT-LICENSE.txt"
curl --fail --silent --show-error --location \
  "$BASE_URL/dictionaries/pt_PT/LICENSE" \
  -o "$LICENSES/pt_PT-LICENSE.txt"

npm install --global --silent hunspell-reader@10.0.1
# The reader applies the .aff prefix/suffix rules by default. Sorting and
# Unicode normalization are intentionally done in the Python stage so this
# remains compatible across hunspell-reader CLI option changes.
hunspell-reader words "$TMP/Portuguese-European.dic" \
  -o "$TMP/pt-expanded.txt"

python3 - "$ROOT" "$TMP" "$OUT" <<'PY'
from __future__ import annotations

import json
import sys
import unicodedata
from pathlib import Path

root = Path(sys.argv[1])
tmp = Path(sys.argv[2])
out = Path(sys.argv[3])

ALLOWED_PUNCT = {"'", "-"}
MAX_LEN = 190


def normalize_exact(value: str) -> str:
    value = value.strip().replace("’", "'").replace("‘", "'")
    return unicodedata.normalize("NFC", value)


def normalize(value: str) -> str:
    return normalize_exact(value).casefold()


def valid_token(value: str) -> bool:
    if not value or len(value) > MAX_LEN or any(ch.isspace() for ch in value):
        return False
    return all(ch.isalpha() or ch in ALLOWED_PUNCT for ch in value)


def is_titlecase_candidate(raw: str) -> bool:
    if not raw or any(ch.isspace() for ch in raw):
        return False
    letters = [ch for ch in raw if ch.isalpha()]
    if len(letters) < 3:
        return False
    return letters[0].isupper() and any(ch.islower() for ch in letters[1:])


def english_source_sets() -> tuple[set[str], set[str], set[str]]:
    # The CSpell source declares `keep-case`. Lowercase source entries form the
    # normal case-insensitive English lexicon. Other exact-case entries are
    # preserved separately so valid forms such as I / I'm / I'd are not lost.
    ordinary: set[str] = set()
    lowercase_source: set[str] = set()
    titlecase_source: set[str] = set()
    exact_candidates: dict[str, str] = {}

    for raw in (tmp / "en_GB-base.txt").read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw or raw.startswith("#"):
            continue
        exact = normalize_exact(raw)
        token = normalize(raw)
        if not valid_token(exact):
            continue
        first_alpha = next((ch for ch in exact if ch.isalpha()), "")
        if first_alpha and first_alpha.islower():
            lowercase_source.add(token)
            ordinary.add(token)
        else:
            exact_candidates[exact] = token
            if is_titlecase_candidate(exact):
                titlecase_source.add(token)

    # If CSpell also supplies a lowercase form, normal lexical membership wins
    # and there is no need for an exact-case entry. Otherwise preserve the exact
    # spelling from the validated source without inventing local exceptions.
    exact_case = {
        exact for exact, normalized in exact_candidates.items()
        if normalized not in lowercase_source
    }

    # A word that also exists as an ordinary lowercase English entry (Will,
    # Rose, May...) remains lexical English rather than becoming a neutral name.
    proper = titlecase_source - lowercase_source
    return ordinary, proper, exact_case


def portuguese_source_proper() -> set[str]:
    lowercase_source: set[str] = set()
    titlecase_source: set[str] = set()
    lines = (tmp / "Portuguese-European.dic").read_text(encoding="utf-8").splitlines()
    for index, raw in enumerate(lines):
        if index == 0 and raw.strip().isdigit():
            continue
        raw = raw.strip()
        if not raw:
            continue
        lemma = raw.split("/", 1)[0].strip()
        token = normalize(lemma)
        if not valid_token(normalize_exact(lemma)):
            continue
        first_alpha = next((ch for ch in lemma if ch.isalpha()), "")
        if first_alpha and first_alpha.islower():
            lowercase_source.add(token)
        elif is_titlecase_candidate(lemma):
            titlecase_source.add(token)
    return titlecase_source - lowercase_source


def build_pt() -> set[str]:
    # The full Portuguese lexicon comes only from the validated PT-PT Hunspell
    # source. Project core lists remain a runtime emergency fallback only.
    words: set[str] = set()
    for raw in (tmp / "pt-expanded.txt").read_text(encoding="utf-8", errors="strict").splitlines():
        token = normalize(raw)
        if valid_token(token):
            words.add(token)
    return words


def write_indexed(words: set[str], stem: str) -> None:
    ordered = sorted(words)
    word_path = out / f"{stem}.txt"
    index_path = out / f"{stem}.index.json"
    index: dict[str, list[int | None]] = {}
    offset = 0
    current_prefix = None

    with word_path.open("wb") as handle:
        for word in ordered:
            prefix = word[:2]
            if prefix != current_prefix:
                if current_prefix is not None:
                    index[current_prefix][1] = offset
                index[prefix] = [offset, None]
                current_prefix = prefix
            encoded = (word + "\n").encode("utf-8")
            handle.write(encoded)
            offset += len(encoded)

    if current_prefix is not None:
        index[current_prefix][1] = offset

    index_path.write_text(
        json.dumps(index, ensure_ascii=False, separators=(",", ":")),
        encoding="utf-8",
    )
    print(f"{stem}: {len(ordered)} words, {offset} bytes")


def write_plain(words: set[str], name: str) -> None:
    ordered = sorted(words)
    path = out / name
    path.write_text("".join(word + "\n" for word in ordered), encoding="utf-8")
    print(f"{name}: {len(ordered)} neutral proper names")


en_words, en_proper, en_case_sensitive = english_source_sets()
pt_words = build_pt()
proper_words = en_proper | portuguese_source_proper()

write_indexed(en_words, "en_GB")
write_indexed(en_case_sensitive, "en_GB_case_sensitive")
write_indexed(pt_words, "pt_PT")
write_plain(proper_words, "proper_neutral.txt")
PY

cat > "$OUT/README.md" <<EOF
# Large local PT/EN lexical resources

These files are generated for Room Check and are used only for local lexical
verification. They introduce no runtime network dependency.

Sources are pinned to cspell-dicts commit ${CSPELL_REF}:

- English: dictionaries/en_GB-MIT/src/generated/base.txt (MIT). Lowercase
  entries form the normal EN lexicon. The upstream file is explicitly
  case-sensitive (`keep-case`), so valid exact-case entries are preserved in
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
EOF

# Build-time sanity checks use ordinary examples plus CSpell's exact-case forms.
grep -Fxq "well" "$OUT/en_GB.txt"
grep -Fxq "beautiful" "$OUT/en_GB.txt"
grep -Fxq "necessary" "$OUT/en_GB.txt"
grep -Fxq "I" "$OUT/en_GB_case_sensitive.txt"
grep -Fxq "I'm" "$OUT/en_GB_case_sensitive.txt"
grep -Fxq "I'd" "$OUT/en_GB_case_sensitive.txt"
grep -Fxq "I'll" "$OUT/en_GB_case_sensitive.txt"
grep -Fxq "I've" "$OUT/en_GB_case_sensitive.txt"
if grep -Fxq "i" "$OUT/en_GB.txt"; then
  echo "ERROR: case-sensitive English I was flattened into the ordinary lexicon" >&2
  exit 1
fi
grep -Fxq "cadeira" "$OUT/pt_PT.txt"
grep -Fxq "coração" "$OUT/pt_PT.txt"
grep -Fxq "toalha" "$OUT/pt_PT.txt"

printf 'Large lexicons built successfully.\n'
