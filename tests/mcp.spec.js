/**
 * Tests for the authenticated MCP endpoint: `POST /api/v1/mcp`.
 *
 * This spec covers the JSON-RPC transport, the lifecycle/initialize
 * handshake, and the read-tool smoke paths exposed by the MCP plugin
 * (see `plugins/mcp/readme.md`). It deliberately skips write-tool
 * execution: those tools delegate to core controllers and have
 * significant on-disk side effects that warrant their own dedicated,
 * file-system-snapshotting suite.
 *
 * Tests that need to flip a `plugins.mcp.*` flag use `mutateMcpSettings`
 * from `./mcp-settings.js`. The helper takes a snapshot of
 * `settings/settings.yaml` before the mutation and restores it in
 * `afterAll`, so the live installation is byte-identical after the
 * suite - even when individual tests fail.
 *
 * Context lifetime: each request opens its own APIRequestContext and
 * returns BOTH the response and a `dispose` callback. The caller MUST
 * call `dispose()` after it has finished reading the response body -
 * disposing the context invalidates the response, so reading after
 * disposal would throw "Response has been disposed".
 */

const { test, expect } = require('@playwright/test');
const {
    credentials,
    basicAuthHeader,
    mutateMcpSettings,
    jsonRpcHeaders,
    serialiseBody,
    JSONRPC,
} = require('./mcp-settings');

const MCP_PATH = '/api/v1/mcp';

function openRequestContext(playwright, extraHeaders = {}) {
    return playwright.request.newContext({
        baseURL: process.env.TYPEMILL_BASE_URL || 'http://localhost/',
        extraHTTPHeaders: { Accept: 'application/json', ...extraHeaders },
    });
}

/**
 * POST a JSON-RPC envelope to /api/v1/mcp with HTTP Basic Auth.
 *
 * Returns `{ response, dispose }` so the caller can read the response body
 * before tearing the context down.
 */
async function postMcpAuthed(playwright, body, { headers = {} } = {}, user = credentials.admin) {
    const ctx = await openRequestContext(playwright, basicAuthHeader(user));
    const response = await ctx.post(credentials.url(MCP_PATH), {
        headers: jsonRpcHeaders(headers),
        data: serialiseBody(body),
    });
    return {
        response,
        dispose: () => ctx.dispose(),
    };
}

/**
 * POST a JSON-RPC envelope to /api/v1/mcp WITHOUT credentials. Used by the
 * auth-gate tests so we exercise the middleware rejection path.
 */
async function postMcpAnonymous(playwright, body, { headers = {} } = {}) {
    const ctx = await openRequestContext(playwright);
    const response = await ctx.post(credentials.url(MCP_PATH), {
        headers: jsonRpcHeaders(headers),
        data: serialiseBody(body),
    });
    return {
        response,
        dispose: () => ctx.dispose(),
    };
}

/**
 * Convenience wrapper that auto-disposes the context after the recipe runs.
 * Lets each spec express itself as a single block: post → assert → done.
 */
async function withAuthed(playwright, body, recipe, { postOpts, user } = {}) {
    const handle = await postMcpAuthed(playwright, body, postOpts, user);
    try {
        return await recipe(handle.response);
    } finally {
        await handle.dispose();
    }
}

/**
 * Same as `withAuthed` but for the anonymous (no-Basic-Auth) variant.
 */
