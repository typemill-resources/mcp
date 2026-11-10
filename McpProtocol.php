<?php

namespace Plugins\mcp;

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Plugins\mcp\ToolRegistry;

/**
 * Minimal implementation of the Model Context Protocol (MCP) over
 * Streamable HTTP, stateless mode.
 *
 * Spec versions we accept on initialize: 2024-11-05 .. 2025-06-18+.
 * When the client sends a version we know, we echo it back;
 * otherwise we negotiate down to a known stable version.
 *
 * Reference: https://modelcontextprotocol.io
 */
class McpProtocol
{
    /** Plugin version echoed in serverInfo. Keep in sync with mcp.yaml. */
    public const SERVER_VERSION = '1.0.0';

    /** Stable protocol version advertised when the client sends an unknown one. */
    private const DEFAULT_PROTOCOL_VERSION = '2025-06-18';

    /** Versions we recognize and will echo back unchanged. */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2024-11-05',
        '2025-03-26',
        '2025-06-18',
        '2025-11-25',
    ];

    private Container $container;

    /** Authenticated username from c_username request attribute (empty in public mode). */
    private string $username;

    /** Authenticated user role from c_userrole request attribute ('guest' in public mode). */
    private string $userrole;

    /** Plugin settings (so method handlers can honor toggles). */
    private array $pluginSettings;

    /** Cached bettersearch-active flag inherited from the plugin class. */
    private bool $bettersearchActive;

    /** Cached askthedocs-active flag inherited from the plugin class. */
    private bool $askthedocsActive;

    /**
     * Public-mode switch. When true:
     *   - Only the tools listed in $publicTools are eligible (the
     *     caller pre-filters by ACL=[public,read]).
     *   - Per-tool ACL is NOT re-checked (we already filtered).
     *   - `get_page` always rejects `draft=true`.
     *   - `list_pages` always rejects `scope="draft"`.
     *   - The transport itself handles payload caps / Origin.
     */
    private bool $publicMode;

    /** Whitelist of tool names available in publicMode (null = use registry). */
    private ?array $publicTools;

    public function __construct(
        Container $container,
        string $username,
        string $userrole,
        array $pluginSettings,
        bool $bettersearchActive,
        bool $askthedocsActive,
        bool $publicMode = false,
        ?array $publicTools = null
    ) {
        $this->container          = $container;
        $this->username           = $username;
        $this->userrole           = $userrole;
        $this->pluginSettings     = $pluginSettings;
        $this->bettersearchActive = $bettersearchActive;
        $this->askthedocsActive   = $askthedocsActive;
        $this->publicMode         = $publicMode;
        $this->publicTools        = $publicTools;
    }

    /**
     * Entry point used by McpController::handle() AFTER the body has been parsed.
     * Returns an associative array:
     *   [
     *     'httpStatus' => 200|202|400|...,
     *     'jsonrpc'    => string|null,   // null = empty body, e.g. notifications
     *     'body'       => array|null,    // decoded JSON-RPC response object/array
     *   ]
     *
     * @param mixed $parsed The body already decoded by the caller (or null if invalid).
     */
    public function dispatch($parsed): array
    {
        # ── Batch requests: explicitly rejected per spec/statement ──
        if (is_array($parsed) && array_is_list($parsed)) {
            return $this->errorResponse(null, -32600, 'batch not supported');
        }

        # ── Single JSON object ──
        if (!is_array($parsed)) {
            return $this->errorResponse(null, -32600, 'invalid request');
        }

        $jsonrpc = $parsed['jsonrpc'] ?? null;
        $method  = $parsed['method']  ?? null;
        $id      = $parsed['id']      ?? null;
        $params  = $parsed['params']  ?? [];

        if ($jsonrpc !== '2.0') {
            return $this->errorResponse($id, -32600, 'invalid jsonrpc version');
        }
        if (!is_string($method) || $method === '') {
            return $this->errorResponse($id, -32600, 'method is required');
        }
        if (!is_array($params)) {
            $params = [];
        }

        # ── Notification: no id → 202 empty body ──
        if ($id === null) {
            # We still run lifecycle notifications if we recognise them,
            # but never return a JSON-RPC body for them.
            if ($method === 'notifications/initialized') {
                # nothing to do, client told us it finished initialize
            } elseif ($method === 'notifications/cancelled') {
                # no work has been started in this stateless transport
            } elseif ($method === 'notifications/progress') {
                # no work tracking, ignore
            }
            return ['httpStatus' => 202, 'jsonrpc' => null, 'body' => null];
        }

        # ── id must be string|int|null for a real call; we already handled null above ──
        if (!is_string($id) && !is_int($id)) {
            return $this->errorResponse(null, -32600, 'invalid id');
        }

        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($id, $params);

            case 'ping':
                # heartbeat — empty object is the canonical MCP response
                return $this->successResponse($id, (object) []);

            case 'tools/list':
                return $this->handleToolsList($id);

            case 'tools/call':
                return $this->handleToolsCall($id, $params);

            default:
                return $this->errorResponse($id, -32601, 'method not found: ' . $method);
        }
    }

    // ─── initialize ────────────────────────────────────────────────────────────

    private function handleInitialize($id, array $params): array
    {
        $clientVersion = is_string($params['protocolVersion'] ?? null)
            ? $params['protocolVersion']
            : '';

        $negotiated = in_array($clientVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
            ? $clientVersion
            : self::DEFAULT_PROTOCOL_VERSION;

        $instructions = 'Typemill MCP server. Use list_pages to explore the site, find_page/get_page to read content, '
            . 'get_page_metadata for page metadata, search_pages (when BETTERSEARCH is active) for ranked full-text results, '
            . 'and get_site_info for the site title/author/base url. Write tools (create/update/publish/delete_page, '
            . 'update_page_metadata) honour the role permissions configured in ACL settings.';

        return $this->successResponse($id, [
            'protocolVersion' => $negotiated,
            'capabilities'    => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo'      => [
                'name'    => 'typemill-mcp',
                'version' => self::SERVER_VERSION,
            ],
            'instructions'    => $instructions,
        ]);
    }

    // ─── tools/list ────────────────────────────────────────────────────────────

    private function handleToolsList($id): array
    {
        $registry = new ToolRegistry();
        $tools    = $registry->buildList(
            $this->pluginSettings,
            $this->bettersearchActive,
            $this->askthedocsActive,
            $this->userrole,
            $this->container->get('acl'),
            $this->publicMode,
            $this->publicTools
        );

        return $this->successResponse($id, ['tools' => $tools]);
    }

    // ─── tools/call ────────────────────────────────────────────────────────────

    private function handleToolsCall($id, array $params): array
    {
        if (!isset($params['name']) || !is_string($params['name']) || $params['name'] === '') {
            return $this->errorResponse($id, -32602, 'name is required');
        }

        $name      = $params['name'];
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            $arguments = [];
        }

        $registry = new ToolRegistry();
        $tool     = $registry->findTool($name);

        if (!$tool) {
            return $this->errorResponse($id, -32602, 'unknown tool: ' . $name);
        }

        # ── Public-mode whitelist (overrides anything below for that mode) ──
        if ($this->publicMode) {
            $whitelist = is_array($this->publicTools) ? $this->publicTools : [];
            if (!in_array($name, $whitelist, true)) {
                return $this->toolCallError(
                    $id,
                    $name,
                    'Tool is not available on the public endpoint.'
                );
            }
            # Defense in depth: refuse drafts even if a future setting
            # accidentally exposes a tool that supports the argument.
            if ($name === 'get_page' && !empty($arguments['draft'])) {
                return $this->toolCallError(
                    $id,
                    $name,
                    'Draft mode requires authentication; use draft=false or omit it.'
                );
            }
            if ($name === 'list_pages' && (($arguments['scope'] ?? null) === 'draft')) {
                return $this->toolCallError(
                    $id,
                    $name,
                    'Draft listing requires authentication; use scope="live".'
                );
            }
        }

        # ── Toggles: read_tools / write_tools setting + required-plugin flags ──
        if (!$registry->toolIsAvailable($tool, $this->pluginSettings, $this->bettersearchActive, $this->askthedocsActive)) {
            # Per MCP spec we still return 200 with tool-level "protocol error"
            # shape so clients can render it cleanly. JSON-RPC error happens
            # only for broken envelopes, not for unavailable tools.
            return $this->toolCallError(
                $id,
                $name,
                'Tool is not available: disabled in plugin settings or a required companion plugin is inactive.'
            );
        }

        # ── Per-tool ACL: settings are not a security boundary. In public
        #    mode the registry already filtered to ACL=[public,read] so
        #    we skip the ACL check (would re-run with userrole=guest
        #    and produce the same result, but the whitelist gate above
        #    is what we actually rely on). ──
        if (!$this->publicMode) {
            $acl = $this->container->get('acl');
            [$resource, $privilege] = $tool['acl'];
            if (!$acl->isAllowed($this->userrole, $resource, $privilege)) {
                return $this->toolCallError(
                    $id,
                    $name,
                    'Permission denied. Your role "' . $this->userrole
                    . '" cannot "' . $privilege . '" on "' . $resource . '".'
                );
            }
        }

        # ── inputSchema validation (required + enums; sufficient for v1) ──
        $validationError = $registry->validateArguments($tool, $arguments);
        if ($validationError !== null) {
            return $this->errorResponse($id, -32602, $validationError);
        }

        # ── Dispatch to the actual tool class ──
        [$class, $method] = $tool['handler'];

        $handler = new $class(
            $this->container,
            $this->username,
            $this->userrole,
            $this->pluginSettings,
            $this->bettersearchActive,
            $this->askthedocsActive
        );

        try {
            $result = $handler->{$method}($arguments);
        } catch (\Throwable $e) {
            # Domain failure must NOT be a JSON-RPC error envelope per MCP spec —
            # we always return 200, with isError:true inside the tool result.
            return $this->toolCallError(
                $id,
                $name,
                'Internal tool error: ' . $e->getMessage()
            );
        }

        # Handler returns ['text' => string, 'isError' => bool?]
        $text    = is_string($result['text'] ?? null) ? $result['text'] : '';
        $isError = !empty($result['isError']);

        return $this->successResponse($id, [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'isError' => $isError,
        ]);
    }

    // ─── Response builders ─────────────────────────────────────────────────────

    private function successResponse($id, array|object $result): array
    {
        return [
            'httpStatus' => 200,
            'body' => [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            ],
        ];
    }

    private function errorResponse($id, int $code, string $message): array
    {
        return [
            'httpStatus' => 200,
            'body' => [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'error'   => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ],
        ];
    }

    /**
     * Domain-level tool failure: HTTP 200, JSON-RPC response with isError=true.
     */
    private function toolCallError($id, string $toolName, string $message): array
    {
        return [
            'httpStatus' => 200,
            'body' => [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => [
                    'content' => [
                        ['type' => 'text', 'text' => '[' . $toolName . '] ' . $message],
                    ],
                    'isError' => true,
                ],
            ],
        ];
    }
}
