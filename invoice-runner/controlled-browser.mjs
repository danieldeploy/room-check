import fs from 'node:fs/promises';
import path from 'node:path';
import { assertPrivateDirectory } from './private-storage.mjs';

// Chrome writes this file inside its dedicated, ACL-restricted profile.
// Never accept an endpoint, host or profile path from a portal or a Hub job.
export async function controlledBrowserEndpoint(root) {
  const profile = path.join(root, 'controlled-booking-chrome');
  if (path.dirname(await fs.realpath(profile)) !== root) throw new Error('controlled_profile_invalid');
  await assertPrivateDirectory(profile);
  const data = await fs.readFile(path.join(profile, 'DevToolsActivePort'), 'utf8');
  if (data.length > 256) throw new Error('controlled_endpoint_invalid');
  const [port, endpoint] = data.trim().split(/\r?\n/);
  if (!/^[1-9]\d{0,4}$/.test(port) || Number(port) > 65535
      || !/^\/devtools\/browser\/[a-zA-Z0-9-]{8,80}$/.test(endpoint))
    throw new Error('controlled_endpoint_invalid');
  return 'ws://127.0.0.1:' + port + endpoint;
}
