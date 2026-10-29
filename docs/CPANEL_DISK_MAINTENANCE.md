# Limpeza automática de quota no cPanel/WHM

Acesso existente: segredo `WHM_API_TOKEN` no environment GitHub `management-hub-production`; não exportar o segredo. Branch protegida de produção: `agent/room-item-assignments`.

## Agendamento independente de pedidos ao ChatGPT

O GitHub Actions só agenda workflows que estejam na branch por defeito (`main`). A scheduler em `main` (workflow `cpanel-disk-schedule.yml`) executa de duas em duas horas e despacha o workflow `cpanel-auto-cleanup.yml` para a branch de produção. A cópia placeholder do workflow na `main` não usa segredos nem limpa ficheiros; a implementação ativa fica exclusivamente na branch de produção e obtém o segredo pelo ambiente protegido. **São necessárias ambas as integrações** para o agendamento funcionar.

## Limites de segurança

- Consultar a quota por `Quota/get_quota_info` e parar sem alterações abaixo de 90%. Se o formato da resposta não puder ser validado, falhar sem eliminar nada.
- Acima de 90%, listar apenas `.npm/_cacache` e subpastas regulares até 5 níveis; exclusivamente ficheiros normais com pelo menos 14 dias.
- Antes de cada exclusão, voltar a consultar metadata do ficheiro e exigir tamanho e mtime idênticos aos observados.
- Eliminação definitiva via `Fileman/delete_file` com `skip_trash=1`, sem mover para `.trash`. Limite de 40 ficheiros e 200 MiB por execução.
- Nunca apagar `mail/`, `public_html/`, `room-check-backups/`, `room-check-private/`, `.cache/` genérico, logs ou diretórios; não remover backups automaticamente.
- O diagnóstico UAPI precisa de prova de autenticação real, não apenas status HTTP 200.
- A automação apenas pode recuperar a quota dentro do limite dos ficheiros descartáveis encontrados; **não garante 1 GB** nem substitui revisão da retenção dos backups.
- Depois de ativar, confirmar na primeira execução os valores quota antes/depois e testar a receção real de email. Sem essas verificações, não declarar limpeza efetiva nem correio recuperado.

## Recuperação em caso de falha

Rever a execução protegida, identificar o código de erro sem imprimir respostas do servidor ou tokens, corrigir no PR e voltar a testar. Não repetir indiscriminadamente uma operação destrutiva. O job protegido usa `concurrency` para não sobrepor execuções.
