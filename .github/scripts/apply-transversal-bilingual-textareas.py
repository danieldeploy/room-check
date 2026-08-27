from pathlib import Path

# item-lists.php: opt every item-list instruction textarea into the shared contract
# and provide the existing bilingual Correct/Cancel labels to the generic client.
path = Path('item-lists.php')
text = path.read_text()
old_body = '<body>\n<main class="lists-shell">'
new_body = '''<body
    data-bilingual-decision-message="<?= listEscape(SiteTranslations::text(
        'Tem texto errado em Inglês. Quer corrigir, ou anular a edição?',
        'There is text incorrectly written in Portuguese. Do you want to correct it or cancel the edit?'
    )) ?>"
    data-bilingual-correct="<?= listEscape(SiteTranslations::text('Corrigir', 'Correct')) ?>"
    data-bilingual-cancel="<?= listEscape(SiteTranslations::text('Anular edição', 'Cancel edit')) ?>"
    data-bilingual-saved="<?= listEscape(SiteTranslations::text('Guardado', 'Saved')) ?>"
>
<main class="lists-shell">'''
if old_body not in text:
    raise SystemExit('item-lists body anchor not found')
text = text.replace(old_body, new_body, 1)

old_existing = '''<textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…" aria-label="Descrição da verificação: <?= listEscape((string) $item['name']) ?>">'''
new_existing = '''<textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…" aria-label="Descrição da verificação: <?= listEscape((string) $item['name']) ?>" data-bilingual-textarea data-bilingual-autosave-action="save_item_list_instructions" data-list-id="<?= $listId ?>" data-item-id="<?= (int) $item['id'] ?>">'''
if old_existing not in text:
    raise SystemExit('existing item textarea anchor not found')
text = text.replace(old_existing, new_existing, 1)

old_new = '''<textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…"></textarea>'''
new_new = '''<textarea name="default_instructions" maxlength="5000" rows="1" placeholder="Descreva a verificação…" data-bilingual-textarea data-bilingual-new-item="1"></textarea>'''
if old_new not in text:
    raise SystemExit('new item textarea anchor not found')
text = text.replace(old_new, new_new, 1)
path.write_text(text)

# api.php: persist an existing item-list instruction on blur through the same
# ContentTranslator/LanguageGuard path used by all other bilingual writes.
path = Path('api.php')
text = path.read_text()
anchor = "    if (($payload['action'] ?? '') === 'create_interval') {\n"
block = '''    if (($payload['action'] ?? '') === 'save_item_list_instructions') {
        $currentUser = Auth::requirePermission($pdo, $config, Auth::PERMISSION_TASK_ASSIGN);
        Csrf::validate(isset($payload['csrfToken']) ? (string) $payload['csrfToken'] : null);
        $listId = (int) ($payload['listId'] ?? 0);
        $itemId = (int) ($payload['itemId'] ?? 0);
        $textValue = trim((string) ($payload['text'] ?? ''));
        if ($listId < 1 || $itemId < 1) {
            throw new InvalidArgumentException('Item não encontrado.');
        }
        if (mb_strlen($textValue) > 5000) {
            throw new InvalidArgumentException('A descrição da verificação não pode ultrapassar 5000 caracteres.');
        }
        itemList($pdo, $listId);
        $pdo->beginTransaction();
        $currentStatement = $pdo->prepare(
            'SELECT default_instructions, default_instructions_en
             FROM item_list_items WHERE id = :id AND list_id = :list_id FOR UPDATE'
        );
        $currentStatement->execute(['id' => $itemId, 'list_id' => $listId]);
        $currentItem = $currentStatement->fetch();
        if (!is_array($currentItem)) {
            throw new InvalidArgumentException('Item não encontrado.');
        }
        $instructionVersions = $contentTranslator->versions(
            $textValue,
            Translator::locale(),
            (string) ($currentItem['default_instructions'] ?? ''),
            (string) ($currentItem['default_instructions_en'] ?? '')
        );
        $updateStatement = $pdo->prepare(
            'UPDATE item_list_items
             SET default_instructions = :instructions_pt, default_instructions_en = :instructions_en
             WHERE id = :id AND list_id = :list_id'
        );
        $updateStatement->execute([
            'instructions_pt' => $instructionVersions['pt'],
            'instructions_en' => $instructionVersions['en'],
            'id' => $itemId,
            'list_id' => $listId,
        ]);
        Auth::audit($pdo, (int) $currentUser['id'], 'item_list_instructions_updated', [
            'list_id' => $listId,
            'item_id' => $itemId,
        ]);
        $pdo->commit();
        jsonResponse([
            'ok' => true,
            'value' => Translator::localized($instructionVersions['pt'], $instructionVersions['en']),
        ]);
    }

'''
if anchor not in text:
    raise SystemExit('api insertion anchor not found')
text = text.replace(anchor, block + anchor, 1)
path.write_text(text)

# I18N developer contract: future item-list instruction textareas must opt in.
path = Path('docs/I18N.md')
text = path.read_text()
marker = '## Transversal bilingual textarea contract\n'
if marker not in text:
    text += '''\n\n## Transversal bilingual textarea contract\n\nEditable user-authored item-list instruction textareas use the shared `data-bilingual-textarea` contract. The shared client validates only on blur/submit through the server-side `LanguageGuard`, highlights server-reported invalid words without replacing the user's text, keeps the last server-confirmed value for Cancel edit, and uses the same Correct/Cancel navigation guard. Existing item instructions opt into blur autosave with `data-bilingual-autosave-action`; new-item textareas validate on blur but are persisted only when the item is created. Technical/non-natural-language textareas must not opt in.\n'''
path.write_text(text)
