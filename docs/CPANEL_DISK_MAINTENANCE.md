# Limpeza automática de quota no cPanel/WHM

Acesso existente: segredo `WHM_API_TOKEN` no environment GitHub `management-hub-production`; não exportar o segredo. Branch protegida de produção: `agent/room-item-assignments`.

## Agendamento independente de pedidos ao ChatGPT

O GitHub Actions só agenda workflows que estejam na branch por defeito (`main`). A scheduler em `main` (workflow `cpanel-disk-schedule.yml`) executa diariamente às 03:17 UTC e despacha o workflow `cpanel-auto-cleanup.yml` para a branch de produção. A cópia placeholder do workflow na `main` não usa segredos nem limpa ficheiros; a implementação ativa fica exclusivamente na branch de produção e obtém o segredo pelo ambiente protegido. **São necessárias ambas as integrações** para o agendamento funcionar.

## Limites de segurança

- Consultar a quota por `Quota/get_quota_info` e parar sem alterações abaixo de 90%. Se o formato da resposta não puder ser validado, falhar sem eliminar nada.
- Acima de 90%, listar apenas caches explicitamente permitidas (`.npm/_cacache`, `.cache/pip`, `.cache/uv`, ZIPs de instalação Puppeteer extraídos) e subpastas regulares até 5 níveis; exclusivamente ficheiros normais com pelo menos 14 dias.
- Antes de cada exclusão, voltar a consultar metadata do ficheiro e exigir tamanho e mtime idênticos aos observados.
- Eliminação definitiva via `Fileman/delete_file` com `skip_trash=1`, sem mover para `.trash`. Limite de 40 ficheiros e 200 MiB por execução.
- A **rotina de cache** nunca apaga `mail/`, `public_html/`, `room-check-backups/`, `room-check-private/`, `.cache/` genérico, logs ou diretórios; a rotina **separada** de retenção de backups verificados pode eliminar duplicados segundo a política aprovada, como descrito abaixo.
- O diagnóstico UAPI precisa de prova de autenticação real, não apenas status HTTP 200.
- A automação apenas pode recuperar a quota dentro do limite dos ficheiros descartáveis encontrados; **não garante 1 GB** nem substitui revisão da retenção dos backups.
- Depois de ativar, confirmar na primeira execução os valores quota antes/depois e testar a receção real de email. Sem essas verificações, não declarar limpeza efetiva nem correio recuperado.

## Recuperação em caso de falha

Rever a execução protegida, identificar o código de erro sem imprimir respostas do servidor ou tokens, corrigir no PR e voltar a testar. Não repetir indiscriminadamente uma operação destrutiva. O job protegido usa `concurrency` para não sobrepor execuções.

## Política de backups

A política ativa preserva sempre os 3 backups mais recentes, a cópia mais antiga, uma cópia por dia até 45 dias, uma por semana até 180 dias, uma por mês até 365 dias e uma por trimestre depois disso. Só elimina duplicados com pelo menos 48 horas, após validação de manifests/formato e teste de restauro da base SQL mais recente num MySQL 8 descartável. O controlo é limitado a 25 diretórios/200 MiB por execução. Não foi comprovado o restauro integral dos arquivos da aplicação; preservar os pontos de recuperação definidos.


## Auditoria diária de crescimento

Antes da limpeza, `deploy/cpanel_deep_audit.py` consulta a utilização do armazenamento (incluindo mail, sem aceder a mensagens), a distribuição por idade e tamanho dos backups e os diretórios de versões de navegadores do Puppeteer. Usa a API WHM já protegida e expõe apenas totais agregados em MB, não caminhos de hóspedes, conteúdo de documentos ou segredos.

A API antiga `DiskUsage::fetchdiskusagewithextras` permite obter os totais reais por pasta; `Fileman::search` permite estimar os ficheiros dos diretórios explicitamente autorizados. O diagnóstico é de leitura e falha sem apagar nada se houver resposta inválida.

Este diagnóstico não é por si só autorização para eliminar backups antigos ou browsers instalados. Continuam a ser obrigatórias cópias recuperáveis e provas de utilização antes de reduzir a retenção ou remover versões de browsers.

## Conservação sem perda de backups

O auxiliar privado de pós-publicação `deploy/compress_release_dumps.php` comprime até 50 dumps SQL com mais de 48 horas. Confirma o SHA-256 do manifesto dos artefactos, verifica a descompressão byte a byte, atualiza o manifesto atomicamente para formato 2 (com `database.sql.gz`) e só então elimina o SQL original redundante. Preserva todas as datas e os diretórios dos backups. Se falhar, mantém os dados originais e a publicação não é bloqueada.

