# Migração das fontes Cloudbeds / ZKAccess

Estas pastas são cópias sanitizadas dos anexos fornecidos pelo utilizador para
continuidade de desenvolvimento no Codex. Não constituem implantação ou ativação.
Os ZIP originais não foram alterados nem copiados para o repositório público.

## Inventário e proveniência

| Versão | Anexo de origem | Raiz no ZIP → pasta de referência | SHA-256 do ZIP original |
|---|---|---|---|
| 5.1 | `ZKCloudbedsAuto_v5_1_direct_post_save.zip` | `v5_1/` → `zkcloudbeds-v5.1/` | `dcfbea00866a0f58685e63a73900aaf03974e66e4d461e3af2b2e7d9bcf22e6c` |
| 5.0 | `ZKCloudbedsAuto_v5_0_enterprise.zip` | `v5/` → `zkcloudbeds-v5.0/` | `48293b02460c6b5f0038a9c63d51b8d147b1b111ed2f7c09a1d39fa6032adbb6` |

## Transformações de privacidade

- `config.json` excluído em ambas as versões. Criado `config.example.json` com
  domínios `.invalid`, identificadores simbólicos e credenciais vazias; `dry_run`
  permanece `true`. As restantes opções não confidenciais foram preservadas.
- Um URL Cloudbeds específico da propriedade, fixo em `src/cloudbeds.py`, passou
  para a chave privada `cloudbeds_reservation_url_template`. O código substitui
  `{reservation_id}` pelo mesmo `rid` anteriormente interpolado. Esta é a única
  alteração executável das fontes Python; o algoritmo original foi preservado.
- Identificador da propriedade removido do README; indicação de `config.json`
  distribuído atualizada para `config.example.json`. O título histórico V5.0 foi
  mantido e a discrepância do pacote V5.1 foi registada em `MIGRATION.md`.
- Não se copiaram bytecode ou caches. O ZIP V5.1 continha um ficheiro
  `src/__pycache__/zkaccess.cpython-313.pyc`, excluído por ser binário gerado.
- Adicionados `MIGRATION.md` e `.gitignore` local em cada versão. Configurações,
  perfis, logs, relatórios e screenshots de execução devem permanecer privados.

## Estado das versões

V5.1 acrescenta ao V5.0 a preservação dos pares do formulário, a gravação do PIN
por POST direto e o fallback visual. Todos os outros ficheiros de origem, incluindo
o README que ainda indica V5.0, são iguais entre os dois ZIP. V5.0 fica como
referência histórica; não deve ser tratado como a implementação mais recente.

A implementação existente pode escrever PINs e dados de hóspedes nos logs e
relatórios e usa APIs internas/seletores de portais. Esses comportamentos não foram
alterados nem exercitados. A configuração `dry_run` não bloqueia leitura/login nem
produção desses dados. Antes de uma futura ativação devem ser revistas a proteção
dos ficheiros e as integrações reais. Nenhum valor privado foi incluído nestas notas.

## Validação da transferência

- V5.1: 21 ficheiros finais; 9 fontes Python analisadas com `ast.parse`; 1 exemplo JSON válido. Excluídos: `config.json`, `src/__pycache__/zkaccess.cpython-313.pyc`.
- V5.0: 21 ficheiros finais; 9 fontes Python analisadas com `ast.parse`; 1 exemplo JSON válido. Excluídos: `config.json`.
- Confirmados caminhos das importações Python relativas e dos alvos locais dos
  scripts (`src/app.py`, `requirements.txt` e `run_auto_silent.bat`).
- Verificado que os valores privados das configurações originais e seus
  identificadores/hosts não aparecem nos ficheiros finais.
- Não foram instaladas dependências, importadas/executadas as aplicações, enviados
  pedidos de rede, usados browsers/sessões, criadas tarefas ou alterados sistemas.
- A sintaxe válida não demonstra funcionamento nos portais, compatibilidade com
  o Windows, segurança operacional nem integração no Management Hub.
