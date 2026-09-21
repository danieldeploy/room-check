# Transferência das fontes do gerador e do descodificador ZKTeco

Estas cópias servem de referência para continuar o desenvolvimento no Codex. Esta transferência preserva a lógica e a interface dos anexos; não integra, instala, ativa ou executa estes componentes no Management Hub, no Google Apps Script ou no Windows.

## Proveniência e inventário

- Anexo `room_code_generator_latest(1).zip`: SHA-256 `ea92514d5940a568212c0922932ca6ad1ba7d29241c9d5c2263e59acf88e5618`.
- Anexo `zkteco%20decript(1).txt`: SHA-256 `f4bb93ef409f2b871ec8bcc8517308bbe816738a529ae5ac904b91cfd5789724`.
- Todos os quatro membros do ZIP estão em `room-code-generator/`.
- O TXT contém um userscript JavaScript; a cópia legível sanitizada é `zkteco-codec/decode-password.user.js`.
- Os hashes do conteúdo original identificam os anexos fornecidos e não implicam validação de uma instalação existente.

| Ficheiro | Bytes no original | SHA-256 original | SHA-256 da cópia |
| --- | ---: | --- | --- |
| `Code.gs` | 42795 | `542f80e646eb84ac0d12734760abe3e578e6fade71447fc14607439786842be8` | `542f80e646eb84ac0d12734760abe3e578e6fade71447fc14607439786842be8` |
| `Index.html` | 36767 | `f2a001feb1fc52eb165a3d74da16dcc8e4d1910c47a678f0194a4182006a2702` | `f2a001feb1fc52eb165a3d74da16dcc8e4d1910c47a678f0194a4182006a2702` |
| `appsscript.json` | 97 | `a886e52a033e6d9f29678875c0eac33e04b8f1bf2ab174b89300f2b7b8ea60c4` | `a886e52a033e6d9f29678875c0eac33e04b8f1bf2ab174b89300f2b7b8ea60c4` |
| `VERSIONS.txt` | 642 | `5115e801bdacb8c9f320eef914e63482d59eb0ada365af9950409ad7cea43258` | `5115e801bdacb8c9f320eef914e63482d59eb0ada365af9950409ad7cea43258` |
| `decode-password.user.js (origem TXT)` | 4398 | `f4bb93ef409f2b871ec8bcc8517308bbe816738a529ae5ac904b91cfd5789724` | `a8ae11d8193caf0862dcec579293c1fe298f4be15a6277eaedc629543f93be2c` |

## Sanitização e exclusões

- `Code.gs`, `Index.html`, `appsscript.json` e `VERSIONS.txt` foram preservados byte a byte. Não foram encontrados nestes quatro ficheiros credenciais, endereços privados fixos, identificadores de Sheets/deploys ou PINs atribuídos a hóspedes/quartos.
- Os literais `0000` e `9999` são limites do universo de quatro dígitos, formatos e um placeholder de entrada; não constituem um registo de PINs reais e foram preservados. O mapeamento técnico de pares de letras para dígitos do userscript também foi preservado.
- No userscript, apenas o valor de `@match` que identificava a instalação foi substituído por `http://zkaccess.example.invalid/*`. Este domínio é um placeholder e tem de ser substituído numa cópia privada antes de qualquer uso.
- Não foram excluídas funções, estilos, textos da interface ou ficheiros de código. Nenhum original foi alterado.
- Os anexos não contêm a folha de cálculo, a lista operacional de códigos, o histórico, o estado atual dos quartos, as propriedades da instalação Apps Script, nem a base de dados/instalação ZKAccess. Esses dados não são recuperados pela cópia destas fontes e não devem ser publicados no Git.
- `VERSIONS.txt` conserva a designação original “CURRENT PRODUCTION FILES”; essa designação descreve o anexo recebido e não certifica a versão atualmente instalada.

## Configuração privada necessária para o gerador

1. Obter o projeto Google Apps Script e a folha de cálculo autorizados, ou preparar cópias privadas para desenvolvimento. Os anexos não permitem determinar qual é a instalação ativa.
2. A folha exige `Interface` e `Codes`; `Codes` fornece a sequência operacional na coluna A, com quatro dígitos e zeros iniciais preservados. O código lê essa sequência; não traz o respetivo conteúdo no ZIP.
3. `setup()` deve ser invocado apenas no contexto da folha pretendida. Guarda o respetivo ID na propriedade de script `spreadsheetId`, cria/migra `Rooms`, `History` e `UsedCodes`, formata e protege folhas e altera o estado/cache. Não foi executado durante esta transferência.
4. Para continuar uma instalação existente, preservar os dados privados das folhas e as propriedades `nextCodeIndex`, `dailyNormalizedDate`, `autoUsedCodeFlagsV2` e `usedCodeHistoryMigrated30dV1`. Não substituir o histórico real por uma folha vazia supondo que o ZIP contém esse estado.
5. Manter o fuso `Europe/Lisbon` do manifesto e rever a correspondência dos seis quartos predefinidos antes de adaptar a outra propriedade. A fonte distingue a regra de 30 dias da entrada manual do ciclo próprio da geração automática.
6. A conta de execução, autorizações Google, destinatários com acesso e implantação da Web App são configurações privadas externas a estes ficheiros. O endereço e o ID da implantação não estão incluídos. A interface depende de `google.script.run` e não funciona apenas abrindo o HTML como página estática.
7. O gerador não inclui um cliente que envie códigos ao ZKAccess/Cloudbeds. Essa integração terá de ser implementada/validada separadamente se fizer parte do trabalho seguinte.

## Configuração privada necessária para o userscript ZKTeco

- Substituir `@match` por um padrão específico da instalação ZKAccess autorizada, incluindo o esquema, host, porta e âmbito de caminhos que se apliquem. Não usar um padrão universal para outras aplicações.
- O script destina-se à interface ZKAccess 5.3 indicada no cabeçalho original, depende do seletor `#id_Password` e do formato textual `Password(...)` dos logs.
- A lógica converte os pares de letras da tabela em dígitos e torna visível o conteúdo do campo de palavra-passe/PIN na própria página. Preservar o uso em ambiente autorizado e restrito; esta cópia não contém credenciais ou PINs reais.
- Não é um serviço Windows nem um conector do Hub, e não grava diretamente na base de dados. A compatibilidade com a instalação real, o gestor de userscripts e o comportamento de gravação da interface não foram testados.

## Validação realizada

- Leitura integral dos cinco ficheiros de origem.
- Comparação byte a byte dos quatro ficheiros do gerador com os membros do ZIP.
- `node --check` (v24.19.0) sobre `Code.gs`, o único bloco JavaScript extraído de `Index.html` e o userscript sanitizado; três verificações sintáticas aprovadas, sem executar as fontes.
- `appsscript.json` analisado como JSON e confirmado com `Europe/Lisbon` / `V8`.
- A validade de sintaxe não verifica APIs Apps Script, permissões, interface renderizada, dados reais, acesso aos controladores ou execução operacional. Nenhum serviço vivo foi contactado.
