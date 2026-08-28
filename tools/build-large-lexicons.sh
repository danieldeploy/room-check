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
hunspell-reader words "$TMP/Portuguese-European.dic" \
  -o "$TMP/pt-expanded.txt" -s -u -i -l

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


def normalize(value: str) -> str:
    value = value.strip().replace("’", "'").replace("‘", "'")
    return unicodedata.normalize("NFC", value).casefold()


def valid_token(value: str) -> bool:
    if not value or len(value) > MAX_LEN or any(ch.isspace() for ch in value):
        return False
    return all(ch.isalpha() or ch in ALLOWED_PUNCT for ch in value)


def read_core(path: Path) -> set[str]:
    result: set[str] = set()
    for raw in path.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw or raw.startswith("#"):
            continue
        token = normalize(raw)
        if valid_token(token):
            result.add(token)
    return result


def build_en() -> set[str]:
    words = read_core(root / "resources/lexicon/en_GB_core.txt")
    for raw in (tmp / "en_GB-base.txt").read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw or raw.startswith("#"):
            continue
        # The source contains many proper names/acronyms. They must not become
        # lexical EN evidence in a PT sentence merely because they are names.
        # Keep ordinary lowercase dictionary entries only; project technical
        # names are handled explicitly by technical_neutral.txt.
        first_alpha = next((ch for ch in raw if ch.isalpha()), "")
        if not first_alpha or not first_alpha.islower():
            continue
        token = normalize(raw)
        if valid_token(token):
            words.add(token)
    return words


def build_pt() -> set[str]:
    words = read_core(root / "resources/lexicon/pt_PT_core.txt")
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


write_indexed(build_en(), "en_GB")
write_indexed(build_pt(), "pt_PT")
PY

cat > "$OUT/README.md" <<EOF
# Large local PT/EN lexical resources

These files are generated for Room Check and are used only for local lexical
verification. They introduce no runtime network dependency.

Sources are pinned to cspell-dicts commit ${CSPELL_REF}:

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
EOF

# Basic build-time sanity checks. These deliberately include words that were not
# in the old compact lists so coverage regressions are visible immediately.
grep -Fxq "well" "$OUT/en_GB.txt"
grep -Fxq "beautiful" "$OUT/en_GB.txt"
grep -Fxq "necessary" "$OUT/en_GB.txt"
grep -Fxq "cadeira" "$OUT/pt_PT.txt"
grep -Fxq "coração" "$OUT/pt_PT.txt"
grep -Fxq "toalha" "$OUT/pt_PT.txt"

printf 'Large lexicons built successfully.\n'
