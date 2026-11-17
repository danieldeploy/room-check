<?php
declare(strict_types=1);
require_once __DIR__.'/InvoiceText.php';

final class InvoiceTocReplyText
{
    public const REASONS = [
        'toc_reply_attachment_unclear' => ['O Hub identificou a resposta do TOConline, mas não conseguiu determinar se esta fatura foi aceite, se já existia ou se foi recusada.', 'The Hub identified the TOConline reply but could not determine whether this invoice was accepted, already on file or rejected.'],
        'toc_reply_multiple_scans' => ['A referência da fatura na resposta corresponde a várias digitalizações enviadas.', 'The invoice reference in the reply matches several submitted scans.'],
        'toc_reply_authentication' => ['O Hub não conseguiu verificar a assinatura da resposta do TOConline.', 'The Hub could not verify the signature on the TOConline reply.'],
        'toc_reply_recipient' => ['O destinatário da resposta assinada difere do endereço usado para enviar a fatura.', 'The signed reply was addressed to a different email address from the invoice sender.'],
        'toc_reply_correlation' => ['A resposta contém várias referências e o Hub não conseguiu associar o resultado a uma única fatura.', 'The reply contains several references and the Hub could not link the result to one invoice.'],
        'toc_reply_timestamp_missing' => ['Falta uma data válida de assinatura ou de envio para confirmar a resposta.', 'A valid signature time or submission time is missing.'],
        'toc_reply_predates_send' => ['A data da assinatura da resposta é anterior ao envio da fatura, fora da tolerância de cinco minutos.', 'The reply was signed before the invoice was sent, outside the five-minute clock tolerance.'],
        'toc_reply_detail_unavailable' => ['O histórico guardou a resposta para revisão sem registar o motivo técnico.', 'The historical record marked the reply for review without recording the technical reason.'],
    ];

    public static function get(string $code): string
    {
        if (isset(InvoiceText::TEXT[$code])) return InvoiceText::get($code);
        [$pt,$en]=self::REASONS[$code]??self::REASONS['toc_reply_detail_unavailable'];
        return Translator::localized($pt,$en);
    }
}
