# Fontes anteriores do projeto

Estas fontes acompanham o Management Hub para dar continuidade no Codex às automações que estavam apenas nos anexos do projeto. São referências de desenvolvimento: não foram integradas na aplicação PHP nem instaladas ou ativadas em serviços reais durante esta passagem.

## Onde encontrar cada anexo

| Anexo fornecido | Cópia de desenvolvimento | Utilização |
| --- | --- | --- |
| `room_code_generator_latest(1).zip` | [`room-code-generator/`](room-code-generator/) | Gerador Google Apps Script, interface, manifesto e notas de versão; quatro ficheiros preservados sem alterações. |
| `ZKCloudbedsAuto_v5_1_direct_post_save.zip` | [`zkcloudbeds-v5.1/`](zkcloudbeds-v5.1/) | Fonte principal fornecida para Cloudbeds → ZKAccess, incluindo Direct POST e fallback visual. |
| `ZKCloudbedsAuto_v5_0_enterprise.zip` | [`zkcloudbeds-v5.0/`](zkcloudbeds-v5.0/) | Versão anterior para comparação e recuperação de decisões. |
| `zkteco%20decript(1).txt` | [`zkteco-codec/decode-password.user.js`](zkteco-codec/decode-password.user.js) | Userscript de referência; endereço privado substituído por placeholder. |
| `Invoicees(1).html` | [`Invoicees.html`](Invoicees.html) | Proposta visual móvel Faturas e Portais; nome/email pessoais substituídos por exemplos. Ler [INVOICEES_MIGRATION_NOTES.md](INVOICEES_MIGRATION_NOTES.md). |

Antes de alterar ou preparar a instalação, ler [GENERATOR_MIGRATION_NOTES.md](GENERATOR_MIGRATION_NOTES.md), [ZK_MIGRATION_NOTES.md](ZK_MIGRATION_NOTES.md) e o `MIGRATION.md` da versão pretendida. Aí estão os hashes dos originais, as transformações mínimas e os limites de validação.

## Configuração e execução

- As configurações reais dos ZIPs foram excluídas. `config.example.json` contém apenas valores não operacionais; exige uma cópia privada preenchida antes de qualquer execução.
- Dados de hóspedes, PINs reais, sessões, relatórios, folhas Google e propriedades da instalação permanecem fora deste repositório. Os anexos não constituem backup desses dados.
- As fontes antigas podem gerar dados sensíveis mesmo em `dry_run`. Não executar instaladores ou testes de portais apenas para confirmar que o código foi transferido.
- Não publicar esta pasta em `public_html`. O manifesto `.cpanel.yml` atual usa uma lista explícita de caminhos e não inclui `docs/`.
- O executor de faturas em `invoice-runner/` é outro componente. Não substituir nem misturar automaticamente os executores de faturas e ZKTeco.
- A presença destas fontes permite leitura, comparação e desenvolvimento; não comprova que a versão instalada no Windows seja igual aos anexos recebidos.

## Continuidade

Consultar [PROJECT_CONTINUITY.md](../PROJECT_CONTINUITY.md) e [CODEX_HANDOFF.md](../CODEX_HANDOFF.md). A proposta visual `Invoicees.html` foi recuperada e incluída nesta pasta em 22 de setembro de 2026. A transferência dos cinco anexos disponíveis está concluída; a implementação da proposta permanece por validar. Os arquivos originais e o histórico completo das conversas continuam no projeto ChatGPT original.
