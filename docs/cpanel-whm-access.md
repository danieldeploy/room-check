# Acesso existente ao cPanel / WHM (runbook)

## Regra obrigatória antes de pedir credenciais

Para tarefas no cPanel/WHM do projeto, **reutilizar primeiro o acesso existente GitHub Actions → WHM API → cPanel/UAPI**. Não presumir que falta configurar a API e não pedir novo login/token antes de verificar os workflows e os segredos já configurados. A API do Management Hub é distinta e não substitui a API do cPanel.

## Configuração estabelecida (histórico de 22/09/2026 e 08/10/2026)

- WHM: `https://server50.romania-webhosting.com:2087`
- Reseller: `fazenda`
- Contas cPanel referidas: `welcome` e `city`
- Token: segredo cifrado **`WHM_API_TOKEN`** do GitHub Environment **`management-hub-production`**; nunca guardar o valor no repositório, ficheiros públicos, conversas ou logs.
- Permissões WHM anteriormente confirmadas: `cpanel-api` e `list-accts`. Outras operações podem estar limitadas; `parse_dns_zone` já apresentou restrição.
- Em 22/09/2026 foram confirmadas consultas autenticadas à conta `welcome` por GitHub Actions; em 08/10/2026 foi referida utilização WHM/UAPI nas contas `welcome` e `city`.
- O cliente de manutenção documentado no histórico operava limitado à conta `welcome` e suportava modo de diagnóstico sem escrita (`writes:false`). Confirmar o comportamento real dos workflows na versão atual antes de executar.

## Procedimento para uma nova tarefa

1. Consultar os workflows em `.github/workflows/`, documentação de deploy e histórico de PRs (nomeadamente #74), procurando a chamada existente WHM/UAPI. Consultar os nomes e a presença de segredos apenas por mecanismos seguros; não tentar mostrar o token.
2. Executar uma consulta **só de leitura** através do workflow já autorizado, com o Environment correto e com a conta cPanel explicitamente selecionada; confirmar autenticação e permissões reais.
3. Para limpeza de disco, listar tamanhos, ficheiros, datas, dependências e retenção. **Não apagar** emails, configuração, base de dados, backup único, ficheiros de produção ou artefactos de recuperação sem análise.
4. Se houver suporte técnico e autorização, executar alterações limitadas às pastas/ficheiros identificados, com inventário antes/depois e verificação de funcionamento. Evitar comandos ou SQL arbitrários.
5. Se o workflow já não existir ou falhar, diagnosticar o erro e a permissão em leitura antes de pedir qualquer novo acesso.

## Estado desta documentação

Os dados acima foram recuperados do histórico do projeto; a autenticação WHM **não foi novamente executada em 29/10/2026**. Uma resposta HTTP 200 na porta 2083 pode ser apenas a página de login e **não comprova** autenticação UAPI.
