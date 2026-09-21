# ZKCloudbedsAuto 5.1 — fontes de referência

Cópia do anexo original, preparada para desenvolvimento no Codex. Não está instalada,
ativada, agendada ou integrada no Management Hub. Não executar os ficheiros BAT como
parte da importação ou da implantação do Hub.

## Configuração privada

O `config.json` original foi excluído porque contém configuração operacional e
credenciais. Para uma futura instalação autorizada, copiar `config.example.json`
para `config.json` **apenas numa pasta local privada, fora do repositório público e
fora de public_html**, e configurar os endereços e credenciais reais nesse ficheiro.
Os exemplos usam domínios `.invalid`, utilizador/password vazios e `dry_run: true`;
não permitem operar os sistemas reais sem configuração.

O código continua a ler `config.json` na raiz desta aplicação. `config.json`, perfis
do browser, logs, relatórios e screenshots são ignorados pelo `.gitignore` local.
Uma regra de ignore não remove ficheiros já versionados: não adicionar esses dados
manualmente nem com `git add -f`.

Foi externalizado um único URL Cloudbeds que antes estava fixo em `src/cloudbeds.py`:
`cloudbeds_reservation_url_template` deve conter a base correta da propriedade e o
marcador literal `{reservation_id}`. O método continua a inserir o identificador da
reserva no mesmo ponto do URL. `cloudbeds_dashboard_url` e esse template devem
referir-se à mesma propriedade. Não introduzir URLs reais no código versionado.
Não existem credenciais nem identificadores reais no ficheiro de exemplo.

## O que foi preservado

- Extração do PIN das notas de reservas, procura de funcionários por nome, tabela
  de descodificação ZKAccess, comparação e escrita condicionada por `dry_run`.
- Scripts originais de instalação e execução e o agendamento Windows às 12:55,
  guardados apenas para referência. O instalador de tarefa usa `/F`, podendo
  substituir uma tarefa com o mesmo nome; não foi executado.
- As seis designações genéricas de quartos no teste ZKAccess, os seletores de UI,
  caminhos relativos das APIs, tempos de espera e opções de configuração existentes.
- Requisito original `playwright>=1.44`; não foi instalada nem fixada uma versão.

## Limitações herdadas e validação

O modo `dry_run` impede a alteração do PIN pelo fluxo `auto`, mas ainda permite
login, leitura dos sistemas, criação de perfis e produção de logs, screenshots e
relatórios. O código original inclui nomes de hóspedes, identificadores e PINs
nesses ficheiros e mensagens. Não foram gerados nesta transferência; quando forem
gerados, têm de permanecer privados. Estas fontes não foram submetidas a hardening.

O README original declara V5.0, inclusive no pacote V5.1. O nome do anexo/pasta
identifica a versão; a diferença V5.1 é a gravação por POST direto com fallback
visual. A referência ao ID da propriedade no README foi substituída por
`[PROPERTY_ID]`. Não se conclui que o resto do README esteja atualizado.

Foram verificadas a sintaxe Python, a leitura do JSON e as referências de ficheiros
locais. Não houve instalação, importação/execução das aplicações, login, rede,
acesso a portais, leitura/escrita de PINs, criação de tarefas nem teste no Windows.
A transferência não valida as APIs internas, os seletores atuais, a autenticação,
a gravação, o Chrome/sandbox, a integração no Hub ou a operação em produção.
