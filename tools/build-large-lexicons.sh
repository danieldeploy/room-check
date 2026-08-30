#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/resources/lexicon/full"
LICENSES="$OUT/licenses"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

CSPELL_REF="51cd13717e52a6e14b4a36055b7b49a88f267106"
CSPELL_BASE_URL="https://raw.githubusercontent.com/streetsidesoftware/cspell-dicts/${CSPELL_REF}"
ONOMAVERSE_TAG="v2026.06"
ONOMAVERSE_BASE_URL="https://github.com/onomaverse/datasets/releases/download/${ONOMAVERSE_TAG}"
ONOMAVERSE_GIVEN_SHA256="42d889e8c7eab75907aba9b3c4d3c40074439c5b04e7e4eced301411cb6ea848"
ONOMAVERSE_SURNAME_SHA256="426aab8f6e308047576aa08b4f328ec0680b06251a2a1e2344f2136578929e60"
CLDR_REF="29e2b5461f7347f4e5605fd3396a55a7a7cb7f4e"
CLDR_BASE_URL="https://raw.githubusercontent.com/unicode-org/cldr-json/${CLDR_REF}"

mkdir -p "$OUT" "$LICENSES"

curl --fail --silent --show-error --location \
  "$CSPELL_BASE_URL/dictionaries/en_GB-MIT/src/generated/base.txt" \
  -o "$TMP/en_GB-base.txt"
curl --fail --silent --show-error --location \
  "$CSPELL_BASE_URL/dictionaries/people-names/src/names.txt" \
  -o "$TMP/cspell-people-names.txt"
curl --fail --silent --show-error --location \
  "$CSPELL_BASE_URL/dictionaries/pt_PT/src/hunspell/Portuguese-European.dic" \
  -o "$TMP/Portuguese-European.dic"
curl --fail --silent --show-error --location \
  "$CSPELL_BASE_URL/dictionaries/pt_PT/src/hunspell/Portuguese-European.aff" \
  -o "$TMP/Portuguese-European.aff"
curl --fail --silent --show-error --location \
  "$CSPELL_BASE_URL/dictionaries/en_GB-MIT/LICENSE" \
  -o "$LICENSES/en_GB-MIT-LICENSE.txt"
curl --fail --silent --show-error --location \
  "$CSPELL_BASE_URL/dictionaries/pt_PT/LICENSE" \
  -o "$LICENSES/pt_PT-LICENSE.txt"

curl --fail --silent --show-error --location \
  "$ONOMAVERSE_BASE_URL/given-name-frequency.csv" \
  -o "$TMP/given-name-frequency.csv"
curl --fail --silent --show-error --location \
  "$ONOMAVERSE_BASE_URL/surname-frequency.csv" \
  -o "$TMP/surname-frequency.csv"
echo "$ONOMAVERSE_GIVEN_SHA256  $TMP/given-name-frequency.csv" | sha256sum -c -
echo "$ONOMAVERSE_SURNAME_SHA256  $TMP/surname-frequency.csv" | sha256sum -c -

curl --fail --silent --show-error --location \
  "$CLDR_BASE_URL/cldr-json/cldr-localenames-full/main/en/territories.json" \
  -o "$TMP/cldr-en-territories.json"
curl --fail --silent --show-error --location \
  "$CLDR_BASE_URL/cldr-json/cldr-localenames-full/main/pt-PT/territories.json" \
  -o "$TMP/cldr-pt-territories.json"
curl --fail --silent --show-error --location \
  "$CLDR_BASE_URL/LICENSE" \
  -o "$LICENSES/unicode-cldr-LICENSE.txt"

npm install --global --silent hunspell-reader@10.0.1
hunspell-reader words "$TMP/Portuguese-European.dic" \
  -o "$TMP/pt-expanded.txt"

python3 - "$TMP" "$OUT" <<'PY'
from __future__ import annotations

import csv
import json
import re
import sys
import unicodedata
from pathlib import Path

tmp = Path(sys.argv[1])
out = Path(sys.argv[2])

ALLOWED_PUNCT = {"'", "-"}
MAX_LEN = 190
PSEUDO_TERRITORIES = {"EU", "EZ", "QO", "UN", "XA", "XB", "ZZ"}


def normalize_exact(value: str) -> str:
    value = value.strip().replace("’", "'").replace("‘", "'")
    return unicodedata.normalize("NFC", value)


def normalize(value: str) -> str:
    return normalize_exact(value).casefold()


def valid_token(value: str) -> bool:
    if not value or len(value) > MAX_LEN or any(ch.isspace() for ch in value):
        return False
    return all(ch.isalpha() or ch in ALLOWED_PUNCT for ch in value)


def english_source_sets() -> tuple[set[str], set[str]]:
    ordinary: set[str] = set()
    lowercase_source: set[str] = set()
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

    exact_case = {
        exact for exact, normalized in exact_candidates.items()
        if normalized not in lowercase_source
    }
    return ordinary, exact_case


