import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

export async function assertPrivateDirectory(directory) {
  if (typeof directory !== 'string' || !path.isAbsolute(directory)
      || directory.split(/[\\/]/).some(p => ['public_html', '..', '.'].includes(p.toLowerCase()))) throw new Error('private_storage_unavailable');
  const root = await fs.realpath(directory);
  if (root.split(/[\\/]/).some(p => p.toLowerCase() === 'public_html')) throw new Error('private_storage_unavailable');
  const stat = await fs.stat(root);
  if (!stat.isDirectory()) throw new Error('private_storage_unavailable');
  if (process.platform === 'win32') {
    const script = fileURLToPath(new URL('./windows/Test-PrivateDirectory.ps1', import.meta.url));
    await promisify(execFile)('powershell.exe', ['-NoProfile', '-NonInteractive', '-File', script, '-Directory', root],
      { windowsHide: true, timeout: 15000, maxBuffer: 4096 });
  } else if (stat.mode & 0o077) throw new Error('private_storage_permissions');
  return root;
}
