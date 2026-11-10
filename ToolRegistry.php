<?php

namespace Plugins\mcp;

use Laminas\Permissions\Acl\Acl;
use Plugins\mcp\Tools\ReadTools;
use Plugins\mcp\Tools\SearchTool;
use Plugins\mcp\Tools\WriteTools;

/**
 * Single source of truth for every MCP tool exposed by this server.
 *
 * Each tool entry carries:
 *   - name             (used in MCP tools/list and tools/call)
 *   - description      (sent to the AI client so it knows when to call the tool)
 *   - inputSchema      (JSON Schema draft-07; additionalProperties:false)
 *   - acl              ([resource, privilege] mirrored from core API routes)
 *   - requiresPlugin   ('bettersearch'|'askthedocs'|null) optional gate
 *   - setting          ('read_tools'|'write_tools') toggle, both must be on
 *                       when the plugin setting `active` is on. The toggle
 *                       is checked in toolIsAvailable().
 *   - handler          [ClassName::FQN, 'method']
 *
 * The list returned by buildList() is the MCP `tools/list` response;
 * it is filtered by toggle, deps and per-tool ACL.
 */
class ToolRegistry
{
    /**
     * Tool catalogue. Keep entries grouped logically (read | search | write)
     * to make diffs easier in code review.
     *
     * @return array<string, array>
     */
    public static function catalogue(): array
    {
        return [

            // ─── Read tools ─────────────────────────────────────────────────
            'get_site_info' => [
                'name'        => 'get_site_info',
                'description' => 'Return basic site information (title, author, copyright, language, base url). '
                               . 'No arguments. Safe to call anytime to orient yourself in the site.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => (object)[],
                    'additionalProperties' => false,
                ],
                'acl'           => ['public', 'read'],
                'requiresPlugin' => null,
                'setting'       => 'read_tools',
                'handler'       => [ReadTools::class, 'getSiteInfo'],
            ],

            'list_pages' => [
                'name'        => 'list_pages',
                'description' => 'List pages as a tree. scope="live" returns public pages (default). '
                               . 'scope="draft" requires the content:read permission and includes drafts/unpublished. '
                               . 'url sets the project or multilanguage root to list from. Safe; never mutates content.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'scope' => [
                            'type' => 'string',
                            'enum' => ['live', 'draft'],
                            'description' => 'Default "live". "draft" requires content:read.',
                        ],
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL (or project/language root such as "/", "/de", "/docs"). Default "/".',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'acl'           => ['public', 'read'],
                'requiresPlugin' => null,
                'setting'       => 'read_tools',
                'handler'       => [ReadTools::class, 'listPages'],
            ],

