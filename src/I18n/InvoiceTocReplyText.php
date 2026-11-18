<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceText.php';

final class InvoiceTocReplyText
{
    public const REASONS = [
        'toc_reply_unassigned_refusal' => ['O TOConline recusou um ficheiro, mas a resposta não identifica qual dos documentos foi recusado.', 'TOConline refused a file. The reply does not identify which document it refused.'],
        'toc_reply_batch_incomplete' => ['A resposta do TOConline só confirma parte das faturas deste fornecedor. Falta identificar as restantes.', 'TOConline confirmed only some of this supplier’s invoices. The remaining ones need to be identified.'],
        'toc_reply_batch_conflict' => ['As referências ou contagens da resposta do TOConline são incompatíveis com as faturas enviadas.', 'The TOConline references or counts conflict with the invoices submitted.'],
        'toc_reply_batch_ambiguous' => ['A resposta do TOConline pode corresponder a mais de um envio.', 'The TOConline reply could refer to more than one submission.'],
        'toc_reply_attachment_unclear' => ['O Hub identificou a resposta do TOConline, mas não conseguiu determinar se esta fatura foi aceite, se já existia ou se foi recusada.', 'The Hub identified the TOConline reply but could not determine whether this invoice was accepted, already on file or rejected.'],
        'toc_reply_multiple_scans' => ['A referência da fatura na resposta corresponde a várias digitalizações enviadas.', 'The invoice reference in the reply matches several submitted scans.'],
        'toc_reply_authentication' => ['O Hub não conseguiu verificar a assinatura da resposta do TOConline.', 'The Hub could not verify the signature on the TOConline reply.'],
        'toc_reply_recipient' => ['O destinatário da resposta assinada difere do endereço usado para enviar a fatura.', 'The signed reply was addressed to a different email address from the invoice sender.'],
        'toc_reply_correlation' => ['A resposta contém várias referências e o Hub não conseguiu associar o resultado a uma única fatura.', 'The reply contains several references and the Hub could not link the result to one invoice.'],
        'toc_reply_timestamp_missing' => ['Falta uma data válida de assinatura ou de envio para confirmar a resposta.', 'A valid signature time or submission time is missing.'],
        'toc_reply_predates_send' => ['A data da assinatura da resposta é anterior ao envio da fatura, fora da tolerância de cinco minutos.', 'The reply was signed before the invoice was sent, outside the five-minute clock tolerance.'],
        'toc_reply_detail_unavailable' => ['O histórico guardou a resposta para revisão sem registar o motivo técnico.', 'The historical record marked the reply for review without recording the technical reason.'],
    ];