def build_pt() -> set[str]:
    words: set[str] = set()
    for raw in (tmp / "pt-expanded.txt").read_text(encoding="utf-8", errors="strict").splitlines():
        token = normalize(raw)
        if valid_token(token):
            words.add(token)
    return words


def build_people() -> set[str]:
    names: set[str] = set()

    for raw in (tmp / "cspell-people-names.txt").read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw or raw.startswith("#"):
            continue
        exact = normalize_exact(raw)
        if valid_token(exact):
            names.add(exact)

    for csv_name in ("given-name-frequency.csv", "surname-frequency.csv"):
        with (tmp / csv_name).open("r", encoding="utf-8", newline="") as handle:
            reader = csv.DictReader(handle)
            if "name" not in (reader.fieldnames or []):
                raise RuntimeError(f"Onomaverse file {csv_name} has no name column")
            for row in reader:
                exact = normalize_exact(row.get("name", ""))
                if valid_token(exact):
                    names.add(exact)
    return names


def build_country_tokens(path: Path, locale: str) -> set[str]:
    data = json.loads(path.read_text(encoding="utf-8"))
    territories = data["main"][locale]["localeDisplayNames"]["territories"]
    words: set[str] = set()
    for key, value in territories.items():
        match = re.fullmatch(r"([A-Z]{2})(?:-alt-[a-z-]+)?", key)
        if not match or match.group(1) in PSEUDO_TERRITORIES:
            continue
        exact = normalize_exact(str(value))
        if valid_token(exact):
            words.add(normalize(exact))
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
    print(f"{stem}: {len(ordered)} entries, {offset} bytes")


def write_plain(words: set[str], name: str) -> None:
    ordered = sorted(words)
    (out / name).write_text("".join(word + "\n" for word in ordered), encoding="utf-8")
    print(f"{name}: {len(ordered)} entries")


en_words, en_case_sensitive = english_source_sets()
pt_words = build_pt()
people = build_people()
country_en = build_country_tokens(tmp / "cldr-en-territories.json", "en")
country_pt = build_country_tokens(tmp / "cldr-pt-territories.json", "pt-PT")

write_indexed(en_words, "en_GB")
write_indexed(en_case_sensitive, "en_GB_case_sensitive")
write_indexed(pt_words, "pt_PT")
write_indexed(people, "person_neutral")
write_plain(country_en, "country_en.txt")
write_plain(country_pt, "country_pt.txt")

obsolete = out / "proper_neutral.txt"
if obsolete.exists():
    obsolete.unlink()
PY

cat > "$OUT/README.md" <<EOF
# Validated local PT/EN lexical resources

Room Check does not maintain its own language, technical-term, person-name or
country word lists. Runtime validation uses generated resources from maintained
external sources and has no network dependency.

Sources are pinned/reproducible:

- English (EN-GB): CSpell dictionaries, commit ${CSPELL_REF}. The upstream
  keep-case information is preserved in en_GB_case_sensitive.* so forms such as
  I / I'm / I'd / I'll / I've are not lost by lowercase lookup.
- Portuguese (PT-PT): CSpell/Hunspell Portuguese-European dictionary from the
  same pinned CSpell commit, expanded with hunspell-reader 10.0.1.
- Person names: Onomaverse ${ONOMAVERSE_TAG} given-name and surname frequency
  datasets (CC BY 4.0), supplemented by CSpell's maintained people-names source.
  Names data from Onomaverse (https://onomaverse.com/datasets), licensed CC BY 4.0.
- Country names: Unicode CLDR JSON commit ${CLDR_REF}, locale data for en and
  pt-PT, under the bundled Unicode License V3.

Unknown brands, technical labels, codes or intentionally unvalidated text must
be written inside double quotes. Quoted spans are preserved exactly and skipped
by language validation/translation. Person names recognized by the generated
name resource do not require quotes. Country names remain language-bearing and
are translated normally.

Rebuild with:

    bash tools/build-large-lexicons.sh
EOF

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
grep -Fxq "Michael" "$OUT/person_neutral.txt"
grep -Fxq "Ranjana" "$OUT/person_neutral.txt"
grep -Fxq "spain" "$OUT/country_en.txt"
grep -Fxq "romania" "$OUT/country_en.txt"
grep -Fxq "espanha" "$OUT/country_pt.txt"
grep -Fxq "roménia" "$OUT/country_pt.txt"
if grep -Fxiq "Spania" "$OUT/person_neutral.txt" || \
   grep -Fxiq "Spania" "$OUT/country_en.txt" || \
   grep -Fxiq "Spania" "$OUT/country_pt.txt"; then
  echo "ERROR: Spania unexpectedly appears in a PT/EN person/country source" >&2
  exit 1
fi

printf 'Validated lexicons built successfully.\n'
