<?php
declare(strict_types=1);
// Reuse the baseline suite's sanitized SQLite schema, then run transport lifecycle cases.
require __DIR__.'/invoices.php';
$pdo->exec('DROP TABLE invoice_auth_challenges');
$pdo->exec("CREATE TABLE invoice_auth_challenges (id TEXT PRIMARY KEY,account_id INTEGER,task_id INTEGER,method TEXT,state TEXT DEFAULT 'waiting',created_at TEXT,expires_at TEXT,consumed_at TEXT,event_hash TEXT UNIQUE)");
require __DIR__.'/invoice-agent-cases.php';
runInvoiceAgentCases($pdo);
