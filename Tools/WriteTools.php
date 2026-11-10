<?php

namespace Plugins\mcp\Tools;

use DI\Container;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Typemill\Controllers\ControllerApiAuthorArticle;
use Typemill\Controllers\ControllerApiAuthorMeta;

/**
 * Writing tools that re-use the core API controllers via in-process
 * delegation. Each handler:
 *
 *   1. Normalises its tool arguments into a PSR-7 parsed body.
 *   2. Crafts an internal ServerRequest that carries the authenticated
 *      username/userrole as request attributes.
 *   3. Hands it to the matching core controller method.
 *   4. Maps the controller's JSON status + body back to the MCP result
 *      shape ({isError: bool, text: <json string>}).
 *
 * Validation, ownership checks, sitemap updates and event dispatching
 * (OnPagePublished, OnPageDeleted, ...) all happen inside the core
 * controller and bubble up via the response — we never bypass them.
 */
class WriteTools
{
    private Container $container;
    private string $username;
    private string $userrole;
    private array $pluginSettings;
    private bool $bettersearchActive;
    private bool $askthedocsActive;

    public function __construct(
        Container $container,
        string $username,
        string $userrole,
        array $pluginSettings,
        bool $bettersearchActive,
        bool $askthedocsActive
    ) {
        $this->container          = $container;
        $this->username           = $username;
        $this->userrole           = $userrole;
        $this->pluginSettings     = $pluginSettings;
        $this->bettersearchActive = $bettersearchActive;
        $this->askthedocsActive   = $askthedocsActive;
    }

    // ─── create_page ───────────────────────────────────────────────────────

