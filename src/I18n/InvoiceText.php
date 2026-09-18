<?php
declare(strict_types=1);
require_once __DIR__ . '/Translator.php';

final class InvoiceText
{
    public const TEXT = [
        'title' => ['Faturas e Portais', 'Invoices and Portals'],
        'description' => ['Faturas do Booking, histórico e recolha automática.', 'Booking invoices, history and scheduled collection.'],
        'eyebrow' => ['Documentos dos portais', 'Portal documents'],
        'view_permission' => ['Consultar faturas', 'View invoices'],
        'run_permission' => ['Recolher faturas', 'Collect invoices'],
        'open' => ['Abrir módulo', 'Open module'],
        'period' => ['Mês de emissão', 'Issue month'],
        'property' => ['Alojamento', 'Property'],
        'all' => ['Ambos os alojamentos', 'Both properties'],
        'filter' => ['Filtrar', 'Filter'],
        'collect' => ['Recolher faturas', 'Collect invoices'],
        'background' => ['Pode sair deste ecrã. A recolha continua no servidor; regresse ou atualize para consultar o estado.', 'You can leave this page. Collection continues on the server; return or refresh to check progress.'],
        'refresh' => ['Atualizar estado', 'Refresh status'],
        'invoices' => ['Faturas recolhidas', 'Collected invoices'],
        'empty' => ['Ainda não há faturas para estes filtros.', 'There are no invoices for these filters yet.'],
        'date' => ['Data de emissão', 'Issue date'],
        'number' => ['Número', 'Number'],
        'download' => ['Descarregar PDF', 'Download PDF'],
        'older' => ['Faturas anteriores', 'Older invoices'],
        'history' => ['Últimas 20 execuções', 'Last 20 runs'],
        'state' => ['Estado', 'Status'],
        'created' => ['Pedido em', 'Requested at'],
        'counts' => ['Novas / repetidas', 'New / duplicate'],
        'queued' => ['Em fila', 'Queued'],
        'running' => ['Em execução', 'Running'],
        'completed' => ['Concluída', 'Completed'],
        'failed' => ['Falhou', 'Failed'],
        'needs_auth' => ['É necessária autenticação no Booking. A recolha automática foi desativada.', 'Booking authentication is required. Scheduled collection has been disabled.'],
        'worker_busy' => ['Existe uma recolha em curso. Aguarde antes de substituir as credenciais.', 'A collection is running. Wait before replacing credentials.'],
        'login_required' => ['Teste primeiro o acesso ao Booking.', 'Test Booking access first.'],
        'settings' => ['Configuração — Gerência', 'Settings — Management'],
        'preflight' => ['Testar browser no servidor', 'Test server browser'],
        'login' => ['Testar acesso ao Booking', 'Test Booking access'],
        'identifier' => ['Utilizador Booking', 'Booking username'],
        'password' => ['Palavra-passe Booking', 'Booking password'],
        'save_credentials' => ['Guardar credenciais cifradas', 'Save encrypted credentials'],
        'configured' => ['Credenciais guardadas', 'Credentials saved'],
        'not_configured' => ['Credenciais por configurar', 'Credentials not configured'],
        'schedule' => ['Recolha mensal automática', 'Automatic monthly collection'],
        'schedule_note' => ['Recolhe as faturas emitidas no mês anterior. Horário de Lisboa.', 'Collects invoices issued in the previous month. Lisbon time.'],
        'day' => ['Dia do mês (1–28)', 'Day of month (1–28)'],
        'time' => ['Hora de Lisboa', 'Lisbon time'],
        'save' => ['Guardar configuração', 'Save settings'],
        'saved' => ['Configuração guardada.', 'Settings saved.'],
        'requested' => ['Pedido registado. O servidor executará a tarefa.', 'Request queued. The server will run the task.'],
        'ready' => ['Browser validado nas últimas 24 horas.', 'Browser validated within the last 24 hours.'],
        'preflight_required' => ['Execute primeiro o teste do browser no servidor.', 'Run the server browser test first.'],
        'worker_stale' => ['O serviço não comunicou nos últimos 5 minutos. Verifique a instalação e o cron.', 'The service has not reported within the last 5 minutes. Check installation and cron.'],
        'migration_required' => ['É necessário instalar a migração 026 e o serviço privado.', 'Migration 026 and the private service must be installed.'],
        'private_storage_unavailable' => ['O armazenamento privado ainda não está preparado.', 'Private storage is not ready.'],
        'private_storage_permissions' => ['As permissões do armazenamento privado precisam de correção.', 'Private storage permissions need correction.'],
        'vault_key_unavailable' => ['A chave do cofre não está disponível.', 'The vault key is unavailable.'],
        'vault_read_failed' => ['Não foi possível abrir o cofre. Verifique a configuração privada.', 'Could not open the vault. Check private configuration.'],
        'vault_write_failed' => ['Não foi possível guardar no cofre.', 'Could not save to the vault.'],
        'worker_unavailable' => ['O serviço de recolha ainda não está disponível.', 'The collection service is not available yet.'],
        'worker_timeout' => ['A execução excedeu o tempo permitido.', 'The run exceeded its time limit.'],
        'worker_failed' => ['A execução falhou. Consulte a configuração e tente novamente.', 'The run failed. Check configuration and try again.'],
        'browser_unavailable' => ['O browser não conseguiu concluir o teste ou a ligação ao portal.', 'The browser could not complete the test or connect to the portal.'],
        'connector_unconfigured' => ['A configuração do conector Booking precisa de validação no servidor.', 'The Booking connector configuration needs server validation.'],
        'portal_changed' => ['Não foi possível confirmar os dados do portal. É necessária revisão do conector.', 'Portal data could not be verified. The connector needs review.'],
        'invalid_document' => ['Foi recusado um documento inválido ou de outro período.', 'An invalid document or one from another period was rejected.'],
        'invoice_conflict' => ['Já existe este número de fatura com conteúdo diferente. Requer revisão.', 'This invoice number already exists with different content. Review is required.'],
        'no_invoices' => ['Sem faturas emitidas no período selecionado.', 'No invoices issued in the selected period.'],
        'ok' => ['Concluído com sucesso.', 'Completed successfully.'],
        'interrupted' => ['A execução foi interrompida. Pode repetir a recolha.', 'The run was interrupted. You can repeat the collection.'],
        'invalid_request' => ['Pedido inválido.', 'Invalid request.'],
        'invalid_schedule' => ['Indique um dia entre 1 e 28 e uma hora válida.', 'Enter a day from 1 to 28 and a valid time.'],
        'forbidden' => ['Não tem permissão para esta ação.', 'You do not have permission to perform this action.'],
        'https_required' => ['Use uma ligação HTTPS para guardar credenciais.', 'Use HTTPS to save credentials.'],
        'document_limit' => ['A recolha atingiu o limite de documentos ou páginas. Requer revisão.', 'Collection reached its document or page limit. Review is required.'],
        'network_error' => ['Falha de ligação ao portal.', 'Could not connect to the portal.'],
        'scope' => ['Booking: Welcome Guest House e City Center Guest House.', 'Booking: Welcome Guest House and City Center Guest House.'],
    ];

    public static function get(string $key): string
    {
        $pair = self::TEXT[$key] ?? self::TEXT['worker_failed'];
        return Translator::localized($pair[0], $pair[1]);
    }

    public static function catalog(): array
    {
        return array_column(array_values(self::TEXT), 1, 0);
    }
}
