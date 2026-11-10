<?php

namespace Plugins\mcp\Tools;

use DI\Container;
use Typemill\Models\Navigation;
use Typemill\Models\StorageWrapper;

/**
 * Server-side full-text search over the BETTERSEARCH plugin's index file.
 *
 * The upstream BETTERSEARCH plugin regenerates its index lazily — but
 * on Publish/Unpublish/Sort/Delete it deletes the index file. We rebuild
 * the file ourselves on a cache miss using the same code path as
 * plugins/bettersearch/SearchController::createIndex(), adapted to honour
 * an optional current project so /data/bettersearch/index_<project>.json
 * also gets rebuilt when an MCP client searches a non-default project.
 *
 * Scoring is intentionally naive: substring match counts. Title hits are
 * weighted 5× body hits. The MCP client (itself an AI) is expected to
 * walk the top hits with get_page; this tool only narrows the search.
 */
class SearchTool
{
    private Container $container;
    private string $username;
    private string $userrole;
    private array $pluginSettings;
    private bool $bettersearchActive;
    private bool $askthedocsActive;

    /** Project context for the current request, set on the first search. */
    private ?string $project = null;

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

    public function searchPages(array $args): array
    {
        if (!$this->bettersearchActive) {
            return ['text' => json_encode(['message' => 'search_pages requires BETTERSEARCH to be active']), 'isError' => true];
        }
        if (empty($args['query']) || !is_string($args['query'])) {
            return ['text' => json_encode(['message' => 'query is required']), 'isError' => true];
        }

        $query   = (string)$args['query'];
        $limit   = isset($args['limit']) ? max(1, min(50, (int)$args['limit'])) : 10;
        $project = isset($args['project']) ? strtolower((string)$args['project']) : null;

        $this->project = $project;

        $storage = new StorageWrapper($this->container->get('settings')['storage']);

        $index = $this->loadOrRebuildIndex($storage);

        # Score + sort + cap
        $results   = $this->scoreIndex($index, $query);
        $topResult = array_slice($results, 0, $limit);

        return ['text' => json_encode([
            'query'   => $query,
            'count'   => count($topResult),
            'results' => $topResult,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // ─── Index loading / rebuild ─────────────────────────────────────────

    /**
     * Load the index.json (or per-project variant), rebuild if missing,
     * and (if FULL INDEX is enabled in BETTERSEARCH settings) merge
     * every project index into a single in-memory map.
     *
     * @return array<string, array>  map keyed by absolute URL.
     */
    private function loadOrRebuildIndex(StorageWrapper $storage): array
    {
        if (!$this->isFullIndexEnabled() && !$this->project) {
            # Standard single-index mode for the active project.
            $indexName = $this->project ? ('index_' . $this->project) : 'index';
            $index = $this->loadSingle($storage, $indexName);
            if ($index === null) {
                $index = $this->buildIndex($storage);
                $this->persistIndex($storage, $indexName, $index);
            }
            return $index;
        }

        # Cross-project mode. Build/load base + every project, merge by URL.
        $merged = [];

        if ($this->isFullIndexEnabled()) {
            $base = $this->loadSingle($storage, 'index');
            if ($base === null) {
                $base = $this->buildIndex($storage);
                $this->persistIndex($storage, 'index', $base);
            }
            # The base project's entries keep their own project id as a
            # defacto-marked filter so clients can tell them apart.
            $merged = array_merge($merged, $base);
        }

        # Walk all project instances.
        $settings   = $this->container->get('settings');
        $navigation = new Navigation();
        $projects   = $navigation->getAllProjects($settings);
        if (is_array($projects)) {
            foreach ($projects as $proj) {
                if (empty($proj['id'])) {
                    continue;
                }
                $pid = strtolower((string)$proj['id']);
                if (!empty($proj['base'])) {
                    continue; # already covered by base index above
                }
                $indexName = 'index_' . $pid;
                $index = $this->loadSingle($storage, $indexName);
                if ($index === null) {
                    $index = $this->buildProjectIndex($storage, $pid);
                    $this->persistIndex($storage, $indexName, $index);
                }
                # Tag all entries with the project for filtering, matching
                # what SearchController::addProjectFolderToIndex does.
                foreach ($index as $key => $entry) {
                    $index[$key]['filtername'] = (string)($proj['label'] ?? $pid);
                    $index[$key]['filterpath'] = '/' . $pid . '/';
                }
                $merged = array_merge($merged, $index);
            }
        }

        return $merged;
    }

    /**
     * Read a single index file from disk and JSON-decode it. Returns
     * null if missing or invalid so the caller can rebuild.
     */
    private function loadSingle(StorageWrapper $storage, string $name): ?array
    {
        $raw = $storage->getFile('dataFolder', 'bettersearch', $name . '.json');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * Persist a single index file. Silent on success (consistent with
     * the upstream BETTERSEARCH behaviour); the caller treats any
     * non-truthy result as a transient cache miss that will be
     * regenerated on the next call.
     */
    private function persistIndex(StorageWrapper $storage, string $name, array $index): bool
    {
        if (empty($index)) {
            return false;
        }
        $payload = json_encode($index, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return (bool)$storage->writeFile('dataFolder', 'bettersearch', $name . '.json', $payload);
    }

    /**
     * Is the BETTERSEARCH "Cross Project Search" toggle on?
     */
    private function isFullIndexEnabled(): bool
    {
        $bettersearch = $this->container->get('settings')['plugins']['bettersearch'] ?? [];
        return !empty($bettersearch['fullindex']);
    }

    /**
     * Build (or rebuild) the index for the currently selected project.
     * Mirrors plugins/bettersearch/SearchController::createIndex,
     * adapted to honour $this->project so a project-scoped rebuild
     * writes the right index_<project>.json file.
     */
    private function buildIndex(StorageWrapper $storage): array
    {
        if ($this->project) {
            return $this->buildProjectIndex($storage, $this->project);
        }

        $settings   = $this->container->get('settings');
        $urlinfo    = $this->container->get('urlinfo');
        $langattr   = $settings['langattr'] ?? 'en';

        $navigation = new Navigation();
        $liveNavigation = $navigation->getLiveNavigation($urlinfo, $langattr);
        if (!is_array($liveNavigation)) {
            return [];
        }

        $index = $this->collectForNavigation($liveNavigation, $storage, $urlinfo);

        # The home page (index.md at the project root) is not part of the
        # draft navigation tree but BETTERSEARCH still indexes it.
        $home = $storage->getFile('contentFolder', '', 'index.md');
        if (is_string($home) && $home !== '') {
            $entry = $this->pageEntry($home, $urlinfo['baseurl'] ?? '', null);
            if ($entry !== null) {
                $index[$entry['url']] = $entry;
            }
        }

        return $index;
    }

    /**
     * Build the index for a specific project id.
     */
    private function buildProjectIndex(StorageWrapper $storage, string $projectId): array
    {
        $settings = $this->container->get('settings');
        $urlinfo  = $this->container->get('urlinfo');
        $langattr = $settings['langattr'] ?? 'en';

        $navigation = new Navigation();
        $navigation->setProject($settings, '/' . $projectId . '/', $this->container->get('dispatcher'));

        $liveNavigation = $navigation->getLiveNavigation($urlinfo, $langattr);
        if (!is_array($liveNavigation)) {
            return [];
        }

        $base = rtrim((string)($urlinfo['baseurl'] ?? ''), '/');
        $urlAbs = $base . '/' . $projectId;
        $folder = '_' . $projectId;

        $index = [];
        $home = $storage->getFile('contentFolder', $folder, 'index.md');
        if (is_string($home) && $home !== '') {
            $entry = $this->pageEntry($home, $urlAbs, null);
            if ($entry !== null) {
                $index[$entry['url']] = $entry;
            }
        }
        $index = array_merge($index, $this->collectForNavigation($liveNavigation, $storage, $urlinfo, $projectId, $urlAbs));

        return $index;
    }

    /**
     * Walk a liveNavigation array, reading each .md file from the
     * content folder and building content-stripped index entries.
     *
     * @return array<string, array>
     */
    private function collectForNavigation(
        array $navigation,
        StorageWrapper $storage,
        array $urlinfo,
        ?string $projectId = null,
        ?string $urlAbsOverride = null
    ): array {
        $index = [];

        foreach ($navigation as $item) {
            if (!is_object($item)) {
                continue;
            }

            # Decode top-level folder for a "section" tag, like BETTERSEARCH does.
            $firstLevel = null;
            if (
                ($item->elementType ?? '') === 'folder'
                && isset($item->keyPathArray)
                && is_array($item->keyPathArray)
                && count($item->keyPathArray) === 1
            ) {
                $firstLevel = [
                    'name' => $item->name ?? '',
                    'path' => $item->pathWithoutType ?? '',
                ];
            }

            if (($item->fileType ?? '') === 'md') {
                $md = $storage->getFile('contentFolder', '', $item->pathWithoutType . '.md');
                if (is_string($md) && $md !== '') {
                    $urlAbs = $urlAbsOverride ?: ($item->urlAbs ?? ($urlinfo['baseurl'] ?? ''));
                    $entry = $this->pageEntry($md, $urlAbs, $firstLevel);
                    if ($entry !== null) {
                        $index[$entry['url']] = $entry;
                    }
                }
            }

            if (($item->elementType ?? '') === 'folder' && !empty($item->folderContent) && is_array($item->folderContent)) {
                $index = array_merge(
                    $index,
                    $this->collectForNavigation($item->folderContent, $storage, $urlinfo, $projectId, $urlAbsOverride)
                );
            }
        }

        return $index;
    }

    /**
     * Build a single page index entry: title + body (markdown stripped).
     * Modeled on plugins/bettersearch/SearchController::getPageContentArray
     * without reusing the private method directly.
     */
    private function pageEntry(string $raw, string $urlAbs, ?array $firstLevel): ?array
    {
        $parts   = explode("\n", $raw, 2);
        $title   = trim($parts[0] ?? '', "# \t\r");
        $title   = str_replace(["\r\n", "\n", "\r"], ' ', $title);
        $content = $parts[1] ?? '';
        $content = preg_replace('/^\s*$(?:\r\n?|\n)/m', '', $content) ?? '';
        $content = str_replace(["\r\n", "\n", "\r"], ' ', $content);
        $content = $this->stripMarkdown($content);

        if ($title === '' && $content === '') {
            return null;
        }

        $entry = [
            'title'   => $title,
            'content' => $content,
            'url'     => $urlAbs,
        ];
        if ($firstLevel !== null) {
            $entry['filtername'] = $firstLevel['name'] ?? '';
            $entry['filterpath'] = $firstLevel['path'] ?? '';
        } else {
            $entry['filtername'] = '';
            $entry['filterpath'] = '';
        }
        return $entry;
    }

    /**
     * Markdown stripping pipeline copied from
     * plugins/bettersearch/SearchController::strip_markdown() so
     * the rebuilt index has the exact same shape as the upstream
     * one (right down to the regex sequence and `if(!$content){return false;}`
     * guards). Private there — we replicate it rather than try to call it.
     */
    private function stripMarkdown(string $content): string
    {
        $content = str_replace('[TOC]', '', $content);

        $content = preg_replace('/\[:.*:\]/m', '', $content) ?? '';
        $content = preg_replace('/^(-\s*?|\*\s*?|_\s*?){3,}\s*$/m', '', $content) ?? '';
        $content = preg_replace('/^([\s\t]*)([\*\-\+]|\d+\.)\s+/', '', $content) ?? '';
        $content = preg_replace('/~{3}.*\n/', '', $content) ?? '';
        $content = preg_replace('/~~/', '', $content) ?? '';
        $content = preg_replace('/\{.*?\}/', '', $content) ?? '';
        $content = preg_replace('/`{3}.*\n/', '', $content) ?? '';
        $content = preg_replace('/<[^>]*>/', '', $content) ?? '';
        $content = preg_replace('/^[=\-]{2,}\s*$/', '', $content) ?? '';
        $content = preg_replace('/#{1,6}/', '', $content) ?? '';
        $content = preg_replace('/^(\n)?\s{0,}#{1,6}\s+| {0,}(\n)?\s{0,}#{0,} {0,}(\n)?\s{0,}$/', '$1$2$3', $content) ?? '';
        $content = preg_replace('/\[\^.+?\](\: .*?$)?/', '', $content) ?? '';
        $content = preg_replace('/\s{0,2}\[.*?\]: .*?$/', '', $content) ?? '';
        $content = preg_replace('/\!\[(.*?)\][\[\(].*?[\]\)]/', '', $content) ?? '';
        $content = preg_replace('/\s{0,3}>\s?/', '', $content) ?? '';
        $content = preg_replace('/([\*_]{1,3})(\S.*?\S{0,1})\1/', '$2', $content) ?? '';
        $content = preg_replace('/(`{3,})(.*?)\1/', '$2', $content) ?? '';
        $content = preg_replace('/`(.+?)`/', '$1', $content) ?? '';

        return trim($content);
    }

    // ─── Scoring ──────────────────────────────────────────────────────────

    /**
     * Substring counting on the lowercased query string.
     * Title hits weight ×5 vs. body hits.
     *
     * @return array<int, array>  one entry per matching indexed page
     */
    private function scoreIndex(array $index, string $query): array
    {
        $needle = mb_strtolower($query);
        if ($needle === '') {
            return [];
        }

        $scored = [];
        foreach ($index as $url => $entry) {
            $title   = mb_strtolower((string)($entry['title'] ?? ''));
            $content = mb_strtolower((string)($entry['content'] ?? ''));

            $hitsTitle   = $title   !== '' ? substr_count($title, $needle) : 0;
            $hitsContent = $content !== '' ? substr_count($content, $needle) : 0;

            $score = ($hitsTitle * 5) + $hitsContent;
            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'url'       => $url,
                'title'     => $entry['title'] ?? '',
                'score'     => $score,
                'excerpt'   => $this->excerpt($content, $needle),
                'filtername'=> (string)($entry['filtername'] ?? ''),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    /**
     * ±120 chars of plain text around the first content hit.
     * Returns the empty string when the index entry has no body.
     */
    private function excerpt(string $lowerContent, string $needle): string
    {
        if ($lowerContent === '' || $needle === '') {
            return '';
        }
        $pos = strpos($lowerContent, $needle);
        if ($pos === false) {
            # All hits were in the title; the body has no occurrence left
            # (after lowercase). Show the first 200 chars instead of nothing.
            return trim(mb_substr($lowerContent, 0, 200));
        }
        $start = max(0, $pos - 120);
        $excerpt = mb_substr($lowerContent, $start, 240);
        $excerpt = trim($excerpt);
        if ($start > 0) {
            $excerpt = '… ' . $excerpt;
        }
        if ($start + 240 < mb_strlen($lowerContent)) {
            $excerpt .= ' …';
        }
        return $excerpt;
    }
}
