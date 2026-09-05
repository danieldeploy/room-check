# Publicação segura da tradução Google

Este procedimento troca apenas o motor de tradução de conteúdo dinâmico. Não altera templates WhatsApp, lembretes, utilizadores ou integrações ZKAccess/My2N.

## 1. Preparação e verificações sem escrita

1. Crie e descarregue um backup completo da base de dados.
2. Confirme que o repositório cPanel aponta para `agent/room-item-assignments` e para `/home/welcome/public_html/check`.
3. Confirme em phpMyAdmin que existem:
   - `item_lists.name_en`;
   - `item_list_items.name_en` e `item_list_items.default_instructions_en`;
   - `room_checklist_values.problem_en`;
   - `room_item_assignments.verification_instructions_en`;
   - a tabela `translation_cache` e o índice `uq_translation_cache_pair_hash`.
4. Não publique se algum destes elementos faltar. Aplique primeiro, pela ordem necessária, as migrações `017_bilingual_content.sql`, `019_translation_cache.sql` e `020_dynamic_list_item_names.sql`.

## 2. Chave privada

1. Ative Cloud Translation API (Basic) no projeto Google Cloud.
2. Restrinja a chave à Cloud Translation API e, quando o alojamento tiver IP de saída estável, a esse IP.
3. Crie `/home/welcome/room-check-private/google-translation.json`, fora de `public_html`, com:

```json
{"api_key":"CHAVE_RESTRITA"}
```

4. Aplique permissões `0600` ao ficheiro.
5. Em `config.local.php`, configure apenas:

```php
'translation' => [
    'enabled' => true,
    'secrets_file' => '/home/welcome/room-check-private/google-translation.json',
    'engine_key' => 'google-basic-nmt-v2',
    'timeout_seconds' => 12,
],
```

Nunca coloque a chave no Git, no JavaScript ou numa página do portal.

## 3. Migração e publicação

1. Importe uma única vez `migrations/023_google_translation_cache.sql`.
2. Confirme que `translation_cache.engine_key` existe e que o índice único passou a `uq_translation_cache_engine_pair_hash`.
3. Atualize o repositório a partir do remoto e confirme o commit esperado.
4. Execute `Deploy HEAD Commit`.
5. A publicação remove apenas os ficheiros do antigo validador lexical e o endpoint de preview que já não são utilizados.

## 4. Testes após publicação

1. Abra o portal em PT e confirme que listas e descrições existentes continuam visíveis sem chamadas ao Google durante a navegação.
2. Com um texto de teste aprovado, grave uma alteração em PT e confirme a versão EN.
3. Repita com `Substitua-os` e confirme que não aparece qualquer erro de “palavra não reconhecida”.
4. Confirme que uma segunda gravação sem alterações reutiliza a tradução existente.
5. Abra o portal em EN, faça uma alteração de teste autorizada e confirme a versão PT.
6. Consulte os logs PHP e confirme que não existem erros de cURL, SQL ou permissões do ficheiro privado.

## 5. Rollback

Se o teste falhar, publique o commit anterior. A migração 023 pode permanecer: o código anterior continua a inserir linhas com o valor predefinido `mymemory-v1`. Se for necessário repor também a base de dados exatamente ao estado anterior, restaure o backup em vez de alterar manualmente os índices.
