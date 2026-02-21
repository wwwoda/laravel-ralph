#!/usr/bin/env node

/**
 * Ralph Loop — Claude Code agent loop with session resume.
 *
 * Runs `claude` CLI iteratively, parsing stream-json output for readable
 * terminal display, logging to file, and detecting completion markers.
 *
 * Usage:
 *   node ralph-loop.js --prompt <file-or-text> --iterations <n> --name <name>
 *     [--permission-mode <mode>] [--model <model>] [--session-id <uuid>]
 *     [--budget <amount>] [--fresh]
 *
 * Environment:
 *   AGENT_PROMPT_SUFFIX — appended to every prompt
 *   AGENT_LOG_DIR — log output directory (default: storage/ralph-logs)
 *   AGENT_COMPLETION_MARKER — marker to detect task completion
 *   AGENT_CONTINUATION_PROMPT — prompt for iterations 2+ in resume mode
 *
 * Exit codes:
 *   0 = complete (marker detected)
 *   1 = error
 *   2 = max iterations reached
 */

const { spawn } = require("child_process");
const fs = require("fs");
const path = require("path");
const readline = require("readline");

// ── Argument parsing ──────────────────────────────────────────────

function parseArgs() {
  const args = process.argv.slice(2);
  const parsed = {
    prompt: null,
    iterations: 30,
    name: "ralph",
    permissionMode: "acceptEdits",
    model: null,
    sessionId: null,
    budget: null,
    fresh: false,
  };

  for (let i = 0; i < args.length; i++) {
    switch (args[i]) {
      case "--prompt":
        parsed.prompt = args[++i];
        break;
      case "--iterations":
        parsed.iterations = parseInt(args[++i], 10);
        break;
      case "--name":
        parsed.name = args[++i];
        break;
      case "--permission-mode":
        parsed.permissionMode = args[++i];
        break;
      case "--model":
        parsed.model = args[++i];
        break;
      case "--session-id":
        parsed.sessionId = args[++i];
        break;
      case "--budget":
        parsed.budget = args[++i];
        break;
      case "--fresh":
        parsed.fresh = true;
        break;
    }
  }

  return parsed;
}

// ── Colors ────────────────────────────────────────────────────────

const color = {
  reset: "\x1b[0m",
  dim: "\x1b[2m",
  bold: "\x1b[1m",
  cyan: "\x1b[36m",
  green: "\x1b[32m",
  yellow: "\x1b[33m",
  red: "\x1b[31m",
  magenta: "\x1b[35m",
  blue: "\x1b[34m",
};

// ── Logging ───────────────────────────────────────────────────────

function createLogger(name) {
  const logDir =
    process.env.AGENT_LOG_DIR || path.join("storage", "ralph-logs");
  const agentLogDir = path.join(logDir, name);

  fs.mkdirSync(agentLogDir, { recursive: true });

  const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
  const logPath = path.join(agentLogDir, `${timestamp}.log`);
  const stream = fs.createWriteStream(logPath, { flags: "a" });

  return {
    path: logPath,
    write(text) {
      stream.write(text);
    },
    writeLine(text) {
      stream.write(text + "\n");
    },
    close() {
      stream.end();
    },
  };
}

// ── Prompt resolution ─────────────────────────────────────────────

function resolvePrompt(promptArg) {
  if (!promptArg) {
    console.error(`${color.red}Error: --prompt is required${color.reset}`);
    process.exit(1);
  }

  // If it's a file path, read its contents
  if (fs.existsSync(promptArg)) {
    return fs.readFileSync(promptArg, "utf-8").trim();
  }

  // Otherwise treat as inline text
  return promptArg;
}

// ── Stream event formatting ───────────────────────────────────────

function formatEvent(event) {
  switch (event.type) {
    case "assistant":
      if (event.message?.content) {
        for (const block of event.message.content) {
          if (block.type === "text") {
            return `${color.cyan}Claude:${color.reset} ${block.text}`;
          }
          if (block.type === "tool_use") {
            return `${color.magenta}Tool:${color.reset} ${block.name}(${truncate(JSON.stringify(block.input), 200)})`;
          }
        }
      }
      return null;

    case "result":
      if (event.result) {
        return `${color.green}Result:${color.reset} ${truncate(event.result, 500)}`;
      }
      if (event.cost_usd !== undefined) {
        return `${color.dim}Cost: $${event.cost_usd.toFixed(4)} | Duration: ${event.duration_ms}ms${color.reset}`;
      }
      return null;

    default:
      return null;
  }
}

