# Search-Replace Engine

Serialize-aware string replacement for WordPress data. Safely rewrites URLs inside PHP-serialized values, JSON, Gutenberg block markup, and plain text — without corrupting byte-length prefixes.

## Public API

### `WpMigrateSafe\SearchReplace\Replacer`

```php
use WpMigrateSafe\SearchReplace\Replacer;

$r = new Replacer('http://old.com', 'https://new.com');

$result = $r->apply($columnValue);
echo $result->value();         // replaced string
echo $result->replacements();  // number of replacements made
if ($result->changed()) { ... }
```

**Handling logic:**
1. If `$value` doesn't contain the search term at all → return unchanged (fast path).
2. If `$value` is detected as serialized → `unserialize` → recursively walk → replace every string leaf → `serialize` (correct byte lengths).
3. Otherwise → plain `str_replace` (correct for JSON, HTML, plain text).

### `WpMigrateSafe\SearchReplace\Result`

Immutable value object.

```php
$result->value();        // string
$result->replacements(); // int
$result->changed();      // bool
```

### `WpMigrateSafe\SearchReplace\SerializedDetector`

Low-level helper — usually you don't need this directly.

```php
SerializedDetector::isSerialized($value); // bool
```

### Exceptions

- `WpMigrateSafe\SearchReplace\Exception\UnserializableException` — raised only if you explicitly request strict mode (future API). In default mode, malformed serialized data falls back to plain replacement.

## When NOT to Use

- Replacements that change the byte length of *keys* inside PHP `C:` (serializable object) payloads that declare their own byte count: these use a custom `serialize()` method and are not round-tripped. Rare in WP core; watch for them in edge cases.
- Cross-field replacements inside encrypted columns.

## Correctness Invariants

After `apply()`:
1. If input was valid serialized PHP → output is valid serialized PHP.
2. If input was valid JSON → output is valid JSON.
3. If input contained no `search` substring → output byte-equals input.
4. `replacements()` counts leaf-level string replacements, not whole-structure rewrites.
