---
title: Filters
parent: Developer Reference
nav_order: 1
---

## 17. Filters

### `tc_link_types`

Register additional link types programmatically. Receives and returns an associative array keyed by slug.

```php
add_filter( 'tc_link_types', function( array $types ): array {
    $types['my-db'] = array(
        'slug'             => 'my-db',
        'label'            => 'My Database',
        'uri_prefix'       => 'https://mydb.example.com/entry/',
        'platform'         => 'other',
        'enabled'          => true,
        'show_on_archives' => true,
        'taxonomies'       => array(),
    );
    return $types;
} );
```

A registered type may include a **`provider`** key (your plugin slug). Types with a non-empty `provider` are **hidden from the Peer Sites admin screen** (managed by your own plugin instead) and are never written to the `tc_link_types` option — but they remain fully active for term editing, REST, and peer matching.

### `tc_link_uri`

Filter the final URL built for a link-type + identifier pair (`tc_get_link_uri()`). Lets a plugin produce per-network, per-taxonomy, or transformed URLs the default `uri_prefix . id` concatenation can't express.

```php
add_filter( 'tc_link_uri', function( ?string $url, string $slug, string $id, array $type, string $taxonomy ): ?string {
    if ( 0 === strpos( $slug, 'social_' ) ) {
        // e.g. hashtag URL on the 'hashtag' taxonomy, profile URL on 'mention'.
    }
    return $url;
}, 10, 5 );
```

### `tc_suggest_config` / `tc_parse_suggest_response`

Add live autocomplete to the term-edit identifier field for a link type you registered. `tc_suggest_config` is consulted (after TermConnect's own built-in identifier types and the `mediawiki` platform fallback) for a type with no built-in config; return `[ 'url' => <template>, 'parser' => <id> ]` to enable suggestions. `{query}` in the URL template is replaced with the URL-encoded search term and the request is made **server-side to that fixed endpoint only** — the term is the sole interpolated value, so arbitrary-URL SSRF is not possible. Pair with `tc_parse_suggest_response` to turn the response body into `{ value, label }` suggestions for your `parser` id.

Two optional refinements:

- `'free_text' => true` makes suggestions **advisory** rather than required — typed input that matches no suggestion is still accepted (appropriate for handles the editor may already know).
- The `$taxonomy` argument (the term being edited) lets you offer suggestions only where they make sense — e.g. a profile search only on a `mention` taxonomy.

```php
add_filter( 'tc_suggest_config', function( $config, string $slug, $type, string $taxonomy ) {
    if ( null !== $config || 'social_github' !== $slug || 'mention' !== $taxonomy ) {
        return $config;
    }
    return array(
        'url'       => 'https://api.github.com/search/users?q={query}&per_page=8',
        'parser'    => 'my_github',
        'free_text' => true,
    );
}, 10, 4 );

add_filter( 'tc_parse_suggest_response', function( array $results, string $body, string $parser ): array {
    if ( 'my_github' !== $parser ) {
        return $results;
    }
    $data = json_decode( $body, true );
    $out  = array();
    foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
        if ( ! empty( $item['login'] ) ) {
            $out[] = array( 'value' => $item['login'], 'label' => '@' . $item['login'] );
        }
    }
    return $out;
}, 10, 3 );
```

### `tc_enabled_taxonomies`

Filter the list of TermConnect-enabled taxonomies (`TC_Settings::get_enabled_taxonomies()`). Lets a companion plugin opt its taxonomies into TermConnect behavior — the `tc_links` REST field, the `htax_term_uri` matching parameter, peer term resolution by shared identifier, and "See also" — without writing them into the `tc_enabled_taxonomies` option. The result is normalized to a unique list of taxonomy slug strings.

This is what makes cross-site matching by shared identifier work for a companion's terms. For example, the Social Hashtags & Mentions plugin adds its `mention` taxonomy so that two peer sites storing the same network handle for a person are matched by the resulting profile URI — even when the term slugs and labels differ — through the same identifier-overlap path TermConnect already uses for shared locations.

```php
add_filter( 'tc_enabled_taxonomies', function( array $taxonomies ): array {
    $taxonomies[] = 'mention';
    return $taxonomies;
} );
```

### `tc_taxonomy_peer_authors`

Declare that a taxonomy's WordPress-peer links map to **authors** (WP users) rather than to peer terms. A "people" taxonomy (e.g. Social Hashtags & Mentions' `mention`) represents a person, who on a peer site is an author. When `true`, the term-edit identifier field autocompletes the peer's authors (via the SSRF-safe users proxy; the special non-configurable "Local site" peer lists local authors instead) and the link resolves to the peer's `/author/{nicename}/` archive.