function truncate(str, maxLen) {
  if (!str) return "";
  if (str.length <= maxLen) return str;
  return str.slice(0, maxLen) + "...";
}

// ── Run single Claude iteration ───────────────────────────────────

function runClaude(claudeArgs, logger) {
  return new Promise((resolve, reject) => {
    const proc = spawn("claude", claudeArgs, {
      stdio: ["inherit", "pipe", "pipe"],
      env: { ...process.env },
    });

    let output = "";
    let completionDetected = false;
    const completionMarker =
      process.env.AGENT_COMPLETION_MARKER || "<promise>COMPLETE</promise>";

    const rl = readline.createInterface({ input: proc.stdout });

    rl.on("line", (line) => {
      logger.writeLine(line);

      try {
        const event = JSON.parse(line);
        const formatted = formatEvent(event);

        if (formatted) {
          console.log(formatted);
        }

        // Collect text output for completion detection
        if (event.type === "assistant" && event.message?.content) {
          for (const block of event.message.content) {
            if (block.type === "text") {
              output += block.text;
            }
          }
        }

        if (event.type === "result" && event.result) {
          output += event.result;
        }
      } catch {
        // Non-JSON line, just log it
        if (line.trim()) {
          console.log(`${color.dim}${line}${color.reset}`);
        }
      }
    });

    proc.stderr.on("data", (data) => {
      const text = data.toString();
      logger.write(text);
      process.stderr.write(`${color.dim}${text}${color.reset}`);
    });

    proc.on("close", (code) => {
      completionDetected = output.includes(completionMarker);

      resolve({
        exitCode: code,
        completionDetected,
        output,
      });
    });

    proc.on("error", (err) => {
      reject(err);
    });
  });
}

// ── Build Claude CLI args ────────────────────────────────────────

function buildClaudeArgs(config, prompt, iteration) {
  const commonArgs = [
    "--verbose",
    "--output-format",
    "stream-json",
    "--permission-mode",
    config.permissionMode,
  ];

  if (config.model) {
    commonArgs.push("--model", config.model);
  }

  if (config.budget) {
    commonArgs.push("--max-budget-usd", config.budget);
  }

  // Fresh mode: every iteration is independent
  if (config.fresh) {
    return ["-p", prompt, ...commonArgs];
  }

  // First iteration: new session with session ID
  if (iteration === 1) {
    const args = ["-p", prompt, ...commonArgs];
    if (config.sessionId) {
      args.push("--session-id", config.sessionId);
    }
    return args;
  }

  // Iteration 2+: resume existing session
  if (config.sessionId) {
    return ["--resume", config.sessionId, "-p", prompt, ...commonArgs];
  }

  // Fallback: no session ID, run fresh
  return ["-p", prompt, ...commonArgs];
}

// ── Main loop ─────────────────────────────────────────────────────

