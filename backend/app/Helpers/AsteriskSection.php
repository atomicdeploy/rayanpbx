<?php

namespace App\Helpers;

/**
 * AsteriskSection represents a section in an Asterisk configuration file
 * In Asterisk configs, multiple sections can have the same name but different types
 * (e.g., [101] for endpoint, auth, and aor)
 *
 * NOTE: Comments within a section body (between the section header and the next section)
 * are not preserved during parsing. Only comments that appear immediately before a section
 * header are captured in the $comments property.
 */
class AsteriskSection
{
    public string $name;

    public string $type;

    public array $properties = [];

    public array $keys = [];

    public array $comments = [];

    public function __construct(string $name, string $type = '')
    {
        $this->name = $name;
        $this->type = $type;
    }

    /**
     * Set a property value (maintains order for new keys)
     */
    public function setProperty(string $key, string $value): void
    {
        if (! isset($this->properties[$key])) {
            $this->keys[] = $key;
        }
        $this->properties[$key] = $value;
    }

    /**
     * Get a property value
     */
    public function getProperty(string $key): ?string
    {
        return $this->properties[$key] ?? null;
    }

    /**
     * Check if a property exists
     */
    public function hasProperty(string $key): bool
    {
        return isset($this->properties[$key]);
    }

    /**
     * Render the section as a config string
     */
    public function toString(): string
    {
        $output = '';

        // Write comments
        foreach ($this->comments as $comment) {
            $output .= $comment."\n";
        }

        // Write section header
        $output .= "[{$this->name}]\n";

        // Write properties in order
        foreach ($this->keys as $key) {
            if (isset($this->properties[$key])) {
                $output .= "{$key}={$this->properties[$key]}\n";
            }
        }

        return $output;
    }
}
