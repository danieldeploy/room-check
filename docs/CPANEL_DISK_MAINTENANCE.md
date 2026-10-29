# Limpeza automática de quota no cPanel/WHM

Acesso existente: segredo `WHM_API_TOKEN` no environment GitHub `management-hub-production`; não exportar o segredo. Branch protegida de produção: `agent/room-item-assignments`.

## Agendamento independente de pedidos ao ChatGPT

O GitHub Actions só agenda workflows que estejam na branch por defeito (`main`). A scheduler em `main` (workflow `cpanel-disk-schedule.yml`) executa diariamente às 03:17 UTC e despacha o workflow `cpanel-auto-cleanup.yml` para a branch de produção. A cópia placeholder do workflow na `main` não usa segredos nem limpa ficheiros; a implementação ativa fica exclusivamente na branch de produção e obtém o segredo pelo ambiente protegido. **São necessárias ambas as integrações** para o agendamento funcionar.

## Limites de segurança

- Consultar a quota por `Quota/get_quota_info` e parar sem alterações abaixo de 90%. Se o formato da resposta não puder ser validado, falhar sem eliminar nada.
- Acima de 90%, listar apenas caches explicitamente permitidas (`.npm/_cacache`, `.cache/pip`, `.cache/uv`, ZIPs de instalação Puppeteer extraídos) e subpastas regulares até 5 níveis; exclusivamente ficheiros normais com pelo menos 14 dias.
- Antes de cada exclusão, voltar a consultar metadata do ficheiro e exigir tamanho e mtime idênticos aos observados.
- Eliminação definitiva via `Fileman/delete_file` com `skip_trash=1`, sem mover para `.trash`. Limite de 40 ficheiros e 200 MiB por execução.
- Nunca apagar `mail/`, `public_html/`, `room-check-backups/`, `room-check-private/`, `.cache/` genérico, logs ou diretórios automaticamente; não remover backups automaticamente.
- O diagnóstico UAPI precisa de prova de autenticação real, não apenas status HTTP 200.
- A automação apenas pode recuperar a quota dentro do limite dos ficheiros descartáveis encontrados; **não garante 1 GB** nem substitui revisão da retenção dos backups.
- Depois de ativar, confirmar na primeira execução os valores quota antes/depois e testar a receção real de email. Sem essas verificações, não declarar limpeza efetiva nem correio recuperado.

## Recuperação em caso de falha

Rever a execução protegida, identificar o código de erro sem imprimir respostas do servidor ou tokens, corrigir no PR e voltar a testar. Não repetir indiscriminadamente uma operação destrutiva. O job protegido usa `concurrency` para não sobrepor execuções.

## Política de backups

Preservar sempre pelo menos os 3 backups completos mais recentes e todos os backups com menos de 45 dias. Não apagar backups sem primeiro validar os manifests e checksums dos artefactos, confirmar um restauro recuperável e confirmar que não há deploy/recovery em curso. A rotina atual apenas faz inventário de backups, sem os apagar, porque a leitura WHM Fileman não permite por si só comprovar a recuperação. A extensão da remoção definitiva de backups antigos fica bloqueada até existir essa prova técnica.


## Auditoria diária de crescimento

Antes da limpeza, `deploy/cpanel_deep_audit.py` consulta a utilização do armazenamento (incluindo mail, sem aceder a mensagens), a distribuição por idade e tamanho dos backups e os diretórios de versões de navegadores do Puppeteer. Usa a API WHM já protegida e expõe apenas totais agregados em MB, não caminhos de hóspedes, conteúdo de documentos ou segredos.

A API antiga `DiskUsage::fetchdiskusagewithextras` permite obter os totais reais por pasta; `Fileman::search` permite estimar os ficheiros dos diretórios explicitamente autorizados. O diagnóstico é de leitura e falha sem apagar nada se houver resposta inválida.

Este diagnóstico não é por si só autorização para eliminar backups antigos ou browsers instalados. Continuam a ser obrigatórias cópias recuperáveis e provas de utilização antes de reduzir a retenção ou remover versões de browsers.

## Conservação sem perda de backups

O auxiliar privado de pós-publicação `deploy/compress_release_dumps.php` comprime até 50 dumps SQL com mais de 48 horas. Confirma o SHA-256 do manifesto dos artefactos, verifica a descompressão byte a byte, atualiza o manifesto atomicamente para formato 2 (com `database.sql.gz`) e só então elimina o SQL original redundante. Preserva todas as datas e os diretórios dos backups. Se falhar, mantém os dados originais e a publicação não é bloqueada.

O formato 1 continua a conter `database.sql`. Para recuperar o formato 2, descomprimir `database.sql.gz` antes da importação MySQL, com os mesmos controlos de recuperação privada. A compactação não prova por si só um restauro funcional da base de dados.


## Plano de retenção de backups e teste de restauração

O utilitário `deploy/cpanel_backup_retention.py` executa primeiro somente um **diagnóstico sem eliminação** pelo workflow WHM protegido. Preserva as últimas 3 cópias, um backup diário durante 45 dias (Europe/Lisbon), um por semana até 180 dias, um por mês até 365 dias, um por trimestre para histórico mais antigo, e o backup mais antigo. Só marca duplicados com mais de 48 horas como candidatos. Verifica em leitura o manifesto de todas as cópias que ficariam e restaura o SQL mais recente num MySQL 8 descartável do GitHub Actions, sem imprimir dados privados. A fase `--apply`, ainda desativada, está limitada a 25 diretórios e 200 MiB por execução, com revalidação de listagem antes de cada exclusão. A restauração integral dos arquivos da aplicação continua por confirmar; não eliminar backups sem os testes necessários.


## Ativação segura da retenção (29/10/2026)

A execução protegida [40964594901](https://github.com/danieldeploy/room-check/actions/runs/40964594901) confirmou em teste sem eliminação: 172 backups, 23 preservados segundo a política diária e histórica, 148 candidatos com mais de 48h; a base SQL mais recente foi recuperada num MySQL temporário (sem aceder à base de produção). Após este resultado, o job protegido foi preparado para utilizar `--apply`, limitado a 25 backups e 200 MiB por dia, com validação de manifestos, lista de ficheiros permitidos, verificação da listagem antes de cada remoção e medição da quota antes e depois. Preserva os três mais recentes, uma cópia por dia durante 45 dias, uma por semana até 180 dias, uma por mês até 365 dias, uma por trimestre para os mais antigos, e sempre a cópia mais antiga. Mantém `mail/`, `public_html/` e automações intactos. Isto não valida um restauro completo dos arquivos da aplicação.