            'find_page' => [
                'name'        => 'find_page',
                'description' => 'Find a page by its URL ("url" argument) or by a slug ("slug" argument, exact one required). '
                               . 'Slugs are not unique: returns all matches so the caller can disambiguate. '
                               . 'Use this when you have a slug from another page and want to know its full URL.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL such as "/getting-started/installation".',
                        ],
                        'slug' => [
                            'type' => 'string',
                            'description' => 'Exact slug to look for across the site (not unique).',
                        ],
                    ],
                    'anyOf' => [
                        ['required' => ['url']],
                        ['required' => ['slug']],
                    ],
                    'additionalProperties' => false,
                ],
                'acl'           => ['public', 'read'],
                'requiresPlugin' => null,
                'setting'       => 'read_tools',
                'handler'       => [ReadTools::class, 'findPage'],
            ],

            'get_page' => [
                'name'        => 'get_page',
                'description' => 'Fetch a page by URL. Returns the raw markdown document (title as first H1, body after). '
                               . 'Default draft=false returns the published version. Set draft=true to fetch the currently edited '
                               . 'draft (requires the mycontent:read permission). Empty pages still return an empty "markdown".',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL such as "/getting-started/installation".',
                        ],
                        'draft' => [
                            'type' => 'boolean',
                            'description' => 'Default false. If true, return the current draft instead of the published page. Requires mycontent:read.',
                        ],
                    ],
                    'required' => ['url'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['public', 'read'],
                'requiresPlugin' => null,
                'setting'       => 'read_tools',
                'handler'       => [ReadTools::class, 'getPage'],
            ],

            'get_page_metadata' => [
                'name'        => 'get_page_metadata',
                'description' => 'Get all metadata fields of a single page (tab name + values). '
                               . 'Returned as {meta:{...}}. Useful to read navigation title, owner, restrictions, etc.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL such as "/getting-started/installation".',
                        ],
                    ],
                    'required' => ['url'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['public', 'read'],
                'requiresPlugin' => null,
                'setting'       => 'read_tools',
                'handler'       => [ReadTools::class, 'getPageMetadata'],
            ],

            // ─── Search tool (bettersearch-gated) ────────────────────────────
            'search_pages' => [
                'name'        => 'search_pages',
                'description' => 'Search published (live) pages by full-text. Returns a ranked list with a short excerpt around each '
                               . 'first hit. THIS TOOL IS ONLY AVAILABLE WHEN THE BETTERSEARCH PLUGIN IS ACTIVE on the Typemill '
                               . 'instance. Naive substring scoring (title hits weight 5× body hits); designed for an AI client that '
                               . 'will then call get_page for the most relevant results.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query (case-insensitive substring match).',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 50,
                            'description' => 'Maximum number of results. Default 10.',
                        ],
                        'project' => [
                            'type' => 'string',
                            'description' => 'Optional project id (lowercase) for multi-project setups.',
                        ],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['public', 'read'],
                'requiresPlugin' => 'bettersearch',
                'setting'       => 'read_tools',
                'handler'       => [SearchTool::class, 'searchPages'],
            ],

            // ─── Write tools ─────────────────────────────────────────────────
            'create_page' => [
                'name'        => 'create_page',
                'description' => 'Create a new page or folder. parent_url is the destination folder URL (use "/" for root, '
                               . 'or a folder URL such as "/docs"). name becomes the title and the URL slug. '
                               . 'type="file" creates a single page, type="folder" creates an indexable folder. '
                               . 'If markdown is provided, the page is created and immediately written as a draft (a separate '
                               . 'publish_page call is still required to make it live). Requires content:create.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'parent_url' => [
                            'type' => 'string',
                            'description' => 'URL of the destination folder. "/" creates a top-level page.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Page name (becomes title; 1–60 characters).',
                            'minLength' => 1,
                            'maxLength' => 60,
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['file', 'folder'],
                            'description' => 'Default "file". Use "folder" to create an indexable folder (its index page can be edited like any other).',
                        ],
                        'markdown' => [
                            'type' => 'string',
                            'description' => 'Optional initial markdown content. Written as a draft only; the page is not live until publish_page.',
                        ],
                    ],
                    'required' => ['parent_url', 'name'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['content', 'create'],
                'requiresPlugin' => null,
                'setting'       => 'write_tools',
                'handler'       => [WriteTools::class, 'createPage'],
            ],

            'update_page' => [
                'name'        => 'update_page',
                'description' => 'Replace the page content with the supplied markdown. ALWAYS WRITES A DRAFT — the published page '
                               . 'is not touched. Combine with publish_page to make changes visible. '
                               . 'The "url" argument targets an existing page (folder or file). Requires content:create.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL such as "/getting-started/installation".',
                        ],
                        'markdown' => [
                            'type' => 'string',
                            'description' => 'Full markdown document (first H1 is the title).',
                        ],
                    ],
                    'required' => ['url', 'markdown'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['content', 'create'],
                'requiresPlugin' => null,
                'setting'       => 'write_tools',
                'handler'       => [WriteTools::class, 'updatePage'],
            ],

            'publish_page' => [
                'name'        => 'publish_page',
                'description' => 'Publish the existing draft of a page (or folder index). Requires content:publish. '
                               . 'Publishing fires OnPagePublished so the BETTERSEARCH and ASKDOCS indexes invalidate '
                               . 'themselves automatically.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL to publish.',
                        ],
                    ],
                    'required' => ['url'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['content', 'publish'],
                'requiresPlugin' => null,
                'setting'       => 'write_tools',
                'handler'       => [WriteTools::class, 'publishPage'],
            ],

            'unpublish_page' => [
                'name'        => 'unpublish_page',
                'description' => 'Unpublish a previously published page. The page and its draft disappear from the live site. '
                               . 'Requires content:unpublish.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL to unpublish.',
                        ],
                    ],
                    'required' => ['url'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['content', 'unpublish'],
                'requiresPlugin' => null,
                'setting'       => 'write_tools',
                'handler'       => [WriteTools::class, 'unpublishPage'],
            ],

            'delete_page' => [
                'name'        => 'delete_page',
                'description' => 'DESTRUCTIVE AND IRREVERSIBLE. Permanently deletes a page or folder and all its assets/metadata. '
                               . 'No undo. Clients SHOULD ask the user to confirm before calling. '
                               . 'Requires content:delete.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'Page URL (or folder URL) to delete.',
                        ],
                    ],
                    'required' => ['url'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['content', 'delete'],
                'requiresPlugin' => null,
                'setting'       => 'write_tools',
                'handler'       => [WriteTools::class, 'deletePage'],
            ],

            'update_page_metadata' => [
                'name'        => 'update_page_metadata',
                'description' => 'Update metadata fields on a single tab of a page (e.g. "meta", "seo", "social", or a plugin tab). '
                               . '"data" must contain the tab\'s field names; unknown fields are rejected by the validation '
                               . 'in the core controller. Requires mycontent:update.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string', 'description' => 'Page URL.'],
                        'tab' => ['type' => 'string', 'description' => 'Metadata tab name (e.g. "meta", "seo", plugin-specific).'],
                        'data' => [
                            'type'        => 'object',
                            'description' => 'Tab field values as object; structure matches the tab definition.',
                            'additionalProperties' => true,
                        ],
                    ],
                    'required' => ['url', 'tab', 'data'],
                    'additionalProperties' => false,
                ],
                'acl'           => ['mycontent', 'update'],
                'requiresPlugin' => null,
                'setting'       => 'write_tools',
                'handler'       => [WriteTools::class, 'updatePageMetadata'],
            ],
        ];
    }

    /**
     * Build the MCP `tools/list` payload, applying all filters:
     *   1. public-mode whitelist (operator-curated set of tool names)
     *   2. public-mode ACL pre-filter (ensure [public,read] only)
     *   3. tool entry exists in the catalogue
     *   4. plugin setting toggle (read_tools / write_tools) is on
     *   5. requiredPlugin is met (only bettersearch_active → search_pages)
     *   6. user role passes ACL check (skipped in public mode since
     *      the registry already restricted to [public,read])
     *
     * @return array<int, array>
     */
    public function buildList(
        array $pluginSettings,
        bool $bettersearchActive,
        bool $askthedocsActive,
        string $userrole,
        Acl $acl,
        bool $publicMode = false,
        ?array $publicTools = null
    ): array {
        $out = [];

        $whitelist = ($publicMode && is_array($publicTools))
            ? array_flip($publicTools)
            : null;

        foreach (self::catalogue() as $tool) {
            if ($whitelist !== null && !isset($whitelist[$tool['name']])) {
                continue;
            }

            # In public mode the tool must be safe: it can never carry
            # an ACL outside [public,read]. We refuse write tools here
            # so a future admin mistake cannot expose them.
            if ($publicMode) {
                [$resource, $privilege] = $tool['acl'];
                if (!($resource === 'public' && $privilege === 'read')) {
                    continue;
                }
            }

            if (!$this->toolIsAvailable($tool, $pluginSettings, $bettersearchActive, $askthedocsActive)) {
                continue;
            }

            if (!$publicMode) {
                [$resource, $privilege] = $tool['acl'];
                if (!$acl->isAllowed($userrole, $resource, $privilege)) {
                    continue;
                }
            }

            $out[] = [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return $out;
    }

    /**
     * Find a tool by its MCP name. Returns the full catalogue entry or null.
     *
     * @return array|null
     */
    public function findTool(string $name): ?array
    {
        $catalogue = self::catalogue();
        return $catalogue[$name] ?? null;
    }

    /**
     * Is the tool switched on given the current plugin settings and
     * companion-plugin activity? Per-tool ACL is checked separately.
     */
    public function toolIsAvailable(
        array $tool,
        array $pluginSettings,
        bool $bettersearchActive,
        bool $askthedocsActive
    ): bool {
        $toggle = $tool['setting'] ?? null;
        if ($toggle !== null) {
            if (empty($pluginSettings[$toggle])) {
                return false;
            }
        }

        $required = $tool['requiresPlugin'] ?? null;
        if ($required === 'bettersearch' && !$bettersearchActive) {
            return false;
        }
        if ($required === 'askthedocs' && !$askthedocsActive) {
            return false;
        }

        return true;
    }

    /**
     * Validate $arguments against the tool's inputSchema. We implement a
     * minimal, deterministic subset of JSON Schema that covers what every
     * tool here actually declares:
     *   - type=object, additionalProperties:false
     *   - required, properties.{name}.type, enum, minLength, maxLength,
     *     minimum, maximum
     *   - anyOf required-coupling (e.g. exactly-one of url|slug for find_page)
     *
     * Returns null on success or a human-readable error string.
     */
    public function validateArguments(array $tool, array $arguments): ?string
    {
        $schema = $tool['inputSchema'] ?? [];

        $type = $schema['type'] ?? 'object';
        if ($type !== 'object' || !is_array($arguments)) {
            return 'arguments must be a JSON object';
        }

        $properties = $schema['properties'] ?? [];

        # additionalProperties:false → reject unknown keys
        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] === false) {
            foreach ($arguments as $key => $_) {
                if (!array_key_exists($key, $properties)) {
                    return 'unknown argument: ' . $key;
                }
            }
        }

        # per-property checks
        foreach ($properties as $name => $def) {
            $present = array_key_exists($name, $arguments);
            $value   = $arguments[$name] ?? null;

            if ($present) {
                $typeError = $this->validateType($name, $def, $value);
                if ($typeError !== null) {
                    return $typeError;
                }
            }
        }

        # `required` (single array form)
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $reqName) {
                if (!array_key_exists($reqName, $arguments) || $arguments[$reqName] === null) {
                    return 'missing required argument: ' . $reqName;
                }
            }
        }

        # `anyOf` for xor-style required coupling
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $branchMatched = false;
            foreach ($schema['anyOf'] as $branch) {
                if (!is_array($branch) || !isset($branch['required']) || !is_array($branch['required'])) {
                    continue;
                }
                $allHere = true;
                foreach ($branch['required'] as $reqName) {
                    if (!array_key_exists($reqName, $arguments) || $arguments[$reqName] === null || $arguments[$reqName] === '') {
                        $allHere = false;
                        break;
                    }
                }
                if ($allHere) {
                    $branchMatched = true;
                    break;
                }
            }
            if (!$branchMatched) {
                $descriptions = [];
                foreach ($schema['anyOf'] as $branch) {
                    $descriptions[] = implode('+', $branch['required'] ?? []);
                }
                return 'exactly one of the alternatives is required: ' . implode(' / ', $descriptions);
            }
        }

        return null;
    }

    /**
     * Per-property JSON Schema validator. Returns null on success.
     */
    private function validateType(string $name, array $def, $value): ?string
    {
        $type = $def['type'] ?? null;

        switch ($type) {
            case 'string':
                if (!is_string($value)) {
                    return 'argument "' . $name . '" must be a string';
                }
                if (isset($def['minLength']) && mb_strlen($value) < (int)$def['minLength']) {
                    return 'argument "' . $name . '" must be at least ' . (int)$def['minLength'] . ' characters';
                }
                if (isset($def['maxLength']) && mb_strlen($value) > (int)$def['maxLength']) {
                    return 'argument "' . $name . '" must be at most ' . (int)$def['maxLength'] . ' characters';
                }
                if (isset($def['enum']) && is_array($def['enum']) && !in_array($value, $def['enum'], true)) {
                    return 'argument "' . $name . '" must be one of: ' . implode(', ', array_map('strval', $def['enum']));
                }
                return null;

            case 'integer':
                if (!is_int($value) || is_bool($value)) {
                    return 'argument "' . $name . '" must be an integer';
                }
                if (isset($def['minimum']) && $value < (int)$def['minimum']) {
                    return 'argument "' . $name . '" must be >= ' . (int)$def['minimum'];
                }
                if (isset($def['maximum']) && $value > (int)$def['maximum']) {
                    return 'argument "' . $name . '" must be <= ' . (int)$def['maximum'];
                }
                return null;

            case 'number':
                if (!is_numeric($value) || is_bool($value)) {
                    return 'argument "' . $name . '" must be a number';
                }
                return null;

            case 'boolean':
                if (!is_bool($value)) {
                    return 'argument "' . $name . '" must be a boolean';
                }
                return null;

            case 'array':
                if (!is_array($value) || (array_keys($value) !== range(0, count($value) - 1))) {
                    return 'argument "' . $name . '" must be an array';
                }
                return null;

            case 'object':
                if (!is_array($value)) {
                    return 'argument "' . $name . '" must be an object';
                }
                return null;

            case null:
                # No type declared → accept whatever JSON gave us.
                return null;

            default:
                return 'argument "' . $name . '" has unsupported schema type: ' . $type;
        }
    }
}