```php
add_filter( 'tc_taxonomy_peer_authors', function( bool $is_author, string $taxonomy ): bool {
    return 'mention' === $taxonomy ? true : $is_author;
}, 10, 2 );
```

### `tc_link_value`

Normalize or validate a link identifier before it is stored (`tc_set_term_link()`). Useful for cleaning up free-form input — stripping an `@`, reducing a pasted profile URL to a handle, etc. Return an empty string to reject the value (the link is removed rather than stored).

```php
add_filter( 'tc_link_value', function( string $id, string $slug, array $type ): string {
    if ( 0 !== strpos( $slug, 'social_' ) ) {
        return $id;
    }
    $id = ltrim( trim( $id ), '@' );
    return preg_match( '/\s/', $id ) ? '' : $id; // a handle has no spaces
}, 10, 3 );
```

### `tc_term_links_see_also_html`

Override the full "See also" HTML before it is appended to the archive description. Return an empty string to suppress.

```php
add_filter( 'tc_term_links_see_also_html', function(
    string  $html,
    WP_Term $term,
    array   $items   // [ ['url' => ..., 'label' => ...], ... ]
): string {
    return $html;
}, 10, 3 );
```

### `tc_index_extra_uris`

Contribute extra URIs to a term's `_tc_uris` index — the index TermConnect matches against for cross-site term resolution (`?htax_term_uri=`) — without owning a TermConnect link type. Fired inside `tc_rebuild_uri_index()` after the local and delegated link URIs are collected.

This is how a term's identity can come from a source other than a link type. For example, HyperTaxonomy injects a canonical-address URI derived from As the Crow Flies, so peer and FOAF terms describing the **same place** match by shared location even when their slugs and labels differ. (Trigger a rebuild via `tc_rebuild_uri_index( $term_id )` when your source data changes, so the index stays current.)

```php
add_filter( 'tc_index_extra_uris', function( array $uris, int $term_id ): array {
    $uris[] = 'urn:example:place:US:62704:123-main-street';
    return $uris;
}, 10, 2 );
```

### `htax_term_uris` *(HyperTaxonomy-defined)*

TermConnect populates this HyperTaxonomy filter with URIs from all enabled link types that have a value set on the term. Hook into it to add your own URIs alongside TermConnect's.

```php
add_filter( 'htax_term_uris', function( array $uris, int $term_id ): array {
    $uris[] = 'https://mydb.example.com/entry/42';
    return $uris;
}, 10, 2 );
```

### `htax_term_external_posts` *(HyperTaxonomy-defined)*

TermConnect populates this HyperTaxonomy filter with related posts fetched from the term's configured WordPress peer sites, as plain normalized post arrays. Consumers read them (visibility-gated) via `htax_get_term_external_posts()` — see [External Documents](external-documents.html). Hook into it to contribute documents from your own source.

```php
add_filter( 'htax_term_external_posts', function( array $docs, int $term_id ): array {
    // $docs[] = [ 'url' => …, 'title' => …, 'date_gmt' => …, 'excerpt' => …, … ];
    return $docs;
}, 10, 2 );
```
