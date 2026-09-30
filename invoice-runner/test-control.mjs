import { AgentClient } from './agent-client.mjs';

// Called by the local DPAPI launcher. Neither arguments nor stdout contain a key.
try {
  let raw = '';
  for await (const chunk of process.stdin) { raw += chunk; if (raw.length > 16384) throw new Error(); }
  const config = JSON.parse(raw.replace(/^\uFEFF/, '')); raw = '';
  if (!config.request || !['control_status','control_pause','control_resume','control_revoke',
    'control_login','control_collect','control_enable_captcha','control_enable_alerts'].includes(config.request.action)) throw new Error();
  const result = await new AgentClient(config.endpoint, config.token).request(config.request);
  config.token = undefined;
  process.stdout.write(JSON.stringify(result) + '\n');
} catch (error) {
  const code = ['forbidden','invalid_request','worker_busy','auth_invalid'].includes(error.message) ? error.message : 'unavailable';
  process.stdout.write(JSON.stringify({ error: code }) + '\n'); process.exitCode = 1;
}
