/**
 * Helpers shared by the MCP plugin's Playwright specs.
 *
 * Why this lives inside the plugin folder:
 *   - The plugin owns its own test surface; the core fixtures under
 *     /tests/fixtures stay core-only.
 *   - The MCP endpoint hits /settings/settings.yaml at runtime, and a
 *     non-trivial subset of the suite mutates `plugins.mcp.*` flags
 *     (active, public_endpoint, allow_origin, public_origin,
 *     public_max_payload_kb). We therefore depend on the existing
 *     backupSettings/restoreSettings pair from /tests/fixtures/instance
 *     so the live installation is byte-identical after the suite, even
 *     when individual tests fail mid-flight.
 *
 * The settings mutator is intentionally NOT a full YAML parser:
 *   - Symfony YAML writes /settings/settings.yaml with a stable 4-space
 *     indentation under `plugins.<name>` and one value per line for the
 *     MCP keys we touch. Anchor the replacement on the line under
 *     `mcp:` that begins `        <key>:` and rewrite the value.
 *   - Backup/restore (handled here via the per-test snapshot) makes the
 *     helper safe to use without rounding-trip parsing.
 */

const fs = require('fs');
const path = require('path');

// plugin tests live at /plugins/mcp/tests → climb 3 levels to the repo root
const REPO_ROOT = path.resolve(__dirname, '..', '..', '..');
const SETTINGS_FILE = path.join(REPO_ROOT, 'settings', 'settings.yaml');

const {
    backupSettings,
    restoreSettings,
} = require(path.join(REPO_ROOT, 'tests', 'fixtures', 'instance.js'));
const credentials = require(path.join(REPO_ROOT, 'tests', 'fixtures', 'credentials.js'));

/**
 * Escape a literal string so it can be embedded in a RegExp.
 */
function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Render a JS value as the right-hand side of a YAML scalar in the form
 * Symfony YAML writes. We only support the types we actually need to
 * emit (boolean, integer, plain string); anything else throws so
 * mistake-driven writes fail loudly rather than silently corrupt the file.
 */
function formatScalar(value) {
    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
        return String(value);
    }
    if (typeof value === 'string') {
        // Bare alphanumeric/dot/dash/underscore strings need no quoting.
        if (/^[A-Za-z0-9_.\-/]+$/.test(value)) {
            return value;
        }
        // Single-quoted YAML string; double up any embedded single quotes.
        return `'${value.replace(/'/g, "''")}'`;
    }
    throw new Error(`formatScalar: unsupported value type ${typeof value} for ${value}`);
}

/**
 * Apply a set of scalar mutations to settings.yaml under `plugins.mcp`.
 * Every key in `mutations` MUST exist on a single 8-space-indented line
 * beneath `    mcp:`. Missing keys throw so a typo is caught loudly.
 *
 * Implementation: a line-by-line scan rather than a single multi-line
 * regex. Three reasons:
 *   - The `plugins.mcp` block contains 8-space and 12-space indented rows
 *     (the `public_tools:` list lives at 8, its `- item` rows at 12). A
 *     simple regex that anchors on `^ {4}mcp:` followed by `\n        <key>:`
 *     only matches the FIRST scalar after `mcp:`; later keys silently fail.
 *   - Symfony YAML writes a stable indentation but the regex's `.*`
 *     across the rest of the block can be ambiguous about where the line
 *     boundary actually is.
 *   - A line scan makes errors self-explanatory: report the file + line
 *     number of the row we replaced.
 *
 * @param {Record<string, string|number|boolean>} mutations
 * @returns {{ snapshotDir: string, restore: () => void }}
 */
