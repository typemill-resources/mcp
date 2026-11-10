<?php

namespace Plugins\mcp;

use Typemill\Plugin;

class mcp extends Plugin
{
    /**
     * Detected plugin activity flags, set in onPluginsLoaded().
     * - bettersearch_active: a search index is available for the search_pages tool.
     * - askthedocs_active: per-page descriptions can be enriched in list_pages.
     */
    protected bool $bettersearch_active = false;
    protected bool $askthedocs_active = false;

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsLoaded' => ['onPluginsLoaded', 0],
        ];
    }

    public static function addNewRoutes()
    {
        return [
            # Authenticated endpoint. The route lives under /api/v1 with
            # resource+privilege, so api.php wires ApiAuthentication +
            # ApiAuthorization(public,read) automatically. Per-tool ACL
            # is then enforced inside the handler.
            [
                'httpMethod' => 'post',
                'route'      => '/api/v1/mcp',
                'name'       => 'mcp.endpoint',
                'class'      => 'Plugins\mcp\McpController:handle',
                'resource'   => 'public',
                'privilege'  => 'read',
            ],

            # Public, no-auth read-only endpoint. NO resource / privilege
            # means routes/api.php leaves it out of the protected API
            # chain, so request reach the controller anonymously. The
            # controller itself filters tools and arguments. See README
            # for the security model (anyone with the URL can read all
            # public content). The route is gated by the public_endpoint
            # plugin setting — set to false to remove it entirely.
            [
                'httpMethod' => 'post',
                'route'      => '/api/v1/mcp-public',
                'name'       => 'mcp.endpoint.public',
                'class'      => 'Plugins\mcp\McpController:handlePublic',
            ],
        ];
    }

    /**
     * Detect which optional sister plugins are loaded so that the
     * tool registry can selectively advertise search_pages and
     * enrich list_pages with descriptions.
     *
     * The active plugin names are passed as the data array of the
     * OnPluginsLoaded event (see system.php:247).
     */
    public function onPluginsLoaded($plugins)
    {
        $names = $plugins->getData();

        if (is_array($names)) {
            foreach ($names as $name) {
                if (!is_string($name)) {
                    continue;
                }
                $lower = strtolower($name);
                if ($lower === 'bettersearch') {
                    $this->bettersearch_active = true;
                }
                if ($lower === 'askthedocs') {
                    $this->askthedocs_active = true;
                }
            }
        }

        # keep the event data unchanged so that other plugins see the same list
        $plugins->setData($names);
    }
}
