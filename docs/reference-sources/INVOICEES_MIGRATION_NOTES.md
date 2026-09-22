# Invoicees — proposta visual recuperada

Recuperada em 22 de setembro de 2026 a partir do anexo `Invoicees(1).html` fornecido pelo proprietário. A própria proposta está datada de 20 de setembro de 2026.

## Proveniência e alterações

- Original privado: 130132 bytes; SHA256 `613499b7f4018ebf8b8ce71074fb5768488b40b19c5b7f324d4aa2b2bbc8552c`.
- Cópia de desenvolvimento: `Invoicees.html`, 130138 bytes; SHA256 `be09588a7a65353e74a698058cefa8408f72680cb909aa457b9bd4682ff217b8`.
- Únicas substituições: duas ocorrências do email operacional por `gerente@example.invalid` e duas do nome do proprietário por `Gerente de demonstração`.
- Restante conteúdo, estilos, scripts, simulação e decisões preservados byte a byte. Não houve redesenho nem implementação desta proposta no Hub.
- Os dados de faturas, contas, estados e credenciais apresentados são de demonstração. Não introduzir credenciais reais.

## Conteúdo para continuar

A proposta guarda cinco áreas: **Resumo**, **Faturas**, **Portais e contas**, **Atividade** e **Definições**. Ler o cabeçalho do HTML e a simulação embutida antes de implementar.

- Preservar filtros de período, alojamento, portal e conta; distinguir acesso, agendamento, serviço e resultado da recolha.
- Guardar credenciais pausa o agendamento e exige novo teste de acesso.
- Recolha manual e programada por conta; falhas de uma conta não interrompem as restantes. Distinguir consulta sem documentos de consulta ainda não executada.
- Drive: cópia privada até confirmação do upload; tentativa inicial mais oito repetições a cada três horas e aviso final ao gerente. A conta real permanece no contexto privado.
- WhatsApp: plataforma, conta, período, problema e intervenção necessária; confirmar template `invoice_collection_failed_v1` antes de ativar.
- Airbnb: preservar CSV, tratar separadamente, gerar PDF e depois encaminhar para TOConline; assinalar configuração pendente.
- A regra proposta de titularidade identifica Active Lines. Confirmar validação de negócio na implementação.
- SMS/email/TOTP são requisitos propostos de 2FA, não prova de suporte ou funcionamento de cada portal. CAPTCHA permanece por analisar.
- Filtros e gravações conservam contexto; voltar recupera filtros e posição. Confirmações desaparecem ao fim de dois segundos; erros continuam visíveis.

## Limites técnicos

- O HTML é uma simulação histórica, não uma ligação aos portais nem um executor de faturas.
- Os estados de sucesso e documentos visíveis são exemplos, não provas operacionais.
- O exemplo usa relógio fixo e cálculos UTC com a etiqueta Lisboa. Preservados como fonte histórica; na implementação usar `Europe/Lisbon` com mudança de hora correta.
- Algumas bibliotecas visuais são carregadas do CDN unpkg. O ficheiro principal é autocontido quanto à proposta, mas ícones/comportamentos auxiliares podem depender da rede.
- O texto antigo sobre alterações locais parciais é uma nota da proposta: verificar o código atual antes de concluir que essas alterações ainda existem ou foram integradas.
- Não publicar `docs/` em `public_html`. A importação não autoriza deploy, instalação Windows ou ativação das automações.
