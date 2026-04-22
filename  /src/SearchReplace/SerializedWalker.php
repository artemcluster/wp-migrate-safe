<?php
declare(strict_types=1);

namespace WpMigrateSafe\SearchReplace;

/**
 * Recursively walk a PHP value, applying a string transformation to every string leaf
 * (including array keys and object property values).
 */
final class SerializedWalker
{
    /**
     * @param mixed    $value     Already-unserialized value.
     * @param callable $transform (string $leaf) => string
     * @return mixed Transformed value with the same structural type as input.
     */
    public static function walk($value, callable $transform)
    {
        if (is_string($value)) {
            return $transform($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $newKey = is_string($key) ? $transform($key) : $key;
                $result[$newKey] = self::walk($child, $transform);
            }
            return $result;
        }

        if (is_object($value)) {
            // Clone to preserve caller's reference, then rewrite properties.
            $clone = clone $value;
            // Include public + protected + private via Reflection so WC/ACF objects work.
            $reflection = new \ReflectionObject($clone);
            foreach ($reflection->getProperties() as $prop) {
                $prop->setAccessible(true);
                $current = $prop->getValue($clone);
                $new = self::walk($current, $transform);
                $prop->setValue($clone, $new);
            }
            return $clone;
        }

        return $value;
    }
}