async function main() {
  const config = parseArgs();
  const promptSuffix = process.env.AGENT_PROMPT_SUFFIX || "";
  const continuationPrompt = process.env.AGENT_CONTINUATION_PROMPT || "Continue working on the task.";
  const basePrompt = resolvePrompt(config.prompt);
  const logger = createLogger(config.name);

  const fullPrompt = promptSuffix
    ? `${basePrompt}\n\n${promptSuffix}`
    : basePrompt;

  const continuePrompt = promptSuffix
    ? `${continuationPrompt}\n\n${promptSuffix}`
    : continuationPrompt;

  const resumeMode = !config.fresh && config.sessionId;

  console.log(
    `${color.bold}${color.blue}╔══════════════════════════════════════╗${color.reset}`,
  );
  console.log(
    `${color.bold}${color.blue}║  Ralph Loop — ${config.name}${color.reset}`,
  );
  console.log(
    `${color.bold}${color.blue}║  Iterations: ${config.iterations}${color.reset}`,
  );
  console.log(
    `${color.bold}${color.blue}║  Session: ${config.sessionId || "none"}${color.reset}`,
  );
  console.log(
    `${color.bold}${color.blue}║  Resume: ${resumeMode ? "enabled" : "disabled"}${color.reset}`,
  );
  console.log(
    `${color.bold}${color.blue}║  Log: ${logger.path}${color.reset}`,
  );
  console.log(
    `${color.bold}${color.blue}╚══════════════════════════════════════╝${color.reset}`,
  );
  console.log();

  logger.writeLine(
    `[${new Date().toISOString()}] Starting ralph loop: ${config.name}`,
  );
  logger.writeLine(`Iterations: ${config.iterations}`);
  logger.writeLine(`Permission mode: ${config.permissionMode}`);
  logger.writeLine(`Model: ${config.model || "default"}`);
  logger.writeLine(`Session ID: ${config.sessionId || "none"}`);
  logger.writeLine(`Resume: ${resumeMode ? "enabled" : "disabled"}`);
  logger.writeLine(`Prompt: ${basePrompt.slice(0, 200)}...`);
  logger.writeLine("---");

  for (let i = 1; i <= config.iterations; i++) {
    console.log(
      `\n${color.bold}${color.yellow}── Iteration ${i}/${config.iterations} ──${color.reset}\n`,
    );
    logger.writeLine(
      `\n[${new Date().toISOString()}] === Iteration ${i}/${config.iterations} ===`,
    );

    // Use full prompt for iteration 1 (or all iterations in fresh mode),
    // continuation prompt for subsequent iterations in resume mode
    const prompt = (i === 1 || config.fresh) ? fullPrompt : continuePrompt;
    const claudeArgs = buildClaudeArgs(config, prompt, i);

    logger.writeLine(`Claude args: ${JSON.stringify(claudeArgs)}`);

    try {
      const result = await runClaude(claudeArgs, logger);

      if (result.completionDetected) {
        console.log(
          `\n${color.bold}${color.green}✓ Completion marker detected on iteration ${i}. Done!${color.reset}`,
        );
        logger.writeLine(
          `[${new Date().toISOString()}] Completion detected on iteration ${i}`,
        );
        logger.close();
        process.exit(0);
      }

      if (result.exitCode !== 0) {
        console.log(
          `\n${color.yellow}Claude exited with code ${result.exitCode}${color.reset}`,
        );
        logger.writeLine(
          `[${new Date().toISOString()}] Claude exited with code ${result.exitCode}`,
        );

        // If resume failed on iteration 2+, fall back to fresh
        if (i > 1 && resumeMode && result.exitCode !== 0) {
          console.log(
            `${color.yellow}Resume may have failed, retrying as fresh...${color.reset}`,
          );
          logger.writeLine("Retrying iteration as fresh invocation");

          const freshArgs = ["-p", fullPrompt, "--verbose", "--output-format", "stream-json", "--permission-mode", config.permissionMode];
          if (config.model) freshArgs.push("--model", config.model);
          if (config.budget) freshArgs.push("--max-budget-usd", config.budget);

          const retryResult = await runClaude(freshArgs, logger);

          if (retryResult.completionDetected) {
            console.log(
              `\n${color.bold}${color.green}✓ Completion marker detected on retry. Done!${color.reset}`,
            );
            logger.close();
            process.exit(0);
          }
        }
      }
    } catch (err) {
      console.error(
        `\n${color.red}Error on iteration ${i}: ${err.message}${color.reset}`,
      );
      logger.writeLine(
        `[${new Date().toISOString()}] Error: ${err.message}`,
      );
      logger.close();
      process.exit(1);
    }
  }

  console.log(
    `\n${color.bold}${color.yellow}Max iterations (${config.iterations}) reached.${color.reset}`,
  );
  logger.writeLine(
    `[${new Date().toISOString()}] Max iterations reached`,
  );
  logger.close();
  process.exit(2);
}

main().catch((err) => {
  console.error(`${color.red}Fatal error: ${err.message}${color.reset}`);
  process.exit(1);
});
