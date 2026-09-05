# Publicação segura da tradução Google e alerta de quota

Este procedimento troca o motor de tradução de conteúdo dinâmico, aplica um limite local de 15 500 carateres por dia Google e liga o template WhatsApp `translation_quota_alert_v1`. Não altera os templates dos lembretes de atribuições, utilizadores ou integrações ZKAccess/My2N.

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
2. Em **APIs e serviços > Credenciais**, escolha **Criar credenciais > Chave de API**.
3. Dê-lhe um nome descritivo, por exemplo `management-hub-room-check`.
4. Em **Restrições de API**, selecione **Restringir chave** e permita exclusivamente **Cloud Translation API** (`translate.googleapis.com`). Não associe a chave ao Gemini nem a outras APIs.
5. Em **Restrições da aplicação**, use **Endereços IP** apenas depois de confirmar o IP público de saída do servidor cPanel. O IP do DNS/site pode ser diferente do IP usado nas ligações de saída; uma suposição errada bloquearia as traduções. Até essa confirmação, mantenha apenas a restrição de API e não publique a chave em nenhum lugar acessível pela Web.
6. Crie `/home/welcome/room-check-private/google-translation.json`, fora de `public_html`, com:

```json
{"api_key":"CHAVE_RESTRITA"}
```

7. Aplique permissões `0600` ao ficheiro.
8. Em `config.local.php`, configure apenas:

```php
'translation' => [
    'enabled' => true,
    'secrets_file' => '/home/welcome/room-check-private/google-translation.json',
    'engine_key' => 'google-basic-nmt-v2',
    'timeout_seconds' => 12,
    'daily_character_limit' => 15500,
    'quota_timezone' => 'America/Los_Angeles',
    'display_timezone' => 'Europe/Lisbon',
    'quota_alert' => [
        'enabled' => false,
        'recipient_mobile' => '351XXXXXXXXX',
        'template_name' => 'translation_quota_alert_v1',
        'language' => 'pt_PT',
    ],
],
```

Nunca coloque a chave no Git, no JavaScript ou numa página do portal.

O limite usa `America/Los_Angeles`, porque a Google repõe a quota diária à meia-noite do horário do Pacífico. O horário da próxima reposição é convertido dinamicamente para `Europe/Lisbon` no alerta; não fixe manualmente 07:00 ou 08:00.

A reserva local é deliberadamente conservadora: os carateres são contabilizados imediatamente antes do pedido externo. Assim, uma resposta de rede ambígua nunca permite ultrapassar silenciosamente o teto, mesmo que alguns desses carateres acabem por não ser faturados pela Google.

Mantenha `quota_alert.enabled` em `false` até a Meta apresentar `translation_quota_alert_v1` como aprovado. Depois configure o número do administrador e altere apenas esse campo para `true`. O cron existente envia no máximo um alerta por período diário e reutiliza as credenciais WhatsApp privadas já configuradas.

## 3. Migração e publicação

1. Importe uma única vez `migrations/023_google_translation_cache.sql`.
2. Importe uma única vez `migrations/024_translation_daily_quota.sql`.
3. Confirme que `translation_cache.engine_key` existe, que o índice único passou a `uq_translation_cache_engine_pair_hash` e que existe `translation_daily_usage`.
4. Atualize o repositório a partir do remoto e confirme o commit esperado.
5. Execute `Deploy HEAD Commit`.
6. A publicação remove apenas os ficheiros do antigo validador lexical e o endpoint de preview que já não são utilizados.

## 4. Testes após publicação

1. Abra o portal em PT e confirme que listas e descrições existentes continuam visíveis sem chamadas ao Google durante a navegação.
2. Com um texto de teste aprovado, grave uma alteração em PT e confirme a versão EN.
3. Repita com `Substitua-os` e confirme que não aparece qualquer erro de “palavra não reconhecida”.
4. Confirme que uma segunda gravação sem alterações reutiliza a tradução existente.
5. Abra o portal em EN, faça uma alteração de teste autorizada e confirme a versão PT.
6. Consulte os logs PHP e confirme que não existem erros de cURL, SQL ou permissões do ficheiro privado.
7. Confirme que a linha do período atual em `translation_daily_usage` aumenta apenas em traduções novas e permanece inalterada em reutilizações da cache.
8. Depois da aprovação do template, ative o alerta e confirme que não existe qualquer envio sem a quota ter sido atingida.

## 5. Rollback

Se o teste falhar, publique o commit anterior. As migrações 023 e 024 podem permanecer; o código anterior ignora `translation_daily_usage`. Se for necessário repor também a base de dados exatamente ao estado anterior, restaure o backup em vez de alterar manualmente os índices.
