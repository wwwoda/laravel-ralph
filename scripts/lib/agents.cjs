'use strict';

const { spawn } = require('node:child_process');
const readline = require('node:readline');

function argumentsFor(config, prompt, sessionId) {
  if (config.agent === 'codex') {
    const args = ['exec'];
    if (config.permissionMode === 'auto') args.push('--approve-for-me');
    if (sessionId) args.push('resume', sessionId);
    args.push('--json');
    if (config.model) args.push('--model', config.model);
    if (config.effort) args.push('-c', `model_reasoning_effort=${JSON.stringify(config.effort)}`);
    if (config.permissionMode === 'bypassPermissions') args.push('--dangerously-bypass-approvals-and-sandbox', '--skip-git-repo-check');
    else if (config.permissionMode !== 'auto') args.push('-c', 'approval_policy="never"', '-c', 'sandbox_mode="workspace-write"');
    args.push('-');
    return { command: 'codex', args, stdin: prompt };
  }
  const args = ['-p', prompt, '--verbose', '--output-format', 'stream-json', '--permission-mode', config.permissionMode];
  if (sessionId) args.push('--resume', sessionId);
  if (config.model) args.push('--model', config.model);
  if (config.effort) args.push('--effort', config.effort);
  if (config.budget) args.push('--max-budget-usd', config.budget);
  return { command: 'claude', args };
}

function errorKind(event) {
  const value = JSON.stringify(event);
  if (/UsageLimitExceeded|usage_limit_reached|rate_limit_exceeded|rate_limit_error|five_hour|seven_day|hit your.*limit|usage limit/i.test(value)) return 'quota';
  if (/Unauthorized|authentication_error|invalid_api_key|not logged in|authentication failed/i.test(value)) return 'auth';
  if (/HttpConnectionFailed|ResponseStreamConnectionFailed|ResponseStreamDisconnected|ECONNRESET|ETIMEDOUT|overloaded_error/i.test(value)) return 'network';
  return 'work';
}

function resetTime(value) {
  if (!value || typeof value !== 'object') return null;
  const times = [];
  for (const [key, item] of Object.entries(value)) {
    if (['resetsAt', 'resets_at', 'reset_at'].includes(key) && Number.isFinite(Number(item))) {
      const n = Number(item);
      times.push(n < 1e12 ? n * 1000 : n);
    } else if (item && typeof item === 'object') {
      const nested = resetTime(item);
      if (nested) times.push(nested);
    }
  }
  return times.length ? Math.max(...times) : null;
}

function consume(result, event, agent) {
  if (agent === 'codex') {
    if (event.type === 'thread.started') result.sessionId = event.thread_id;
    if (event.type === 'item.completed' && event.item?.type === 'agent_message') result.final = event.item.text || '';
    if (event.type === 'turn.completed') result.success = true;
    if (event.type === 'turn.failed' || event.type === 'error') {
      result.kind = errorKind(event);
      result.retryAt = resetTime(event);
      result.failed = true;
    }
  } else {
    if (event.session_id) result.sessionId = event.session_id;
    if (event.type === 'rate_limit_event' && event.rate_limit_info?.status === 'rejected') {
      result.kind = 'quota';
      result.retryAt = resetTime(event);
      result.failed = true;
    }
    if (event.type === 'result') {
      result.final = event.result || '';
      result.success = !event.is_error && event.subtype === 'success';
      if (!result.success) {
        result.failed = true;
        if (result.kind !== 'quota') result.kind = errorKind(event);
        result.retryAt ||= resetTime(event);
      }
    }
  }
}

function runAgent(config, prompt, sessionId, onEvent = () => {}, signal) {
  const invocation = argumentsFor(config, prompt, sessionId);
  const env = { ...process.env };
  delete env.CLAUDECODE;
  return new Promise((resolve, reject) => {
    const child = spawn(invocation.command, invocation.args, { env, stdio: ['pipe', 'pipe', 'pipe'], signal });
    const result = { success: false, failed: false, kind: 'work', final: '', sessionId, retryAt: null };
    readline.createInterface({ input: child.stdout }).on('line', line => {
      try {
        const event = JSON.parse(line);
        consume(result, event, config.agent);
        onEvent(event, result);
      } catch { /* CLI banners are not task results. */ }
    });
    child.stderr.on('data', data => {
      const kind = errorKind(data.toString());
      if (kind !== 'work') result.kind = kind;
    });
    child.on('error', reject);
    child.on('close', code => resolve({ ...result, success: code === 0 && result.success && !result.failed, exitCode: code }));
    child.stdin.on('error', () => {});
    child.stdin.end(invocation.stdin || '');
  });
}

module.exports = { argumentsFor, consume, errorKind, resetTime, runAgent };
