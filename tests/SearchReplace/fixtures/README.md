# SearchReplace fixtures

Real-world shapes we test against. Rather than committing huge raw dumps, we reconstruct realistic structures inline in tests — easier to read, easier to diff, no binary blobs.

- **WooCommerce order meta** — nested `stdClass`/array with URLs in product_permalink, line_items[].
- **ACF field groups** — serialized arrays with nested repeaters pointing to image URLs.
- **Gutenberg blocks** — JSON-encoded attribute blobs inside `post_content`.
