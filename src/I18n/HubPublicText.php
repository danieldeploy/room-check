<?php
declare(strict_types=1);

/** Public, bilingual information for the application's Google OAuth branding. */
return [
  'homeTitle' => [
    "Centro de Gestão — Management Hub",
    "Management Hub"
  ],
  'privacyTitle' => [
    "Política de privacidade — Google Drive",
    "Privacy policy — Google Drive"
  ],
  'termsTitle' => [
    "Condições de utilização",
    "Terms of use"
  ],
  'home' => [
    "Apresentação",
    "About"
  ],
  'privacy' => [
    "Privacidade",
    "Privacy"
  ],
  'terms' => [
    "Condições de utilização",
    "Terms of use"
  ],
  'login' => [
    "Entrar no Centro de Gestão",
    "Sign in to Management Hub"
  ],
  'intro' => [
    "O Management Hub é uma aplicação de gestão da Active Lines Unip. Lda. para apoiar as operações dos seus alojamentos. O acesso às funções de trabalho está reservado aos utilizadores autorizados pela empresa.",
    "Management Hub is an application operated by Active Lines Unip. Lda. to support its accommodation operations. Access to operational features is restricted to users authorized by the company."
  ],
  'features' => [
    "A aplicação reúne a gestão de espaços e tarefas, a recolha de faturas dos portais de reservas, o arquivo de documentos e o acompanhamento de falhas.",
    "The application brings together space and task management, invoice collection from booking portals, document archiving and failure monitoring."
  ],
  'drive' => [
    "A integração com o Google Drive permite ao gerente autorizar o arquivo das faturas na sua conta. A aplicação reutiliza a pasta de contabilidade escolhida, cria os caminhos de ano e mês que faltarem e verifica os ficheiros enviados.",
    "The Google Drive integration lets the manager authorize invoice archiving in their account. The application reuses the selected accounting folder, creates missing year and month paths, and verifies uploaded files."
  ],
  'contactTitle' => [
    "Responsável e contacto",
    "Operator and contact"
  ],
  'contact' => [
    "A Active Lines Unip. Lda. é responsável por esta aplicação. Para apoio ou pedidos relativos aos dados desta integração, contacte:",
    "Active Lines Unip. Lda. operates this application. For support or requests concerning data used by this integration, contact:"
  ],
  'scopeTitle' => [
    "Dados acedidos e finalidade",
    "Data accessed and purpose"
  ],
  'scope' => [
    "Com autorização da conta, a integração consulta informações dos ficheiros e pastas do Google Drive, incluindo nomes, identificadores, estrutura, proprietário, tamanho e checksum. Usa estas informações para identificar a conta e a pasta autorizadas, reutilizar o arquivo existente e verificar os documentos.",
    "With account authorization, the integration reads Google Drive file and folder information, including names, identifiers, structure, owner, size and checksum. It uses this information to identify the authorized account and folder, reuse the existing archive and verify documents."
  ],
  'write' => [
    "A permissão de escrita abrange os ficheiros específicos utilizados com esta aplicação e permite vê-los, criá-los, editá-los e eliminá-los. O fluxo de arquivo envia faturas e cria as pastas necessárias; não solicita acesso geral ao conteúdo de todos os ficheiros do Drive.",
    "Write permission covers the specific files used with this application and permits viewing, creating, editing and deleting them. The archive workflow uploads invoices and creates required folders; it does not request general access to the contents of every Drive file."
  ],
  'storageTitle' => [
    "Armazenamento e conservação",
    "Storage and retention"
  ],
  'storage' => [
    "As credenciais OAuth são guardadas cifradas num cofre privado do servidor. Os ficheiros recolhidos ficam temporariamente em armazenamento privado e são enviados para a pasta Drive selecionada. A cópia de trabalho local só é eliminada depois de o envio ser verificado; em caso de falha, fica disponível para novas tentativas.",
    "OAuth credentials are stored encrypted in a private server vault. Collected files are held temporarily in private storage and uploaded to the selected Drive folder. The local working copy is removed only after upload verification; on failure, it remains available for retries."
  ],
  'retention' => [
    "Os registos de processamento permanecem no Hub para acompanhamento e auditoria. Os documentos no Drive permanecem até serem eliminados pelo responsável. As cópias de segurança privadas do servidor podem conservar dados anteriores; a eliminação de uma cópia de trabalho não elimina automaticamente os backups.",
    "Processing records remain in the Hub for monitoring and auditing. Drive documents remain until removed by the responsible operator. Private server backups may retain earlier data; deleting a working copy does not automatically delete backups."
  ],
  'sharingTitle' => [
    "Utilização e partilha",
    "Use and sharing"
  ],
  'sharing' => [
    "Os dados são usados para as funções de arquivo e gestão solicitadas pela empresa. O alojamento técnico processa os dados do Hub e o Google processa os ficheiros enviados para o Drive. Os utilizadores autorizados do Hub podem consultar os registos conforme as suas permissões; o acesso direto ao Drive segue as permissões da conta e das pastas.",
    "Data is used for the archiving and management functions requested by the company. The hosting service processes Hub data and Google processes files uploaded to Drive. Authorized Hub users can view records according to their permissions; direct Drive access follows account and folder permissions."
  ],
  'alerts' => [
    "Quando ativados, os avisos por WhatsApp enviam ao gerente informação operacional sobre falhas. Não enviam palavras-passe, tokens OAuth nem o conteúdo das faturas.",
    "When enabled, WhatsApp alerts send operational failure information to the manager. They do not send passwords, OAuth tokens or invoice contents."
  ],
  'limited' => [
    "Os dados obtidos das APIs Google não são vendidos, usados para publicidade nem para treinar modelos de inteligência artificial. A utilização e transferência desses dados respeitam a Google API Services User Data Policy, incluindo os requisitos de Limited Use.",
    "Data obtained from Google APIs is not sold or used for advertising or to train artificial intelligence models. Use and transfer of that data adhere to the Google API Services User Data Policy, including its Limited Use requirements."
  ],
  'revokeTitle' => [
    "Revogar o acesso e pedir eliminação",
    "Revoke access and request deletion"
  ],
  'revoke' => [
    "Pode revogar o acesso da aplicação nas ligações da sua Conta Google. Isso impede novos acessos autorizados pela ligação revogada, mas não elimina os documentos já arquivados. Para pedir a remoção das credenciais guardadas ou esclarecer a conservação de registos e backups, contacte o responsável indicado nesta página.",
    "You can revoke application access in your Google Account connections. This prevents new access through the revoked connection but does not delete documents already archived. To request removal of stored credentials or clarify retention of records and backups, contact the operator listed on this page."
  ],
  'connections' => [
    "Gerir ligações na Conta Google",
    "Manage Google Account connections"
  ],
  'use' => [
    "Esta aplicação destina-se ao trabalho autorizado da Active Lines Unip. Lda. Cada utilizador deve utilizar apenas os módulos e dados permitidos pelo seu perfil e proteger os seus meios de autenticação.",
    "This application is intended for authorized work at Active Lines Unip. Lda. Each user must use only modules and data permitted by their role and protect their authentication credentials."
  ],
  'accuracy' => [
    "O gerente deve verificar os documentos e o estado do arquivo antes de os utilizar para fins contabilísticos. A aplicação apresenta falhas e estados pendentes; uma recolha ou um envio pendente não equivale a um documento validado.",
    "The manager should verify documents and archive status before using them for accounting. The application displays failures and pending states; pending collection or upload does not mean a document has been validated."
  ],
  'providers' => [
    "As ligações a serviços externos dependem das respetivas permissões e disponibilidade. A autorização do Google Drive pode ser revogada pela conta. A utilização desta integração está descrita na política de privacidade.",
    "Connections to external services depend on their permissions and availability. Google Drive authorization can be revoked by the account. Use of this integration is described in the privacy policy."
  ],
  'updated' => [
    "Atualizado em 3 de outubro de 2026.",
    "Updated on 3 October 2026."
  ]
];