function mutateMcpSettings(mutations) {
    const snapshotDir = backupSettings();

    try {
        const original = fs.readFileSync(SETTINGS_FILE, 'utf8');
        const lines = original.split('\n');

        // 1. Find the "    mcp:" row that starts the MCP settings block.
        const mcpRowIndex = lines.findIndex((line) => /^ {4}mcp: ?(#[^\n]*)?$/.test(line));
        if (mcpRowIndex === -1) {
            throw new Error(`mutateMcpSettings: could not find '    mcp:' section in ${SETTINGS_FILE}`);
        }

        // 2. Walk forward to find the end of the block: the first line that
        // is non-empty, non-comment, and indented at fewer than 8 spaces.
        // List items under `public_tools:` live at 12 spaces and stay inside
        // the block.
        let mcpBlockEnd = lines.length;
        for (let i = mcpRowIndex + 1; i < lines.length; i++) {
            const line = lines[i];
            const trimmed = line.trim();
            if (trimmed === '' || trimmed.startsWith('#')) {
                continue;
            }
            const leading = line.length - line.replace(/^[ \t]+/, '').length;
            if (leading < 8) {
                mcpBlockEnd = i;
                break;
            }
        }

        // 3. Replace each requested key in-place. Preserve trailing
        // whitespace and any trailing `#`-comment on the same row.
        const nextLines = lines.slice();
        for (const [key, value] of Object.entries(mutations)) {
            const rowPattern = new RegExp(
                `^( {8})${escapeRegExp(key)}:[ \\t]*([^\\n#]*?)([ \\t]*(?:#.*)?)$`
            );
            let replacedAt = -1;
            for (let i = mcpRowIndex + 1; i < mcpBlockEnd; i++) {
                const m = nextLines[i].match(rowPattern);
                if (m) {
                    nextLines[i] = nextLines[i].replace(
                        rowPattern,
                        (_full, indent, _capturedValue, trail) =>
                            `${indent}${key}: ${formatScalar(value)}${trail}`
                    );
                    replacedAt = i + 1; // 1-indexed for human messages
                    break;
                }
            }
            if (replacedAt === -1) {
                throw new Error(
                    `mutateMcpSettings: key '${key}' not found as a scalar under plugins.mcp in ${SETTINGS_FILE}`
                );
            }
        }

        if (nextLines.join('\n') !== original) {
            fs.writeFileSync(SETTINGS_FILE, nextLines.join('\n'), 'utf8');
        }
    } catch (error) {
        // Roll back the snapshot on partial failure; otherwise the live install
        // would be left in a half-mutated state.
        restoreSettings(snapshotDir);
        throw error;
    }

    return {
        snapshotDir,
        restore: () => restoreSettings(snapshotDir),
    };
}

function basicAuthHeader(user) {
    return {
        Authorization: 'Basic ' + Buffer.from(`${user.username}:${user.password}`).toString('base64'),
    };
}

/**
 * Build a list of JSON-RPC 2.0 envelopes for tests. Kept central so every
 * spec uses identical shapes (and so a typo can't silently slip into the
 * transport layer we're testing).
 */
const JSONRPC = {
    request: (method, params = undefined, id = 1) => {
        const message = { jsonrpc: '2.0', id, method };
        if (params !== undefined) {
            message.params = params;
        }
        return message;
    },
    notification: (method, params = undefined) => {
        const message = { jsonrpc: '2.0', method };
        if (params !== undefined) {
            message.params = params;
        }
        return message;
    },
};

/**
 * Common header set for calling the JSON-RPC endpoints. The MCP plugin
 * reads the Content-Type for diagnostics; consumers of the public endpoint
 * are expected to send `Origin` (browsers do, server-side callers won't —
 * this is why empty-Origin requests pass the check).
 */
function jsonRpcHeaders(extra = {}) {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...extra,
    };
}

/**
 * Pick the wire format based on body shape. Strings are sent as raw bytes
 * (Buffer) so empty / malformed payloads reach the server unchanged;
 * objects and arrays are JSON-encoded by us and sent as a Buffer because
 * Playwright otherwise re-JSON-encodes strings (so `data: '{garbage'`
 * would arrive at the server as the wrapped literal `"\"{garbage\""`
 * and the controller's malformed-JSON path would never fire).
 *
 * Centralised here so both spec files use the same rule and adding a
 * binary body type (e.g. CBOR) later is a single-site change.
 */
function serialiseBody(body) {
    if (typeof body === 'string') {
        return Buffer.from(body, 'utf8');
    }
    return Buffer.from(JSON.stringify(body), 'utf8');
}

module.exports = {
    REPO_ROOT,
    SETTINGS_FILE,
    credentials,
    basicAuthHeader,
    mutateMcpSettings,
    jsonRpcHeaders,
    JSONRPC,
    serialiseBody,
};
