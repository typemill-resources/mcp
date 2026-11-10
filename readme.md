# MCP Server for Typemill

Native Typemill plugin that exposes the [Model Context Protocol](https://modelcontextprotocol.io) (MCP) over Streamable HTTP so AI clients (Claude, ChatGPT, IDEs, custom agents) can **read and edit Typemill content as tools** instead of integrating the REST API themselves.

> v1 ships with **read tools + search** enabled by default. Write tools are off by default and must be enabled in the plugin settings — they reuse the core authoring API in-process, so all validation, ownership checks and `OnPagePublished` / `OnPageDeleted` event listeners (used by `bettersearch`, `askthedocs` and others) work exactly the same as they do in the admin UI.

## Endpoints

The plugin registers two HTTP routes. Both speak JSON-RPC 2.0 over Streamable HTTP (stateless) and both honour the MCP protocol versions listed below.

| Endpoint | Auth | Surface | Use it for |
|---|---|---|---|
| `POST /api/v1/mcp` | HTTP Basic Auth (any user with `apiaccess: true`) | Whatever the user's ACL allows (`public:read` minimum) | Production clients (Claude Desktop, Cursor, your own agents) |
| `POST /api/v1/mcp-public` | **None.** Network-level only (Origin / payload cap) | Operator-curated read-only whitelist (default: `get_site_info`, `list_pages`, `find_page`, `get_page` live, `get_page_metadata`, `search_pages`) | ChatGPT-style hosts that don't speak Basic Auth, or any read-only client |

`/api/v1/mcp` registers with `resource: public` / `privilege: read` so `routes/api.php` wires up `ApiAuthentication` + `ApiAuthorization(public:read)` automatically. `/api/v1/mcp-public` is registered **without** resource/privilege so the API auth chain is bypassed — the controller is responsible for the rest of the security model.

## Connecting clients

### Claude Desktop / Cursor / MCP Inspector (authenticated endpoint)

In the client's MCP configuration, declare a server like this (replace host, user, password):

```json
{
  "mcpServers": {
    "typemill": {
      "type": "http",
      "url": "https://your-typemill-host/api/v1/mcp",
      "headers": {
        "Authorization": "Basic <base64(username:password)>"
      }
    }
  }
}
```

Or use the MCP Inspector (`npx @modelcontextprotocol/inspector`) for manual debugging:

```
Transport: Streamable HTTP
URL:       https://your-typemill-host/api/v1/mcp
Headers:   Authorization: Basic <base64(user:password)>
```

### ChatGPT / no-auth hosts (public endpoint)

If your host can speak MCP over plain HTTP without authentication, point it at:

```
POST https://your-typemill-host/api/v1/mcp-public
Content-Type: application/json
```

No `Authorization` header. The host must still send an `Origin` header (browsers always do); it must either match `settings.plugins.mcp.public_origin` if set, or match the site host. The endpoint exposes ONLY the tools listed in `public_tools` (default the six read+search tools above), and the protocol layer refuses `draft:true` on `get_page` and `scope:"draft"` on `list_pages` regardless of any other setting.

### Dedicated API user (recommended for the authenticated endpoint)

Create a dedicated Typemill user for the MCP client with the **role** that matches the operations you want it to perform (e.g. `editor` for full read+write minus system settings; `member` for read-only + own content). Mark the **apiaccess** flag on the user. The user does not need a session cookie — HTTP Basic Auth is enough.

For most AI pair-programming clients, **`editor`** is the practical sweet spot — full read access plus writes/deletes on content without exposing system settings or user administration.

## Tools

Each tool exposes `name`, `description`, `inputSchema` (JSON Schema draft-07). Per-method descriptions appear in `tools/list` so the model knows when to call each one.

### Read tools (always on when `settings.plugins.mcp.read_tools` is true)

| Name | Description |
|---|---|
| `get_site_info` | Whitelist of `{title, author, copyright, language, baseurl}`. |
| `list_pages` | Navigation tree (live or draft). Adds `description` per page when the **askthedocs** plugin is active. |
| `find_page` | Resolve a page by URL or slug. |
| `get_page` | Raw markdown of a live or draft page. |
| `get_page_metadata` | The full meta record for a page. |
| `search_pages` | Ranked full-text search. **Only available when the `bettersearch` plugin is active.** The MCP plugin rebuilds the index on a cache miss. |

### Write tools (off by default — enable with `settings.plugins.mcp.write_tools`)

| Name | Description |
|---|---|
| `create_page` | Create a file or folder under a parent URL. Optional initial markdown (saved as a draft). |
| `update_page` | Replace a page's content. **Always writes a draft** — combine with `publish_page` to make it live. |
| `publish_page` | Publish an existing draft. Fires `OnPagePublished` so `bettersearch` and `askthedocs` indexes invalidate. |
| `unpublish_page` | Unpublish a page. |
| `delete_page` | DESTRUCTIVE. Clients should ask the user to confirm. |
| `update_page_metadata` | Update a metadata tab (e.g. `meta`, `seo`, plugin-specific tabs). |

### Per-tool ACL

| Tool | ACL | Mirrors |
|---|---|---|
| `get_site_info`, `list_pages` (live), `find_page`, `get_page` (live), `get_page_metadata`, `search_pages` | `public:read` | Core API public endpoints |
| `list_pages` (draft), `get_page` (draft) | `content:read` (plus `mycontent:read` for own drafts) | Draft-aware read endpoints |
| `create_page`, `update_page` | `content:create` | Core authoring endpoints |
| `update_page_metadata` | `mycontent:update` | Per-page meta updates |
| `publish_page` | `content:publish` | Authoring publish |
| `unpublish_page` | `content:unpublish` | Authoring unpublish |
| `delete_page` | `content:delete` | Authoring delete |

Tools a user cannot run are **absent from `tools/list`** — they do not appear in tool discovery.

## Settings

Plugin settings live in `/settings/settings.yaml`. After activating in the admin or by editing the file directly, you can tune:

| Key | Default | Description |
|---|---|---|
| `active` | `false` | When off, `/api/v1/mcp` is unregistered (returns 405 from the API group). |
| `read_tools` | `true` | Read tools (incl. `search_pages` when its companion plugin is active). |
| `write_tools` | `false` | Turn on the create/update/publish/delete/metadata tools. |
| `allow_origin` | `""` | Optional. Origin header allowed on the authenticated endpoint (exact match). When empty, the Origin host must match the site host. |
| `public_endpoint` | `false` | When true, `/api/v1/mcp-public` is exposed with **no authentication**. See the security note below. |
| `public_tools` | `[get_site_info, list_pages, find_page, get_page, get_page_metadata, search_pages]` | Operator-curated whitelist of tools available on the public endpoint. Each must have ACL `[public, read]`; the registry pre-filters anything else as a defence in depth. |
| `public_origin` | `""` | Optional. Origin allowed on the public endpoint. When empty, the Origin host must match the site host. Useful when the public endpoint sits behind a proxy on a different origin. |
| `public_max_payload_kb` | `64` | Per-request JSON body cap on the public endpoint (8 KiB – 512 KiB enforced). Prevents hostile large bodies from reaching the JSON decoder. |

A typical admin setup (authenticated only, the recommended v1 default):

```yaml
plugins:
  mcp:
    active: true
    read_tools: true
    write_tools: false
    allow_origin: ''
```

A setup that also enables the public, no-auth read-only endpoint:

```yaml
plugins:
  mcp:
    active: true
    read_tools: true
    write_tools: false
    allow_origin: ''
    public_endpoint: true
    public_tools:
      - get_site_info
      - list_pages
      - find_page
      - get_page
      - get_page_metadata
      - search_pages
    public_origin: ''
    public_max_payload_kb: 64
```

### ⚠️ Public endpoint security note

When `public_endpoint: true` **anyone who knows the URL can read every published page and metadata on your site, and run full-text search over it.** This is the same data the public web frontend already serves, just packaged for AI clients. You absolutely should:

* Rate-limit `/api/v1/mcp-public` at your reverse proxy (per-IP). 60-120 req/min is a reasonable starting point.
* Set `public_origin` to the origin of the host that will call it (e.g. `https://chatgpt.com` if ChatGPT ever supports a configurable origin), so other browser contexts cannot hit your endpoint.
* Reconsider enabling this if your site has restricted pages whose titles or restricted-role hints would otherwise reach the public web. `get_page_metadata` returns the meta record (owner, allowed role, hidden flags) the way the public web already does via the existing `/api/v1/article/meta` endpoint — if your pages have restrictions set, their presence in the response is intentional because they are visible to anonymous frontend visitors.
* Consider removing `search_pages` from `public_tools` (and/or disabling the `bettersearch` companion plugin) if you don't want your whole published corpus exposed to full-text search by anonymous callers.

## Protocol limitations (v1)

What this v1 deliberately does not include so we can upgrade later without breaking the wire format:

* No stdio transport — only HTTP. (Future versions can add stdio alongside without breaking existing clients.)
* No SSE streaming — single request/response per call.
* No `resources` or `prompts` MCP primitives — only `tools`.
* No batch requests — JSON arrays are rejected with JSON-RPC `-32600`.
* No OAuth / Bearer tokens on the authenticated endpoint — HTTP Basic Auth over the existing API auth. (OAuth for the wider Typemill API is a separate, future core hardening track.) The `/api/v1/mcp-public` endpoint is the v1 accommodation for hosts that don't ship Basic Auth support and only need read-only/public access.

Every tool result still round-trips through the standard `content: [{type:"text", text:<json-string>}]` envelope, so adding new tools or arguments is a non-breaking change.

## Operational notes

* **Stateless** — no MCP session is tracked. Each call re-evaluates the ACL; rotating a user's role is reflected on the next request.
* **Disk cache use** — the only side effect on disk is doing an unpublished/publish/delete: the core controllers fire `OnPagePublished`/`OnPageDeleted`, and the `bettersearch` index file gets dropped (matching the upstream plugin's invalidation contract). When `search_pages` is called and the index is missing, the MCP plugin rebuilds it itself using the same code path that `bettersearch`'s frontend route uses.
* **Logs** — JSON-RPC errors are HTTP 200 with `result.isError: true`. HTTP error codes are reserved for transport issues (malformed envelope, no auth, wrong origin) so malformed clients surface in access logs.

## File layout

```
plugins/mcp/
  mcp.yaml                 # Plugin metadata + admin form
  mcp.php                  # Plugin class — adds the routes (authenticated + public), detects sister plugins
  McpController.php        # Transport envelope: 404/403 + JSON parse + ID; handle() vs handlePublic()
  McpProtocol.php          # JSON-RPC dispatcher; same code serves both authenticated and public modes
  ToolRegistry.php         # Single source of truth for every tool's name/description/schema/ACL + public whitelist filter
  Tools/ReadTools.php      # List/find/get/get-metadata/get-site-info
  Tools/SearchTool.php     # BETTERSEARCH-gated search_pages, with self-rebuilding index
  Tools/WriteTools.php     # In-process delegation to ControllerApiAuthorArticle + ControllerApiAuthorMeta
  readme.md                # This file
```

How a call flows through the two endpoints:

```
authenticated:    MCP client → ApiAuthentication → ApiAuthorization(public,read)
                  → /api/v1/mcp → McpController::handle → McpProtocol
                  → ToolRegistry::buildList (filter by ACL user can pass)
                  → handler → response

public:           MCP client → Origin check + payload cap
                  → /api/v1/mcp-public → McpController::handlePublic → McpProtocol(publicMode=true, publicTools=[...])
                  → ToolRegistry::buildList (filter by public_tools + ACL=[public,read], skip user ACL)
                  → handler (runs as userrole='guest' so per-page publish/role checks happen normally)
                  → response
```