O formato 1 continua a conter `database.sql`. Para recuperar o formato 2, descomprimir `database.sql.gz` antes da importação MySQL, com os mesmos controlos de recuperação privada. A compactação não prova por si só um restauro funcional da base de dados.


## Plano de retenção de backups e teste de restauração

O utilitário `deploy/cpanel_backup_retention.py` executa um **diagnóstico e um teste SQL de restauro** pelo workflow WHM protegido antes de qualquer eliminação. Preserva as últimas 3 cópias, um backup diário durante 45 dias (Europe/Lisbon), um por semana até 180 dias, um por mês até 365 dias, um por trimestre para histórico mais antigo, e o backup mais antigo. Só marca duplicados com mais de 48 horas como candidatos. Verifica em leitura o manifesto de todas as cópias que ficariam e restaura o SQL mais recente num MySQL 8 descartável do GitHub Actions, sem imprimir dados privados. A fase `--apply`, ativada em 29/10/2026, está limitada a 25 diretórios e 200 MiB por execução, com revalidação de listagem antes de cada exclusão. A restauração integral dos arquivos da aplicação continua por confirmar; não eliminar backups sem os testes necessários.


## Ativação segura da retenção (29/10/2026)

A execução protegida [40964594901](https://github.com/danieldeploy/room-check/actions/runs/40964594901) confirmou em teste sem eliminação: 172 backups, 23 preservados segundo a política diária e histórica, 148 candidatos com mais de 48h; a base SQL mais recente foi recuperada num MySQL temporário (sem aceder à base de produção). Após este resultado, o job protegido foi preparado para utilizar `--apply`, limitado a 25 backups e 200 MiB por dia, com validação de manifestos, lista de ficheiros permitidos, verificação da listagem antes de cada remoção e medição da quota antes e depois. Preserva os três mais recentes, uma cópia por dia durante 45 dias, uma por semana até 180 dias, uma por mês até 365 dias, uma por trimestre para os mais antigos, e sempre a cópia mais antiga. Mantém `mail/`, `public_html/` e automações intactos. Isto não valida um restauro completo dos arquivos da aplicação.


## Conclusão da cache NPM e auditoria de logs/Puppeteer (30/10/2026)

- O workflow protegido [41129358547](https://github.com/danieldeploy/room-check/actions/runs/41129358547) terminou oito lotes de limpeza NPM com sucesso, removendo 290 ficheiros >14 dias da cache específica `.npm/_cacache` (~4.826 MiB). O último inventário confirmou **zero ficheiros** nessa cache; a pasta `.npm` permanece com ~3.8 MiB de outros elementos não abrangidos.
- A quota verificada no fim era ~82.06%; os valores exatos variam com a atividade do alojamento.
- Os 11 logs totalizam ~150.68 MiB: 3 erros (~144.78 MiB), com 110.67 MiB num ficheiro recentemente modificado e 33.94 MiB num ficheiro com 372 dias sem alteração; 4 arquivos comprimidos (~5.27 MiB), 4 outros (~0.63 MiB). Apenas **metadados** foram inspecionados; nenhum log foi eliminado. A eliminação de históricos requer análise de retenção, não uma exclusão indiscriminada.
- As instalações Chrome/Puppeteer continuam com ~704.8 MiB, incluindo duas versões Headless Shell (~311.7 MiB). Ainda não foi verificada a dependência real de todas as versões; não as remover automaticamente.
- A intervenção não tocou nos emails nem testou entrega/receção, deixados para a próxima fase por instrução expressa.


## Retirada pontual de log de erros muito antigo (31/10/2026)

Esta ação **não faz parte da limpeza diária** nem abrange `mail/`. A execução protegida `.github/workflows/cpanel-old-log-reclaim.yml` recebe o segredo WHM do ambiente existente. Reutiliza `Fileman::search` de leitura, e só autoriza `Fileman::delete_file` para um único ficheiro regular diretamente sob `~/logs`, de categoria erros, não comprimido, entre 20–60 MiB e sem modificação há pelo menos **365 dias**. Antes de apagar, exige que a listagem e os metadados de tamanho e mtime não tenham mudado e, depois, confirma a ausência na listagem. Ficheiros de log recentes ou ativos, arquivos comprimidos, diretórios, links simbólicos, browsers, emails e backups permanecem excluídos. O resultado e a quota devem ser verificados nos logs protegidos; uma operação incerta não deve ser repetida sem nova auditoria.

## Relatório diário TXT privado e WhatsApp por incidente (01/11/2026)

A rotina existente de GitHub Actions (`main/.github/workflows/cpanel-disk-schedule.yml`) despacha o job `cpanel-auto-cleanup.yml` na branch protegida por volta das 03:17 UTC. Depois das fases de backup/cache, mesmo que alguma delas falhe, o passo final `deploy/cpanel_daily_disk_report.py` guarda um **TXT por dia** na conta `welcome`:

```
/home/welcome/room-check-private/whm-disk-AAAA-MM-DD.txt
```

Os relatórios são privados, fora de `public_html`, não são enviados diariamente e contêm apenas contagens, MB, percentagens, evolução em relação ao último TXT, indicadores anonimizados de novas pastas de raiz e resultados das fases de limpeza. Não ler ou copiar emails, dados de hóspedes, credenciais, conteúdo de logs ou dados de backups para o TXT. A gravação usa POST WHM Fileman e leitura de confirmação; falhas do upload não são ignoradas. A leitura não alcança processos Linux na conta de revendedor, assinalando essa limitação.

Incidentes que podem originar alerta: **primeiro aos 90%, depois aos 95%, 98% e 100% de ocupação** (sem alerta de quota aos 80%), aumento >=250 MB entre relatórios, crescimento de logs >=70 MB, reaparecimento de um browser Puppeteer Linux, falha no inventário ou em fases da manutenção. Quando a ocupação salta vários patamares entre relatórios, enviar apenas o nível mais elevado medido; nos dias seguintes avisar somente se ultrapassar novo nível. Se baixar de 98% para 95%, **não** repetir os 95%; a memória de patamar só reinicia quando a ocupação fica abaixo dos 90%. A categoria de incidentes técnicos continua a funcionar, independentemente dos níveis de quota. Pastas de raiz novas aparecem no TXT como contagem de identificadores não reversíveis, mas a mera criação de uma pasta não provoca WhatsApp.

O cron **já existente** `cron/whatsapp-reminders.php` consulta apenas o TXT privado recente e envia pelo `WhatsAppCloudClient` existente, para o Gerente ativo já selecionado em `invoice_notification_settings.recipient_user_id`, apenas quando há um **incidente diferente do último notificado**. O recibo de deduplicação fica em `room-check-private/whm-disk-whatsapp-state.json`, com permissões 0600; no caso de envio de resultado incerto não reenviar automaticamente. O desaparecimento do problema limpa o estado, permitindo um novo alerta no futuro. Não se enviam relatórios de rotina por WhatsApp.

A automação WhatsApp **permanece desativada** até o novo template ser aprovado pela Meta e habilitado no `config.local.php` privado. Não reutilizar templates de faturas, traduções ou atribuições, cujo texto não corresponde ao alerta de armazenamento.

**Template Meta proposto** (categoria *utility*, `pt_PT`, nome `whm_storage_alert_v1`, exatamente 3 parâmetros):

> Aviso automático do Management Hub\n>\n> Estado do servidor: {{1}}\n>\n> Problema identificado: {{2}}\n>\n> Ação recomendada: {{3}}\n>\n> O relatório detalhado está guardado na área privada do servidor.

Depois da aprovação, ativar apenas `whatsapp.disk_alert.enabled=true` no ficheiro privado `config.local.php`, mantendo nome `whm_storage_alert_v1` e o Gerente já configurado. A aceitação de um envio pela Meta não significa entrega confirmada ao telemóvel; testar com o destinatário autorizado e verificar o estado. Se o cron PHP não tiver recebido o código atualizado por bloqueio de deploy, **TXT funcional não implica WhatsApp funcional**.

Não criar um novo cron, webhook público, token ou destinatário. Não ampliar a limpeza automática de diretórios só por aparecerem no relatório. A deteção de novos diretórios é indicativa; é necessária prova de não utilização para os eliminar.


### Patamares aprovados pelo proprietário (01/11/2026)

- 90%, 95%, 98% e 100%, só uma mensagem por patamar durante o mesmo episódio de ocupação elevada. Um novo episódio começa após medição inferior a 90%; se houver subida direta de 89% para 98%, enviar um só aviso de 98%, não três.
- O relatório TXT diário continua a ser criado sempre; crescimento e falhas continuam monitorizados. As mensagens WhatsApp de quota só partem depois da aprovação Meta e ativação segura da configuração; não forçar um envio com template em revisão.
- A criação e submissão no WhatsApp Manager pertencem à conta profissional Active Lines Unip. Lda.; evitar o perfil pessoal/de teste. Não tratar a submissão como aprovação, nem ativar o envio antes de confirmar o estado e realizar um teste controlado.