async function withAnonymous(playwright, body, recipe, { postOpts } = {}) {
    const handle = await postMcpAnonymous(playwright, body, postOpts);
    try {
        return await recipe(handle.response);
    } finally {
        await handle.dispose();
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Authentication gate
// ──────────────────────────────────────────────────────────────────────────────

test.describe('MCP authenticated endpoint — auth gate', () => {
    test('POST /api/v1/mcp without credentials returns 401', async ({ playwright }) => {
        await withAnonymous(playwright, JSONRPC.request('ping'), async (response) => {
            expect(response.status()).toBe(401);
        });
    });

    test('POST /api/v1/mcp with wrong password returns 401', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('ping'),
            async (response) => {
                expect(response.status()).toBe(401);
            },
            {
                user: {
                    username: credentials.admin.username,
                    password: 'definitely-not-the-password',
                },
            }
        );
    });

    test('POST /api/v1/mcp with a user that has no `apiaccess` flag returns 401', async ({ playwright }) => {
        // `guest` exists on the local fixture but has `apiaccess` unset, mirroring
        // a normal Typemill user. ApiAuthentication must reject the call.
        await withAuthed(
            playwright,
            JSONRPC.request('ping'),
            async (response) => {
                expect(response.status()).toBe(401);
            },
            { user: { username: 'guest', password: 'password' } }
        );
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// JSON-RPC 2.0 envelope — transport and protocol-level errors
// ──────────────────────────────────────────────────────────────────────────────

test.describe('MCP authenticated endpoint — JSON-RPC envelope', () => {
    test('ping returns an empty object (HTTP 200)', async ({ playwright }) => {
        await withAuthed(playwright, JSONRPC.request('ping'), async (response) => {
            expect(response.status()).toBe(200);
            const body = await response.json();
            expect(body).toMatchObject({ jsonrpc: '2.0', id: 1 });
            expect(body.result).toEqual({});
            expect(body.error).toBeUndefined();
        });
    });

    test('notification (no id) returns HTTP 202 with empty body', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.notification('notifications/initialized'),
            async (response) => {
                expect(response.status()).toBe(202);
                const text = await response.text();
                expect(text).toBe('');
            }
        );
    });

    test('empty body returns transport-level parse error (-32700) over HTTP 400', async ({ playwright }) => {
        await withAuthed(playwright, '', async (response) => {
            expect(response.status()).toBe(400);
            const body = await response.json();
            // The controller's transport-error envelope embeds the JSON-RPC
            // shape with id=null but still identifies itself as jsonrpc 2.0.
            expect(body).toMatchObject({ jsonrpc: '2.0', id: null });
            expect(body.error).toBeDefined();
            expect(body.error.code).toBe(-32700);
            expect(body.error.message).toMatch(/empty body/i);
        });
    });

    test('malformed JSON returns transport-level parse error (-32700) over HTTP 400', async ({ playwright }) => {
        await withAuthed(playwright, '{ this is not valid JSON', async (response) => {
            expect(response.status()).toBe(400);
            const body = await response.json();
            expect(body.error.code).toBe(-32700);
            expect(body.error.message).toMatch(/parse error/i);
        });
    });

    test('batch request (JSON array) is rejected with -32600', async ({ playwright }) => {
        await withAuthed(
            playwright,
            [JSONRPC.request('ping'), JSONRPC.request('ping', undefined, 2)],
            async (response) => {
                expect(response.status()).toBe(200); // protocol errors still surface as JSON-RPC responses
                const body = await response.json();
                expect(body.error.code).toBe(-32600);
                expect(body.error.message).toMatch(/batch/i);
            }
        );
    });

    test('invalid jsonrpc version is rejected with -32600', async ({ playwright }) => {
        await withAuthed(
            playwright,
            { jsonrpc: '1.0', id: 1, method: 'ping' },
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.error.code).toBe(-32600);
                expect(body.error.message).toMatch(/invalid jsonrpc/i);
            }
        );
    });

    test('missing method is rejected with -32600', async ({ playwright }) => {
        await withAuthed(playwright, { jsonrpc: '2.0', id: 1 }, async (response) => {
            expect(response.status()).toBe(200);
            const body = await response.json();
            expect(body.error.code).toBe(-32600);
            expect(body.error.message).toMatch(/method is required/i);
        });
    });

    test('unknown method is rejected with -32601', async ({ playwright }) => {
        await withAuthed(playwright, JSONRPC.request('tools/makeCoffee'), async (response) => {
            expect(response.status()).toBe(200);
            const body = await response.json();
            expect(body.error.code).toBe(-32601);
            expect(body.error.message).toMatch(/method not found: tools\/makeCoffee/);
        });
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// initialize + protocolVersion negotiation
// ──────────────────────────────────────────────────────────────────────────────

test.describe('MCP authenticated endpoint — initialize', () => {
    test('echoes a supported protocolVersion', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('initialize', { protocolVersion: '2025-06-18', capabilities: {} }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.error).toBeUndefined();
                expect(body.result.protocolVersion).toBe('2025-06-18');
                expect(body.result.capabilities).toEqual({ tools: { listChanged: false } });
                expect(body.result.serverInfo).toMatchObject({ name: 'typemill-mcp' });
            }
        );
    });

    test('negotiates down to the default protocolVersion for an unknown client version', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('initialize', { protocolVersion: '9999-12-31', capabilities: {} }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                // The protocol layer advertises a stable fallback when the
                // client sends a version it does not recognise (see
                // McpProtocol::DEFAULT_PROTOCOL_VERSION).
                expect(body.result.protocolVersion).toBe('2025-06-18');
            }
        );
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// tools/list — role-driven discovery
// ──────────────────────────────────────────────────────────────────────────────

test.describe('MCP authenticated endpoint — tools/list', () => {
    test('administrator sees the read tools and currently-disabled write-tools entries are absent', async ({ playwright }) => {
        // Live fixture has `mcp.read_tools: true`, `mcp.write_tools: true`,
        // `bettersearch.active: false`. Read tools appear; search_pages never
        // appears because bettersearch is inactive.
        await withAuthed(playwright, JSONRPC.request('tools/list'), async (response) => {
            expect(response.status()).toBe(200);
            const body = await response.json();
            expect(body.error).toBeUndefined();
            const names = body.result.tools.map((t) => t.name).sort();

            expect(names).toContain('get_site_info');
            expect(names).toContain('list_pages');
            expect(names).toContain('find_page');
            expect(names).toContain('get_page');
            expect(names).toContain('get_page_metadata');

            // search_pages is bettersearch-gated and must be absent when the
            // companion plugin is inactive (no human can call it anyway).
            expect(names).not.toContain('search_pages');
        });
    });

    test('every tool entry has MCP-mandated name/description/inputSchema fields', async ({ playwright }) => {
        await withAuthed(playwright, JSONRPC.request('tools/list'), async (response) => {
            const tools = (await response.json()).result.tools;
            expect(tools.length).toBeGreaterThan(0);
            for (const tool of tools) {
                expect(typeof tool.name).toBe('string');
                expect(tool.name.length).toBeGreaterThan(0);
                expect(typeof tool.description).toBe('string');
                expect(typeof tool.inputSchema).toBe('object');
                // The plugin's registry emits JSON Schema draft-07 objects.
                expect(tool.inputSchema.type).toBe('object');
            }
        });
    });

    test('a member-level user does not see write tools in tools/list', async ({ playwright }) => {
        // `apitest` has userrole=member (apiaccess: true). Member inherits
        // guest which has public:read only; content:* / mycontent:* /
        // system:* should be filtered out of the list.
        await withAuthed(
            playwright,
            JSONRPC.request('tools/list'),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                const names = body.result.tools.map((t) => t.name);
                expect(names).toContain('get_site_info'); // public:read
                expect(names).not.toContain('create_page'); // content:create
                expect(names).not.toContain('publish_page'); // content:publish
                expect(names).not.toContain('delete_page'); // content:delete
                expect(names).not.toContain('update_page_metadata'); // mycontent:update
            },
            {
                user: {
                    username: 'apitest',
                    password: 'password',
                },
            }
        );
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// tools/call — happy paths and known input-validation errors
// ──────────────────────────────────────────────────────────────────────────────

test.describe('MCP authenticated endpoint — tools/call', () => {
    test('get_site_info returns the safe whitelist with expected keys', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', { name: 'get_site_info', arguments: {} }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.error).toBeUndefined();
                expect(body.result.isError).toBe(false);
                const parsed = JSON.parse(body.result.content[0].text);
                expect(parsed).toEqual(
                    expect.objectContaining({
                        title: expect.any(String),
                        author: expect.any(String),
                        baseurl: expect.any(String),
                    })
                );
                // Whitelist guard: the controlled surface must not leak internals.
                const forbidden = ['urlinfo', 'storage', 'themes', 'plugins'];
                for (const key of forbidden) {
                    expect(parsed).not.toHaveProperty(key);
                }
            }
        );
    });

    test('list_pages scope=live returns at least one page', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', {
                name: 'list_pages',
                arguments: { scope: 'live', url: '/' },
            }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.result.isError).toBe(false);
                const parsed = JSON.parse(body.result.content[0].text);
                expect(parsed.scope).toBe('live');
                expect(Array.isArray(parsed.pages)).toBe(true);
                expect(parsed.pages.length).toBeGreaterThan(0);
                // Each node is shaped as { title, url, type, status, children? }
                // with no filesystem path leakage.
                for (const node of parsed.pages) {
                    expect(node).toHaveProperty('title');
                    expect(node).toHaveProperty('url');
                    expect(node).toHaveProperty('type');
                    expect(node).not.toHaveProperty('folderContent');
                    expect(node).not.toHaveProperty('keyPath');
                }
            }
        );
    });

    test('find_page by url returns the matching item', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', {
                name: 'find_page',
                arguments: { url: '/getting-started/create-your-first-page' },
            }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.result.isError).toBe(false);
                const parsed = JSON.parse(body.result.content[0].text);
                expect(Array.isArray(parsed.matches)).toBe(true);
                expect(parsed.matches.length).toBe(1);
                expect(parsed.matches[0].url).toBe('/getting-started/create-your-first-page');
            }
        );
    });

    test('get_page returns the page markdown for a known URL', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', {
                name: 'get_page',
                arguments: { url: '/getting-started/create-your-first-page' },
            }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.result.isError).toBe(false);
                const parsed = JSON.parse(body.result.content[0].text);
                expect(parsed.url).toBe('/getting-started/create-your-first-page');
                // `title` is sourced from the navigation item (derived from the
                // URL slug - the live fixture normalises "create-your-first-page"
                // to "create your first page"). Assert the slug pieces, not a
                // specific capitalisation, so the assertion survives the slug
                // transformation the navigation model applies.
                const title = String(parsed.title).toLowerCase();
                expect(title).toContain('create');
                expect(title).toContain('first');
                expect(title).toContain('page');
                // The page body contains the H1 we saw in /content on disk.
                expect(parsed.markdown.toLowerCase()).toContain('your first page');
            }
        );
    });

    test('get_page with a non-existent URL returns isError:true with body HTTP 200', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', {
                name: 'get_page',
                arguments: { url: '/__this-url-does-not-exist__' },
            }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.result.isError).toBe(true);
                expect(body.result.content[0].text).toContain('/__this-url-does-not-exist__');
            }
        );
    });

    test('get_page_metadata returns the meta record for a known URL', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', {
                name: 'get_page_metadata',
                arguments: { url: '/getting-started/create-your-first-page' },
            }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.result.isError).toBe(false);
                const parsed = JSON.parse(body.result.content[0].text);
                expect(parsed.url).toBe('/getting-started/create-your-first-page');
                expect(typeof parsed.metadata).toBe('object');
            }
        );
    });

    test('unknown tool name is rejected with -32602', async ({ playwright }) => {
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', { name: 'no_such_tool', arguments: {} }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.error.code).toBe(-32602);
                expect(body.error.message).toMatch(/no_such_tool/);
            }
        );
    });

    test('argument validation rejects unknown keys with -32602', async ({ playwright }) => {
        // get_page has additionalProperties:false; smuggling in a stray key
        // is the canonical schema-violation case.
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', {
                name: 'get_page',
                arguments: { url: '/getting-started/create-your-first-page', notAnArg: true },
            }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.error.code).toBe(-32602);
                expect(body.error.message).toMatch(/unknown argument: notAnArg/);
            }
        );
    });

    test('argument validation rejects missing required keys with -32602', async ({ playwright }) => {
        // create_page requires {parent_url, name}; missing both is an
        // immediate -32602 short-circuit, before any ACL check. We send
        // create_page because it sits behind content:create (admin-only);
        // the schema validation fires before the ACL deny, so the response
        // is exactly the same for admin and member users.
        await withAuthed(
            playwright,
            JSONRPC.request('tools/call', { name: 'create_page', arguments: {} }),
            async (response) => {
                expect(response.status()).toBe(200);
                const body = await response.json();
                expect(body.error.code).toBe(-32602);
                expect(body.error.message).toMatch(/missing required argument/);
            }
        );
    });
});

// ──────────────────────────────────────────────────────────────────────────────
// Plugin on/off — verified through the live /settings/settings.yaml reload cycle
// ──────────────────────────────────────────────────────────────────────────────

test.describe('MCP authenticated endpoint — settings', () => {
    let snapshot;

    test.beforeAll(() => {
        snapshot = mutateMcpSettings({ active: false });
    });

    test.afterAll(() => {
        snapshot.restore();
    });

    test('when mcp.active is false the route is not registered (POST returns 405)', async ({ playwright }) => {
        // With `active: false` system.php never registers `addNewRoutes()` for
        // this plugin (Ref: system.php around line 215). Slim therefore has no
        // handler for POST /api/v1/mcp and the protected `/api/v1` group
        // rejects it with 405 (Method Not Allowed). Crucially the body MUST
        // NOT be a JSON-RPC envelope — the controller never executes.
        await withAuthed(playwright, JSONRPC.request('ping'), async (response) => {
            expect(response.status()).toBe(405);
            const text = await response.text();
            expect(text).not.toMatch(/jsonrpc/i);
        });
    });
});
