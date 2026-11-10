<?php

namespace Plugins\mcp\Tools;

use DI\Container;
use Typemill\Models\Navigation;
use Typemill\Models\Content;
use Typemill\Models\Meta;
use Typemill\Models\StorageWrapper;

/**
 * Read-only tools.
 *
 * These deliberately never mutate content. They go straight to the
 * core models (Navigation / Content / Meta / Settings) so the AI
 * client sees exactly the same data as the public web frontend.
 *
 * All handlers return ['text' => string, 'isError' => bool]. The
 * text is always a JSON-encoded string so a model can read structured
 * data without parsing individual content blocks.
 */
class ReadTools
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

    // ─── get_site_info ─────────────────────────────────────────────────────

    public function getSiteInfo(array $args): array
    {
        $settings = $this->container->get('settings');
        $urlinfo  = $this->container->get('urlinfo');

        # Strict whitelist. Do not leak storage paths, themes, ai config, etc.
        $info = [
            'title'     => (string)($settings['title']     ?? ''),
            'author'    => (string)($settings['author']    ?? ''),
            'copyright' => (string)($settings['copyright'] ?? ''),
            'language'  => (string)($settings['langattr']  ?? 'en'),
            'baseurl'   => (string)($urlinfo['baseurl']    ?? ''),
        ];

        return ['text' => json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // ─── list_pages ────────────────────────────────────────────────────────

    public function listPages(array $args): array
    {
        $scope = $args['scope'] ?? 'live';
        $url   = $args['url']   ?? '/';

        if (!in_array($scope, ['live', 'draft'], true)) {
            return ['text' => json_encode(['message' => 'invalid scope']), 'isError' => true];
        }

        $url     = $this->normalizeUrl($url);
        $urlinfo = $this->container->get('urlinfo');
        $langattr = $this->container->get('settings')['langattr'] ?? 'en';

        $navigation = new Navigation();
        $navigation->setProject($this->container->get('settings'), $url, $this->container->get('dispatcher'));

        if ($scope === 'draft') {
            # ACL already enforced in the protocol layer, but be defensive.
            if (!$this->container->get('acl')->isAllowed($this->userrole, 'content', 'read')) {
                return ['text' => json_encode(['message' => 'permission denied for draft scope']), 'isError' => true];
            }
            $items = $navigation->getFullDraftNavigation($urlinfo, $langattr, $this->userrole, $this->username);
        } else {
            $items = $navigation->getLiveNavigation($urlinfo, $langattr);
        }

        if (!is_array($items)) {
            $items = [];
        }

        $summaries = $this->askthedocsActive ? $this->loadAskthedocsSummaries() : [];

        $tree = $this->mapTree($items, $summaries);

        return ['text' => json_encode(['scope' => $scope, 'pages' => $tree], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // ─── find_page ─────────────────────────────────────────────────────────

    public function findPage(array $args): array
    {
        $urlinfo  = $this->container->get('urlinfo');
        $langattr = $this->container->get('settings')['langattr'] ?? 'en';
        $navigation = new Navigation();
        $navigation->setProject($this->container->get('settings'), '/', $this->container->get('dispatcher'));

        if (!empty($args['url'])) {
            $url = $this->normalizeUrl((string)$args['url']);
            $item = $navigation->getItemForUrl($url, $urlinfo, $langattr);
            if (!$item) {
                return ['text' => json_encode(['message' => 'page not found', 'url' => $url]), 'isError' => true];
            }
            return ['text' => json_encode(['matches' => [$this->mapItem($item)]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }

        if (!empty($args['slug'])) {
            $slug  = (string)$args['slug'];
            $items = $navigation->getItemsForSlug($slug, $urlinfo, $langattr);
            if (!is_array($items) || empty($items)) {
                return ['text' => json_encode(['message' => 'no page with slug "' . $slug . '"']), 'isError' => true];
            }
            $mapped = array_map([$this, 'mapItem'], $items);
            return ['text' => json_encode(['matches' => $mapped, 'note' => 'slug may match multiple pages'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }

        return ['text' => json_encode(['message' => 'either url or slug is required']), 'isError' => true];
    }

    // ─── get_page ──────────────────────────────────────────────────────────

    public function getPage(array $args): array
    {
        if (empty($args['url'])) {
            return ['text' => json_encode(['message' => 'url is required']), 'isError' => true];
        }
        $url   = $this->normalizeUrl((string)$args['url']);
        $draft = !empty($args['draft']);

        $urlinfo  = $this->container->get('urlinfo');
        $langattr = $this->container->get('settings')['langattr'] ?? 'en';

        $navigation = new Navigation();
        $navigation->setProject($this->container->get('settings'), $url, $this->container->get('dispatcher'));

        $item = $navigation->getItemForUrl($url, $urlinfo, $langattr);
        if (!$item) {
            return ['text' => json_encode(['message' => 'page not found', 'url' => $url]), 'isError' => true];
        }

        $content = new Content($urlinfo['baseurl'], $this->container->get('settings'), $this->container->get('dispatcher'));

        if ($draft) {
            if (!$this->container->get('acl')->isAllowed($this->userrole, 'mycontent', 'read')) {
                return ['text' => json_encode(['message' => 'permission denied for draft']), 'isError' => true];
            }
            $markdownArray = $content->getDraftMarkdown($item);
            $markdown = (is_array($markdownArray) && !empty($markdownArray))
                ? $content->markdownArrayToText($markdownArray)
                : (is_string($markdownArray) ? $markdownArray : '');
        } else {
            $markdown = $content->getLiveMarkdown($item) ?? '';
        }

        return ['text' => json_encode([
            'url'      => $item->urlRelWoF ?? $url,
            'title'    => $item->name ?? '',
            'status'   => $item->status ?? null,
            'type'     => $item->elementType ?? null,
            'markdown' => $markdown,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // ─── get_page_metadata ─────────────────────────────────────────────────

    public function getPageMetadata(array $args): array
    {
        if (empty($args['url'])) {
            return ['text' => json_encode(['message' => 'url is required']), 'isError' => true];
        }
        $url = $this->normalizeUrl((string)$args['url']);

        $urlinfo  = $this->container->get('urlinfo');
        $langattr = $this->container->get('settings')['langattr'] ?? 'en';

        $navigation = new Navigation();
        $navigation->setProject($this->container->get('settings'), $url, $this->container->get('dispatcher'));

        $item = $navigation->getItemForUrl($url, $urlinfo, $langattr);
        if (!$item) {
            return ['text' => json_encode(['message' => 'page not found', 'url' => $url]), 'isError' => true];
        }

        $meta     = new Meta();
        $metadata = $meta->getMetaData($item);
        $metadata = $meta->addMetaDefaults($metadata, $item, $this->container->get('settings')['author'] ?? '', $this->username);

        return ['text' => json_encode([
            'url'      => $item->urlRelWoF ?? $url,
            'metadata' => $metadata,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /**
     * Strip null bytes and `..` before passing to Navigation. Belt-and-braces.
     */
    private function normalizeUrl(string $url): string
    {
        $url = str_replace("\0", '', $url);
        if (strpos($url, '..') !== false) {
            # Don't let `..` reach Navigation. Core also checks, but we
            # refuse to even try.
            return '/__invalid__';
        }
        if ($url === '') {
            return '/';
        }
        return '/' . ltrim($url, '/');
    }

    /**
     * Convert a navigation item (object from Navigation model) into a
     * safe, ACL-friendly array for an AI client.
     * Never expose file paths or internal bookkeeping fields.
     */
    private function mapItem(object $item): array
    {
        $mapped = [
            'title'  => $item->name ?? '',
            'url'    => $item->urlRelWoF ?? '',
            'type'   => $item->elementType ?? 'file',
            'status' => $item->status ?? null,
        ];
        return $mapped;
    }

    /**
     * Recursive mapper for list_pages. Adds askthedocs descriptions
     * (full text is not exposed; the navigation item title+url is enough
     * for an AI to decide which source page to read next).
     */
    private function mapTree(array $items, array $summaries): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }
            $node = $this->mapItem($item);
            $path = (string)($item->urlRelWoF ?? '');
            if ($path !== '' && !empty($summaries[$path]['summary'])) {
                $node['description'] = (string)$summaries[$path]['summary'];
            }
            if (isset($item->folderContent) && is_array($item->folderContent) && !empty($item->folderContent)) {
                $children = $this->mapTree($item->folderContent, $summaries);
                if (!empty($children)) {
                    $node['children'] = $children;
                }
            }
            $out[] = $node;
        }
        return $out;
    }

    /**
     * Read /data/askthedocs/summaries.json and return only the entries
     * that have a non-empty `summary`. Strips the `built` meta key.
     */
    private function loadAskthedocsSummaries(): array
    {
        $storage = new StorageWrapper($this->container->get('settings')['storage']);
        $raw = $storage->getFile('dataFolder', 'askthedocs', 'summaries.json');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        unset($data['built']);
        return array_map(
            fn($entry) => ['summary' => (string)($entry['summary'] ?? ''), 'title' => (string)($entry['title'] ?? '')],
            $data
        );
    }
}
