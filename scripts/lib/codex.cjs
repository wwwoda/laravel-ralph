'use strict';

const { spawn } = require('node:child_process');
const readline = require('node:readline');

// Only return requested metadata. Account credentials and raw server output never leave this helper.
function request(method, params = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn('codex', ['app-server', '--stdio'], { stdio: ['pipe', 'pipe', 'pipe'] });
    let settled = false;
    const finish = (error, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      child.kill();
      error ? reject(error) : resolve(value);
    };
    const timer = setTimeout(() => finish(new Error('Codex metadata request timed out')), 20000);
    const send = value => child.stdin.write(JSON.stringify(value) + '\n');
    child.stdin.on('error', () => {});
    child.stderr.resume();
    child.on('error', () => finish(new Error('Cannot start Codex app server')));
    child.on('exit', () => finish(new Error('Codex metadata request failed')));
    readline.createInterface({ input: child.stdout }).on('line', line => {
      let message;
      try { message = JSON.parse(line); } catch { return; }
      if (message.id === 1) {
        if (message.error) return finish(new Error('Codex initialization failed'));
        send({ method: 'initialized', params: {} });
        send({ id: 2, method, params });
      } else if (message.id === 2) {
        finish(message.error ? new Error('Codex metadata unavailable') : null, message.result);
      }
    });
    send({ id: 1, method: 'initialize', params: { clientInfo: { name: 'ralph', version: '1.0.0' } } });
  });
}

function quotaReset(result, now = Date.now()) {
  const buckets = result.rateLimitsByLimitId ? Object.values(result.rateLimitsByLimitId) : [result.rateLimits];
  const blocked = buckets.flatMap(bucket => [bucket?.primary, bucket?.secondary])
    .filter(window => window && window.usedPercent >= 100);
  if (!blocked.length) return null;
  const times = blocked.map(window => window.resetsAt * 1000).filter(time => Number.isFinite(time) && time > now);
  return times.length === blocked.length ? Math.max(...times) + 30000 : now + 900000;
}

async function models() {
  let cursor;
  const entries = [];
  do {
    const page = await request('model/list', { limit: 100, ...(cursor ? { cursor } : {}) });
    entries.push(...page.data.map(model => ({
      model: model.model, defaultEffort: model.defaultReasoningEffort,
      efforts: model.supportedReasoningEfforts.map(item => item.reasoningEffort),
    })));
    cursor = page.nextCursor;
  } while (cursor);
  return entries;
}

module.exports = { request, quotaReset, models };
