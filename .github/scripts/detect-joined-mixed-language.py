from pathlib import Path

# Server: detect opposite-language prefixes/suffixes accidentally joined into one token.
guard_path = Path('src/I18n/LanguageGuard.php')
guard = guard_path.read_text()
old_loop = """        foreach ($tokens as $token) {
            if (self::isConfidentOppositeComponent($token, $expectedLanguage)) {
                $invalid[$token] = true;
            }
        }
"""
new_loop = """        foreach ($tokens as $token) {
            if (self::isConfidentOppositeComponent($token, $expectedLanguage)) {
                $invalid[$token] = true;
                continue;
            }
            foreach (self::embeddedOppositeParts($token, $expectedLanguage) as $part) {
                $invalid[$part] = true;
            }
        }
"""
if old_loop not in guard:
    raise SystemExit('oppositeWords token loop not found')
guard = guard.replace(old_loop, new_loop, 1)

anchor = """    /**
     * @param array<string, float> $scores
     */
    private static function scoreDominates(array $scores, string $language, string $otherLanguage): bool
"""
helper = """    private static function isConfidentExpectedComponent(string $component, string $expectedLanguage): bool
    {
        $oppositeLanguage = $expectedLanguage === 'en' ? 'pt' : 'en';
        $result = self::detect($component);
        if ($result->language !== $expectedLanguage) {
            return false;
        }

        return self::scoreDominates($result->scores(), $expectedLanguage, $oppositeLanguage);
    }

    /** @return string[] */
    private static function embeddedOppositeParts(string $token, string $expectedLanguage): array
    {
        $length = mb_strlen($token, 'UTF-8');
        if ($length < 6 || in_array($token, self::NEUTRAL, true)) {
            return [];
        }

        $invalid = [];
        for ($split = 3; $split <= ($length - 3); $split++) {
            $left = mb_substr($token, 0, $split, 'UTF-8');
            $right = mb_substr($token, $split, null, 'UTF-8');
            if (!self::isNaturalLanguageToken($left) || !self::isNaturalLanguageToken($right)) {
                continue;
            }

            if (self::isConfidentExpectedComponent($left, $expectedLanguage)
                && self::isConfidentOppositeComponent($right, $expectedLanguage)) {
                $invalid[$right] = true;
            }
            if (self::isConfidentOppositeComponent($left, $expectedLanguage)
                && self::isConfidentExpectedComponent($right, $expectedLanguage)) {
                $invalid[$left] = true;
            }
        }

        return array_keys($invalid);
    }

"""
if anchor not in guard:
    raise SystemExit('scoreDominates anchor not found')
guard = guard.replace(anchor, helper + anchor, 1)
guard_path.write_text(guard)

# Client: highlight server-reported invalid substrings even when they are joined to another word.
feedback_path = Path('assets/validation-feedback.js')
feedback = feedback_path.read_text()
start = feedback.find("    const appendHighlightedText = (layer, value, invalidWords) => {")
end = feedback.find("\n    const renderHighlight =", start)
if start < 0 or end < 0:
    raise SystemExit('appendHighlightedText function not found')
new_function = """    const appendHighlightedText = (layer, value, invalidWords) => {
        const words = (Array.isArray(invalidWords) ? invalidWords : [])
            .map((word) => String(word).trim())
            .filter(Boolean)
            .sort((a, b) => b.length - a.length);
        if (words.length === 0) return false;

        const escaped = words.map((word) => word.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&'));
        const pattern = new RegExp(`(${escaped.join('|')})`, 'giu');
        let matched = false;

        value.split(pattern).forEach((part) => {
            const isWrong = words.some((word) => part.localeCompare(word, undefined, { sensitivity: 'accent' }) === 0);
            if (isWrong) {
                const wrong = document.createElement('span');
                wrong.className = 'language-wrong-segment';
                wrong.textContent = part;
                wrong.style.backgroundColor = highlightBackground(layer);
                layer.append(wrong);
                matched = true;
            } else {
                layer.append(document.createTextNode(part));
            }
        });
        return matched;
    };
"""
feedback = feedback[:start] + new_function + feedback[end:]
feedback_path.write_text(feedback)

# Regression tests: both directions and exact offending embedded segment.
hardening_path = Path('tests/language-guard-hardening.php')
hardening = hardening_path.read_text()
insert_after = """// Symmetric PT -> EN protection.
foreach (['block', 'land', 'boat', 'cloud', 'stairs', 'room', 'bed', 'curtain'] as $word) {
    assertHardeningRejects(
        'Verificar se está limpo e sem danos. ' . $word,
        'pt',
        'PT input rejects English component: ' . $word
    );
}

"""
addition = """// Joined mixed-language tokens must also be rejected in both directions.
try {
    LanguageGuard::assertExpectedLanguage('Check the roadcasa carefully.', 'en');
    throw new RuntimeException('FAIL: EN input accepted joined PT suffix roadcasa');
} catch (LanguageValidationException $exception) {
    assertHardening(in_array('casa', $exception->invalidWords, true), 'EN input detects PT suffix inside joined token: roadcasa');
}
try {
    LanguageGuard::assertExpectedLanguage('Verificar a casaroad cuidadosamente.', 'pt');
    throw new RuntimeException('FAIL: PT input accepted joined EN suffix casaroad');
} catch (LanguageValidationException $exception) {
    assertHardening(in_array('road', $exception->invalidWords, true), 'PT input detects EN suffix inside joined token: casaroad');
}

"""
if insert_after not in hardening:
    raise SystemExit('language hardening insertion point not found')
hardening = hardening.replace(insert_after, insert_after + addition, 1)
hardening_path.write_text(hardening)

ux_path = Path('tests/invalid-edit-ux.php')
ux = ux_path.read_text()
anchor2 = "assertInvalidEditUx(str_contains($feedback, \"appendHighlightedText\"), 'actual server-reported wrong words are highlighted');\n"
addition2 = "assertInvalidEditUx(str_contains($feedback, \"new RegExp(`(${escaped.join('|')})`, 'giu')\"), 'server-reported wrong substrings are highlighted inside joined tokens');\n"
if anchor2 not in ux:
    raise SystemExit('invalid edit UX anchor not found')
if addition2 not in ux:
    ux = ux.replace(anchor2, anchor2 + addition2, 1)
ux_path.write_text(ux)
