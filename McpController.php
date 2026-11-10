<?php

namespace Plugins\mcp;

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Single-entry HTTP handler for the MCP endpoint.
 *
 * Responsibilities (this class is intentionally thin):
 *   1. Bounce back when the plugin is disabled (404).
 *   2. Optional Origin-header host check (DNS-rebinding protection).
 *   3. Verify the JSON envelope can be parsed at all (-32700 otherwise).
 *   4. Hand the decoded body to McpProtocol::dispatch().
 *   5. Serialise the protocol result back as JSON over HTTP.
 *
 * All real permission / domain logic lives in McpProtocol / ToolRegistry /
 * the Tools/* classes. The controller MUST NOT access the filesystem or
 * core models directly.
 */
class McpController
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function handle(Request $request, Response $response, $args): Response
    {
        # ─── Plugin on/off ──────────────────────────────────────────────────
        $pluginSettings = $this->container->get('settings')['plugins']['mcp'] ?? [];

        if (empty($pluginSettings['active'])) {
            # Soft 404: do not advertise the endpoint.
            return $this->jsonResponse($response, 404, ['message' => 'Not Found']);
        }

        # ─── Origin host check (DNS-rebinding per MCP spec) ─────────────────
        if (!$this->originAllowed($request, $pluginSettings['allow_origin'] ?? '', $this->container->get('urlinfo'))) {
            return $this->jsonResponse($response, 403, ['message' => 'Origin not allowed']);
        }

        # ─── Body ───────────────────────────────────────────────────────────
        # We accept both Content-Type: application/json and the raw body that
        # may already be parsed by Slim's built-in body parsing middleware.
        $bodyText = (string)$request->getBody();
        if ($bodyText === '') {
            # Empty body = no call, but the spec prescribes a parse error.
            return $this->transportError(-32700, 'empty body');
        }

        $parsed = json_decode($bodyText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->transportError(-32700, 'parse error: ' . json_last_error_msg());
        }

        # ─── Identity (already populated by ApiAuthentication in middleware) ──
        $username = (string)$request->getAttribute('c_username', '');
        $userrole = (string)$request->getAttribute('c_userrole', '');

        if ($username === '' || $userrole === '') {
            # Should never happen because we sit behind ApiAuthentication,
            # but be defensive: request handling must be auditable.
            return $this->jsonResponse($response, 401, ['message' => 'unauthenticated']);
        }

        # ─── Plugin activity flags for tool registration ────────────────────
        $bettersearchActive = $this->isPluginActive('bettersearch');
        $askthedocsActive   = $this->isPluginActive('askthedocs');

        # ─── Dispatch to the protocol layer ─────────────────────────────────
        $protocol = new McpProtocol(
            $this->container,
            $username,
            $userrole,
            $pluginSettings,
            $bettersearchActive,
            $askthedocsActive,
            publicMode: false,
            publicTools: null
        );

        $result = $protocol->dispatch($parsed);

        # ─── Notifications: HTTP 202, empty body ────────────────────────────
        $httpStatus = (int)($result['httpStatus'] ?? 200);
        $body       = $result['body'] ?? null;

        if ($httpStatus === 202 || $body === null) {
            return $response->withStatus(202);
        }

        return $this->jsonResponse($response, $httpStatus, $body);
    }

    /**
     * Public, no-auth read-only transport. Unlike handle(), this entry
     * point has no Authentication middleware in front of it (because the
     * route is registered WITHOUT resource+privilege). It therefore:
     *
     *   1. Gates the entire path on `public_endpoint` plugin setting.
     *   2. Runs the same Origin / body / parse pipeline as handle().
     *   3. Enforces a per-request payload cap (configurable) so an
     *      attacker cannot drive memory through the JSON decode.
     *   4. Calls McpProtocol with username='', userrole='guest', the
     *      whitelist of public_tools, and publicMode=true. The protocol
     *      + tool registry refuse any tool/argument not on that list.
     *
     * Reading the published site tree / search index / page content /
     * metadata is what the public web frontend already exposes — this
     * endpoint just packages it for AI clients. Drafts and writes are
     * always rejected regardless of any future setting.
     */
    public function handlePublic(Request $request, Response $response, $args): Response
    {
        $pluginSettings = $this->container->get('settings')['plugins']['mcp'] ?? [];

        if (empty($pluginSettings['public_endpoint'])) {
            # Behaviour matches handle() when off: do not advertise.
            return $this->jsonResponse($response, 404, ['message' => 'Not Found']);
        }

        # The public endpoint is meant for browser-based AI clients that
        # always send Origin, so enforcing it here is the strongest
        # defence we have against someone on a hostile origin abusing
        # the no-auth route.
        if (!$this->originAllowed($request, $pluginSettings['public_origin'] ?? '', $this->container->get('urlinfo'))) {
            return $this->jsonResponse($response, 403, ['message' => 'Origin not allowed']);
        }

        # Per-request payload cap. Defaults to 64KB; configured per
        # settings.public_max_payload_kb. Sits BEFORE the JSON decode
        # so a hostile large body never reaches the parser.
        $maxKb   = (int)($pluginSettings['public_max_payload_kb'] ?? 64);
        $maxKb   = max(8, min(512, $maxKb)); // 8 KiB .. 512 KiB
        $maxByt  = $maxKb * 1024;

        $bodyText = (string)$request->getBody();
        if ($bodyText === '') {
            return $this->transportError(-32700, 'empty body');
        }
        if (strlen($bodyText) > $maxByt) {
            return $this->transportError(-32600, 'payload too large (limit ' . $maxKb . ' KiB)');
        }

        $parsed = json_decode($bodyText, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->transportError(-32700, 'parse error: ' . json_last_error_msg());
        }

        # Anonymous identity. guest inherits public:read but no other
        # privilege in the standard ACL setup, which is exactly the
        # surface we want exposed.
        $username = '';
        $userrole = 'guest';

        $bettersearchActive = $this->isPluginActive('bettersearch');
        $askthedocsActive   = $this->isPluginActive('askthedocs');

        # Whitelist: operator-curated set of tools available in public
        # mode. Each must have ACL [public, read] in the catalogue.
        # Default: the read+search tools everyone reasonably expects.
        $publicTools = $this->resolvePublicTools($pluginSettings);

        $protocol = new McpProtocol(
            $this->container,
            $username,
            $userrole,
            $pluginSettings,
            $bettersearchActive,
            $askthedocsActive,
            publicMode: true,
            publicTools: $publicTools
        );

        $result = $protocol->dispatch($parsed);

        $httpStatus = (int)($result['httpStatus'] ?? 200);
        $body       = $result['body'] ?? null;

        if ($httpStatus === 202 || $body === null) {
            return $response->withStatus(202);
        }

        return $this->jsonResponse($response, $httpStatus, $body);
    }

    /**
     * Resolve the public-tools whitelist from plugin settings.
     *
     * The operator can override the default list in YAML. We always
     * pre-filter to tools whose catalogue ACL is [public, read] so
     * an accidental override cannot expose write tools.
     */
    private function resolvePublicTools(array $pluginSettings): array
    {
        $configured = $pluginSettings['public_tools'] ?? null;
        if (!is_array($configured) || empty($configured)) {
            $configured = [
                'get_site_info',
                'list_pages',
                'find_page',
                'get_page',
                'get_page_metadata',
                'search_pages',
            ];
        }

        $clean = [];
        foreach ($configured as $name) {
            if (!is_string($name)) {
                continue;
            }
            $clean[] = $name;
        }
        return array_values(array_unique($clean));
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * Return a low-level transport-format error as plain JSON + HTTP 400.
     * Used when we cannot even parse the request envelope.
     * Matches the JSON-RPC transport "Parse error" shape, but uses HTTP 400
     * so badly-formed envelopes are easy to spot in logs.
     */
    private function transportError(int $code, string $message): Response
    {
        $response = new Psr7Response();
        $payload  = json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    /**
     * Serialise a structured response as JSON with the right Content-Type.
     */
    private function jsonResponse(Response $response, int $status, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Origin-header host check.
     *
     * Strategy:
     *   - If `allowOrigin` (passed in) is configured, the Origin header
     *     MUST equal it (case-insensitive host + scheme match).
     *   - Otherwise, if the request sends an Origin header, its host
     *     component must match the site's host (urlinfo.baseurl).
     *     Same-origin requests from local tooling (no Origin header)
     *     are always allowed.
     *   - Tolerate the MCP-Protocol-Version header without enforcing it.
     *
     * Returns true when the request is allowed to proceed.
     */
    private function originAllowed(Request $request, string $allowOrigin, array $urlinfo): bool
    {
        $originHeader = $request->getHeaderLine('Origin');
        if ($originHeader === '') {
            return true;
        }

        $listed = trim($allowOrigin);
        if ($listed !== '') {
            return strcasecmp($listed, $originHeader) === 0;
        }

        $siteUrl = rtrim((string)($urlinfo['baseurl'] ?? ''), '/');

        if ($siteUrl === '') {
            return false;
        }

        $siteHost   = parse_url($siteUrl, PHP_URL_HOST);
        $originHost = parse_url($originHeader, PHP_URL_HOST);

        if (!is_string($siteHost) || !is_string($originHost)) {
            return false;
        }

        return strcasecmp($siteHost, $originHost) === 0;
    }

    /**
     * Detect whether a sister plugin is currently active.
     * Relies on the system.yaml plugin setting, which system.php keeps in
     * sync with the discoverable plugin list.
     */
    private function isPluginActive(string $name): bool
    {
        $plugins = $this->container->get('settings')['plugins'] ?? [];
        return !empty($plugins[$name]['active']);
    }
}