    public function createPage(array $args): array
    {
        $parentUrl = $args['parent_url'] ?? '/';
        $name      = (string)($args['name'] ?? '');
        $type      = $args['type'] ?? 'file';
        $markdown  = $args['markdown'] ?? null;

        if ($name === '') {
            return ['text' => json_encode(['message' => 'name is required']), 'isError' => true];
        }

        # parent_url semantics: "/" means root → folder_id="root".
        # Anything else (folder path) → use parent_url directly.
        $params = [
            'item_name' => $name,
            'type'      => $type,
            'parent_url'=> '/' . trim($parentUrl, '/'),
        ];
        if (trim($parentUrl, '/') === '') {
            $params['folder_id'] = 'root';
            unset($params['parent_url']);
        }

        # Use _decoded() so we can read item/urlRelWoF from the
        # controller response without re-parsing the JSON text payload.
        $result = $this->dispatchArticle('createArticle', $params);

        if (!empty($result['isError'])) {
            return $this->stripDecoded($result);
        }

        # If the caller supplied initial markdown, follow up with update_page
        # semantics so the new page content is set as a draft.
        if (is_string($markdown) && $markdown !== '' && !empty($result['_decoded']['item'])) {
            $newItem = $result['_decoded']['item'];
            # The controller returns `item` as a decoded assoc array.
            $url = is_array($newItem) ? ($newItem['urlRelWoF'] ?? $newItem['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                $update = $this->updatePage(['url' => $url, 'markdown' => $markdown]);
                if (!empty($update['isError'])) {
                    # The page exists but the markdown follow-up failed.
                    # Surface the create-success info and the write error.
                    $decoded = $result['_decoded'] ?? [];
                    $decoded['markdown_write_error'] = json_decode($update['text'] ?? '{}', true);
                    $result['text'] = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }
        }

        return $this->stripDecoded($result);
    }

    /**
     * Strip internal _decoded payload from a result before it reaches the
     * MCP transport. The MCP layer only cares about text and isError.
     */
    private function stripDecoded(array $result): array
    {
        if (array_key_exists('_decoded', $result)) {
            unset($result['_decoded']);
        }
        return $result;
    }

    // ─── update_page ───────────────────────────────────────────────────────

    public function updatePage(array $args): array
    {
        $url      = (string)($args['url'] ?? '');
        $markdown = (string)($args['markdown'] ?? '');

        if ($url === '' || $markdown === '') {
            return ['text' => json_encode(['message' => 'url and markdown are required']), 'isError' => true];
        }

        $params = [
            'url'      => $url,
            'markdown' => $markdown,
        ];

        return $this->stripDecoded($this->dispatchArticle('updateDraft', $params));
    }

    // ─── publish_page ──────────────────────────────────────────────────────

    public function publishPage(array $args): array
    {
        $url = (string)($args['url'] ?? '');
        if ($url === '') {
            return ['text' => json_encode(['message' => 'url is required']), 'isError' => true];
        }

        # publishArticle expects {url, item_id}; resolve item_id from the URL.
        $itemId = $this->resolveItemKeyPath($url);
        if ($itemId === null) {
            return ['text' => json_encode(['message' => 'page not found: ' . $url]), 'isError' => true];
        }

        return $this->stripDecoded($this->dispatchArticle('publishArticle', [
            'url'     => $url,
            'item_id' => $itemId,
        ]));
    }

    // ─── unpublish_page ────────────────────────────────────────────────────

    public function unpublishPage(array $args): array
    {
        $url = (string)($args['url'] ?? '');
        if ($url === '') {
            return ['text' => json_encode(['message' => 'url is required']), 'isError' => true];
        }
        $itemId = $this->resolveItemKeyPath($url);
        if ($itemId === null) {
            return ['text' => json_encode(['message' => 'page not found: ' . $url]), 'isError' => true];
        }

        return $this->stripDecoded($this->dispatchArticle('unpublishArticle', [
            'url'     => $url,
            'item_id' => $itemId,
        ]));
    }

    // ─── delete_page ───────────────────────────────────────────────────────

    public function deletePage(array $args): array
    {
        $url = (string)($args['url'] ?? '');
        if ($url === '') {
            return ['text' => json_encode(['message' => 'url is required']), 'isError' => true];
        }

        # deleteArticle requires url and item_id. The controller validates it.
        $itemId = $this->resolveItemKeyPath($url);
        if ($itemId === null) {
            return ['text' => json_encode(['message' => 'page not found: ' . $url]), 'isError' => true];
        }

        return $this->stripDecoded($this->dispatchArticle('deleteArticle', [
            'url'     => $url,
            'item_id' => $itemId,
        ]));
    }

    // ─── update_page_metadata ──────────────────────────────────────────────

    public function updatePageMetadata(array $args): array
    {
        $url  = (string)($args['url']  ?? '');
        $tab  = (string)($args['tab']  ?? '');
        $data = $args['data'] ?? null;

        if ($url === '' || $tab === '' || !is_array($data)) {
            return ['text' => json_encode(['message' => 'url, tab and data are required']), 'isError' => true];
        }

        # If "data" was JSON-encoded into a string by an overly clever client,
        # decode it here so callers don't have to send nested objects as strings.
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                return ['text' => json_encode(['message' => 'data must be a JSON object']), 'isError' => true];
            }
        }

        return $this->stripDecoded($this->dispatchMeta('updateMeta', [
            'url'  => $url,
            'tab'  => $tab,
            'data' => $data,
        ]));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    /**
     * Build an internal ServerRequest carrying the user identity, and
     * let ControllerApiAuthorArticle::method() handle it. The controller's
     * constructor dispatches OnTwigLoaded(false) — cheap and harmless.
     *
     * @return array{text:string,isError?:bool}
     */
    private function dispatchArticle(string $method, array $params): array
    {
        return $this->dispatchController(
            ControllerApiAuthorArticle::class,
            $method,
            $params
        );
    }

    private function dispatchMeta(string $method, array $params): array
    {
        return $this->dispatchController(
            ControllerApiAuthorMeta::class,
            $method,
            $params
        );
    }

    /**
     * Run a controller method with an internal PSR-7 request and gather
     * the JSON response. Any 2xx → success; any 4xx/5xx → isError:true
     * with the controller's message in the tool result.
     *
     * The 'text' field is always a JSON-encoded string so the MCP layer
     * can hand it back unchanged. Callers that need the structured fields
     * ("item", "metadata", "navigation") should use _decoded() on the
     * result array.
     *
     * @param class-string $controllerClass
     */
    private function dispatchController(string $controllerClass, string $method, array $params): array
    {
        $factory = new ServerRequestFactory();

        # We let the internal "URL" be anything — the controller doesn't
        # touch Slim routing here (RouteContext would, but core controllers
        # never call it). A stable synthetic path keeps logs consistent.
        $request = $factory->createServerRequest('POST', '/mcp/internal')
            ->withParsedBody($params)
            ->withAttribute('c_username', $this->username)
            ->withAttribute('c_userrole', $this->userrole);

        $response = new Psr7Response();

        $controller = new $controllerClass($this->container);

        try {
            $out = $controller->{$method}($request, $response, []);
        } catch (\Throwable $e) {
            return [
                'text'    => json_encode(['message' => 'controller failure: ' . $e->getMessage()]),
                'isError' => true,
                '_decoded' => null,
            ];
        }

        $status = $out->getStatusCode();
        $raw    = (string)$out->getBody();
        $data   = json_decode($raw, true);

        # Treat 2xx as success. Pass the controller's JSON through verbatim
        # so "item", "metadata", "navigation" round-trip cleanly to the
        # AI client (where it can read the new urlRelWoF / pageid).
        if ($status >= 200 && $status < 300) {
            $payload = is_array($data) ? $data : ['message' => 'ok'];
            return [
                'text'     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                '_decoded' => $payload,
            ];
        }

        # 4xx/5xx: extract the controller's message, fall back to the raw body.
        $message = '';
        if (is_array($data) && !empty($data['message'])) {
            $message = (string)$data['message'];
        } elseif (is_string($raw) && $raw !== '') {
            $message = $raw;
        } else {
            $message = 'controller reported status ' . $status;
        }

        return [
            'text'    => json_encode(['message' => $message, 'status' => $status]),
            'isError' => true,
            '_decoded' => is_array($data) ? $data : null,
        ];
    }

    /**
     * Resolve a page URL into the keypath string expected by the
     * ControllerApiAuthorArticle methods ({item_id: "0.2.1", etc.}). Returns
     * null if the URL does not resolve to a navigable item.
     */
    private function resolveItemKeyPath(string $url): ?string
    {
        $urlinfo    = $this->container->get('urlinfo');
        $langattr   = $this->container->get('settings')['langattr'] ?? 'en';

        $navigation = new \Typemill\Models\Navigation();
        $navigation->setProject($this->container->get('settings'), $url, $this->container->get('dispatcher'));

        $item = $navigation->getItemForUrl($url, $urlinfo, $langattr);
        if (!$item || !isset($item->keyPathArray) || !is_array($item->keyPathArray) || empty($item->keyPathArray)) {
            return null;
        }

        return implode('.', array_map('strval', $item->keyPathArray));
    }
}
