# Manutenção de disco por WHM — acesso existente

O segredo `WHM_API_TOKEN` já pertence ao ambiente protegido `management-hub-production`, não deve ser exportado para ChatGPT nem colocado no servidor Windows.

## Utilização

Workflow manual: **Protected cPanel disk maintenance**, no branch de produção `agent/room-item-assignments`. Ele reutiliza o segredo e seleciona a conta `welcome`.

- `inspect` consulta metadados do ficheiro.
- `trash` exige caminho exato, tamanho e mtime devolvidos pela inspeção; revalida imediatamente antes de mover.
- Primeira versão deliberadamente limitada a **ficheiros individuais em `.npm/_cacache/`**; não permite diretórios, `mail/`, backups, `.cache/` genérico, logs ou código da aplicação.
- Não é uma solução automática para quota cheia: `trash` move para `.trash`, que pode continuar a contabilizar espaço. A remoção definitiva requer um mecanismo próprio validado e com proteções de eliminação separadas.
- A execução do workflow não está disponível como ação diretamente exposta pelo conector GitHub desta conversa. Um agente com acesso autorizado a GitHub Actions pode iniciar o workflow no separador **Actions** da branch de produção. Não é necessário novo token ou login cPanel.

## Próximos passos para resolver quota cheia

1. Fazer inventário de `.cache/`, `.npm/`, `logs/` e `room-check-backups/` sem escrita.
2. Priorizar ficheiros comprovadamente regeneráveis; preservar backup recuperável e todos os emails.
3. Implementar e testar remoção definitiva apenas dos itens identificados, com quotas antes/depois.
4. Confirmar entrega de um email de teste real, além de acesso ao webmail.

Não interpretar o primeiro HTTP 200 de uma página de login como autenticação WHM.
