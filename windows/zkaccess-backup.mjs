// ZKAccess 5.3 Windows backup executor. Run only through Run-ZKBackup.ps1.
// No browser automation, external packages, credentials on command line or public listeners.
import { randomBytes, createHash } from 'node:crypto';
import { createReadStream, createWriteStream } from 'node:fs';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream/promises';
import { createGzip, createGunzip } from 'node:zlib';

const DATABASE = 'zkeco_db';
const PORT = '83306';
const CHUNK = 131072;
const LIMIT = 536870912;
const DUMP = 'C:\\Program Files (x86)\\ZKTeco\\ZKAccess\\mysql\\bin\\mysqldump.exe';
const MYSQL = 'C:\\Program Files (x86)\\ZKTeco\\ZKAccess\\mysql\\bin\\mysql.exe';
const FOLDER = path.join(process.env.LOCALAPPDATA ?? '', 'ManagementHub', 'ZKBackup', 'data');

export function lisbonParts(date = new Date()) {
  const items = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Lisbon', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
  }).formatToParts(date);
  const field = n => items.find(item => item.type === n)?.value;
  return { date: [field('year'), field('month'), field('day')].join('-'),
    minute: Number(field('hour')) * 60 + Number(field('minute')) };
}

export function dueAt(time, parts = lisbonParts()) {
  if (!/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(time)) throw Error('invalid_backup_time');
  const [hour, minute] = time.split(':').map(Number);
  return parts.minute >= hour * 60 + minute;
}

function assertConfig(value) {
  if (!value || value.endpoint !== 'https://check.welcomehostel.pt/zkaccess-backup-agent.php'
    || !/^[a-f0-9]{64}$/.test(value.token ?? '')) throw Error('invalid_agent_configuration');
  return value;
}

async function readConfig() {
  const chunks = [];
  for await (const part of process.stdin) {
    chunks.push(part);
    if (chunks.reduce((n, x) => n + x.length, 0) > 4096) throw Error('configuration_too_large');
  }
  return assertConfig(JSON.parse(Buffer.concat(chunks).toString('utf8')));
}

async function request(config, payload) {
  const response = await fetch(config.endpoint, {
    method: 'POST', redirect: 'manual',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + config.token },
    body: JSON.stringify(payload), signal: AbortSignal.timeout(90000)
  });
  if (response.status < 200 || response.status >= 300) {
    // Never print a server response; it may contain sensitive data.
    throw Error(response.status === 409 ? 'backup_conflict' : 'backup_transfer_failed');
  }
  const answer = await response.json();
  if (!answer || typeof answer !== 'object') throw Error('invalid_backup_response');
  return answer;
}

async function runProcess(file, args, input = undefined) {
  await new Promise((resolve, reject) => {
    const p = spawn(file, args, { windowsHide: true, stdio: [input ? 'pipe' : 'ignore', 'ignore', 'ignore'] });
    p.on('error', () => reject(Error('database_tool_unavailable')));
    p.on('exit', (code) => code === 0 ? resolve() : reject(Error('database_command_failed')));
    if (input) pipeline(input, p.stdin).catch(() => p.kill());
  });
}

async function loadState() {
  try {
    const obj = JSON.parse(await fs.readFile(path.join(FOLDER, 'backups.json'), 'utf8'));
    if (!Array.isArray(obj.backups) || obj.backups.length > 120) throw Error('invalid_backup_index');
    return obj.backups;
  } catch (error) {
    if (error.code === 'ENOENT') return [];
    throw error;
  }
}

async function saveState(backups) {
  const dest = path.join(FOLDER, 'backups.json');
  const tmp = path.join(FOLDER, 'backups-' + randomBytes(8).toString('hex') + '.tmp');
  await fs.writeFile(tmp, JSON.stringify({ backups }), { mode: 0o600, flag: 'wx' });
  await fs.rename(tmp, dest);
}

async function createBackup() {
  const id = randomBytes(16).toString('hex');
  const sql = path.join(FOLDER, 'zkeco_db_' + id + '.sql');
  const zipped = sql + '.gz';
  try {
    await runProcess(DUMP, ['--no-defaults', '--host=127.0.0.1', '--port=' + PORT, '--user=root',
      '--lock-all-tables', '--quick', '--hex-blob', '--routines', '--triggers',
      '--default-character-set=utf8', '--result-file=' + sql, DATABASE]);
    if ((await fs.stat(sql)).size < 4096) throw Error('backup_too_small');
    await pipeline(createReadStream(sql), createGzip({ level: 6 }), createWriteStream(zipped, { mode: 0o600, flags: 'wx' }));
    const stat = await fs.stat(zipped);
    if (stat.size < 100 || stat.size > LIMIT) throw Error('backup_size_invalid');
    const hash = createHash('sha256');
    for await (const bytes of createReadStream(zipped)) hash.update(bytes);
    return { id, date: lisbonParts().date, filename: path.basename(zipped),
      sha256: hash.digest('hex'), size: stat.size, status: 'pending' };
  } catch (err) {
    await fs.rm(zipped, { force: true }).catch(() => {});
    throw err;
  } finally {
    await fs.rm(sql, { force: true }).catch(() => {});
  }
}