    /** The receipt pages use these pairs before the shared invoice catalogue. */
    private const BATCH = [
        'toc_batch_title' => ['Envio para o TOConline de {date}', 'TOConline submission on {date}'],
        'toc_batch_blocked' => ['Bloqueado — é preciso conferir as faturas no TOConline.', 'Blocked — check these invoices in TOConline.'],
        'toc_batch_reply_counts' => ['{total} faturas enviadas. Resposta do TOConline: {accepted} aceites, {rejected} recusadas, {missing} sem resultado final.', '{total} invoices sent. TOConline reply: {accepted} accepted, {rejected} refused, {missing} without a final result.'],
        'toc_batch_status_counts' => ['Faturas identificadas no arquivo: {found} de {total}.', 'Invoices identified in the archive: {found} of {total}.'],
        'toc_batch_unconfirmed' => ['Presença no TOConline por confirmar', 'Presence in TOConline unconfirmed'],
        'toc_batch_compare' => ['Consulte o arquivo TOConline e confira a fatura pelo fornecedor, número, data e valor.', 'Check the invoice in the TOConline archive by supplier, number, date and amount.'],
        'toc_batch_fiscal_counts' => ['O TOConline confirmou {confirmed} de {total} faturas deste fornecedor.', 'TOConline confirmed {confirmed} of this supplier’s {total} invoices.'],
        'toc_batch_individual' => ['Confirmação individual', 'Individually confirmed'],
        'toc_batch_group' => ['Confirmação pelo conjunto do fornecedor', 'Confirmed from the supplier total'],
        'toc_batch_group_named' => ['Arquivada — confirmada pelo lote de {total} faturas de {supplier}.', 'Archived — confirmed from the batch of {total} invoices from {supplier}.'],
        'toc_batch_others' => ['Ao reenviar a selecionada, confirmar também que encontrei todas as outras faturas deste envio no TOConline.', 'When resending the selected invoice, also confirm that I found every other invoice from this submission in TOConline.'],
        'toc_batch_manual' => ['Confirmação manual', 'Manually confirmed'],
        'toc_batch_check' => ['Abra os documentos abaixo e confira-os no arquivo TOConline pelo fornecedor, número, data e valor.', 'Open the documents below and check their supplier, number, date and amount in the TOConline archive.'],
        'toc_batch_choose' => ['Selecione apenas a fatura que identificou como recusada ou em falta.', 'Select only the invoice you identified as refused or missing.'],
        'toc_batch_choose_all' => ['Se confirmou que todas estão no arquivo, deixe a seleção vazia.', 'If you found every invoice in the archive, leave the selection empty.'],
        'toc_batch_verify' => ['Conferi os documentos no arquivo TOConline e confirmo o resultado da ação escolhida.', 'I checked the documents in the TOConline archive and confirm the outcome of the action I choose.'],
        'toc_batch_assign_duplicate' => ['Identificar a fatura como já existente', 'Identify the invoice already on file'],
        'toc_batch_assign_refused' => ['Identificar a fatura recusada', 'Identify the refused invoice'],
        'toc_batch_confirm_all' => ['Confirmar que todas constam no TOConline', 'Confirm all invoices are in TOConline'],
        'toc_batch_resend' => ['Reenviar só a fatura selecionada em falta', 'Resend only the selected missing invoice'],
        'toc_batch_select' => ['Selecionar {invoice}', 'Select {invoice}'],
        'toc_batch_selection' => ['Seleção', 'Selection'],
        'toc_batch_amount' => ['Valor', 'Amount'],
        'toc_batch_refusal_unknown' => ['O TOConline recusou a fatura. O motivo da recusa não está identificado.', 'TOConline refused the invoice. The reason for the refusal is unknown.'],
        'toc_batch_refusal_unassigned' => ['O TOConline comunicou uma recusa, mas a resposta não identifica a fatura.', 'TOConline reported one refusal. The reply does not identify the invoice.'],
        'toc_batch_duplicate_unassigned' => ['O TOConline comunicou que uma fatura já existia, mas a resposta não identifica qual.', 'TOConline reported that one invoice was already on file. The reply does not identify which one.'],
        'toc_batch_buyer_recorded' => ['No PDF, o Hub leu o NIF de comprador {buyer}. A empresa configurada tem o NIF {expected}.', 'The Hub read buyer tax number {buyer} on the PDF. The configured company tax number is {expected}.'],
        'toc_batch_buyer_missing' => ['O Hub não identificou o NIF do comprador no documento.', 'The Hub could not identify the buyer tax number on the document.'],
        'toc_batch_buyer_consumer' => ['O comprador identificado no documento é consumidor final.', 'The buyer identified on the document is a final consumer.'],
        'toc_batch_read_failed' => ['Não foi possível consultar os envios do TOConline. Atualize a página para voltar a verificar.', 'The TOConline submissions could not be read. Refresh the page to check again.'],
        'toc_reply_check_pending' => ['Ainda não foi possível verificar se chegou uma confirmação tardia. Aguarde pela consulta automática e volte a conferir o estado da fatura.', 'A final check for late confirmations is still pending. Wait for the automatic check and then review the invoice status again.'],
        'toc_batch_view' => ['Abrir {file}', 'Open {file}'],
        'toc_batch_changed' => ['O estado do envio mudou. Confira a lista atual antes de voltar a escolher uma ação.', 'The submission status changed. Check the updated list before choosing an action again.'],
        'toc_batch_check_required' => ['Confira o arquivo TOConline e confirme a ação. Para identificar ou reenviar uma fatura, selecione apenas a linha correspondente.', 'Check the TOConline archive and confirm the action. To identify or resend an invoice, select only its row.'],
        'receipt_retained_replies' => ['Releitura das respostas guardadas do TOConline','Retained TOConline reply check'],
        'receipt_finished_pending' => ['Finalizado com pendências','Completed with unresolved items'],
        'toc_provider_wrong_recipient' => ['O TOConline informou que este documento não pertence à empresa destinatária. Confira o comprador no PDF e a empresa no TOConline.', 'TOConline reported that this document does not belong to the recipient company. Check the buyer on the PDF and the company in TOConline.'],
        'toc_provider_preparing' => ['O TOConline indicou que uma fatura deste fornecedor ainda está em preparação. Aguarde a referência definitiva e confira-a no arquivo.', 'TOConline reported that an invoice from this supplier is still in preparation. Wait for the final reference and check it in the archive.'],
        'toc_preparation_expired' => ['O TOConline indicou que uma fatura deste fornecedor estava em preparação. O prazo terminou sem referência definitiva. Confira as faturas no arquivo TOConline.', 'TOConline reported that an invoice from this supplier was in preparation. The deadline passed without a final reference. Check the invoices in the TOConline archive.'],
    ];

    public const TEXT = self::BATCH + self::REASONS + InvoiceText::TEXT;

    public static function label(string $code): string
    {
        [$pt,$en]=self::TEXT[$code]??InvoiceText::TEXT['worker_failed'];
        return Translator::localized($pt,$en);
    }

    public static function get(string $code): string
    {
        return self::label(isset(self::TEXT[$code])?$code:'toc_reply_detail_unavailable');
    }
}