async function uploadOne(config, record) {
  if (!/^[a-f0-9]{32}$/.test(record.id) || !/^zkeco_db_[a-f0-9]{32}\.sql\.gz$/.test(record.filename)) {
    throw Error('invalid_backup_index');
  }
  const source = path.join(FOLDER, record.filename);
  const stats = await fs.stat(source);
  if (stats.size !== record.size || stats.size > LIMIT) throw Error('backup_file_changed');
  const begin = await request(config, { action: 'begin', id: record.id,
    sha256: record.sha256, size: record.size });
  if (begin.status === 'uploaded' || begin.status === 'ready') {
    record.status = begin.status;
    return;
  }
  let offset = begin.received;
  if (!Number.isInteger(offset) || offset < 0 || offset > record.size) throw Error('invalid_backup_offset');
  const file = await fs.open(source, 'r');
  try {
    while (offset < record.size) {
      const length = Math.min(CHUNK, record.size - offset);
      const buffer = Buffer.allocUnsafe(length);
      const { bytesRead } = await file.read(buffer, 0, length, offset);
      if (bytesRead !== length) throw Error('backup_file_changed');
      const response = await request(config, { action: 'chunk', id: record.id,
        offset, data: buffer.toString('base64') });
      if (response.received !== offset + length) throw Error('backup_transfer_mismatch');
      offset += length;
    }
  } finally { await file.close(); }
  const done = await request(config, { action: 'finish', id: record.id });
  if (!done.verified) throw Error('backup_not_verified');
  record.status = 'ready';
}

async function cleanup(backups, remote) {
  const uploaded = new Set((remote.backups ?? []).filter(b => b.status === 'uploaded').map(b => b.id));
  for (const record of backups) {
    if (uploaded.has(record.id)) {
      record.status = 'uploaded';
      const age = (Date.now() - Date.parse(record.date + 'T00:00:00Z')) / 86400000;
      if (age >= 1) {
        await fs.rm(path.join(FOLDER, record.filename), { force: true });
        record.local_removed = true;
      }
    }
  }
}

async function restoreTest(file) {
  // Uses a disposable database. Never imports into zkeco_db.
  const id = randomBytes(6).toString('hex');
  const temporary = 'zkbackup_restore_' + id;
  const sql = path.join(FOLDER, 'restore-' + id + '.sql');
  try {
    await pipeline(createReadStream(file), createGunzip(), createWriteStream(sql, { mode: 0o600, flags: 'wx' }));
    await runProcess(MYSQL, ['--no-defaults', '--host=127.0.0.1', '--port=' + PORT,
      '--user=root', '-e', 'CREATE DATABASE ' + temporary]);
    await runProcess(MYSQL, ['--no-defaults', '--host=127.0.0.1', '--port=' + PORT,
      '--user=root', '--database=' + temporary], createReadStream(sql));
    const countFile = path.join(FOLDER, 'restore-test-count-' + id + '.txt');
    // Save read-only count to private data; never dump row contents.
    await new Promise((resolve, reject) => {
      const p = spawn(MYSQL, ['--no-defaults', '--host=127.0.0.1', '--port=' + PORT,
        '--user=root', '-N', '-e', `SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${temporary}'`],
        { windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'] });
      let result = '';
      p.stdout.on('data', x => { if (result.length < 32) result += x; });
      p.on('error', reject);
      p.on('exit', code => {
        if (code === 0 && Number(result.trim()) >= 100) resolve();
        else reject(Error('restore_table_count_mismatch'));
      });
    });
    return 'RESTORE_TEST_OK';
  } finally {
    await runProcess(MYSQL, ['--no-defaults', '--host=127.0.0.1', '--port=' + PORT,
      '--user=root', '-e', 'DROP DATABASE IF EXISTS ' + temporary]).catch(() => {});
    await fs.rm(sql, { force: true }).catch(() => {});
  }
}

async function main() {
  const config = await readConfig();
  await fs.mkdir(FOLDER, { recursive: true, mode: 0o700 });
  let backups = await loadState();
  if (process.argv.includes('--restore-test')) {
    const candidate = backups.findLast(b => !b.local_removed);
    if (!candidate) throw Error('no_local_backup_for_restore');
    console.log(await restoreTest(path.join(FOLDER, candidate.filename)));
    return;
  }
  const remoteSettings = await request(config, { action: 'config' });
  const settings = remoteSettings.settings;
  if (!settings || settings.timezone !== 'Europe/Lisbon') throw Error('backup_invalid_schedule');
  const remoteStatus = await request(config, { action: 'status' });
  await cleanup(backups, remoteStatus);
  await saveState(backups);
  if (!settings.enabled) { console.log('BACKUP_DISABLED'); return; }
  const now = lisbonParts();
  const alreadyToday = backups.some(b => b.date === now.date);
  if (!alreadyToday && dueAt(settings.time, now)) {
    const current = await createBackup();
    backups.push(current);
    await saveState(backups);
    console.log('MYSQL_BACKUP_CREATED');
  }
  for (const record of backups.filter(b => b.status !== 'uploaded' && !b.local_removed)) {
    await uploadOne(config, record);
    await saveState(backups);
  }
  console.log('BACKUP_AGENT_OK');
}

main().catch(() => { console.error('BACKUP_AGENT_FAILED'); process.exitCode = 1; });
