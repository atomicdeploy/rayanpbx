<?php

namespace App\Helpers;

/**
 * AsteriskSection represents a section in an Asterisk configuration file
 * In Asterisk configs, multiple sections can have the same name but different types
 * (e.g., [101] for endpoint, auth, and aor)
 *
 * Sections can be "commented out" meaning the section header starts with `;[name]`
 * and all properties are prefixed with `;`. This allows disabling extensions without
 * deleting them from the config file.
 */
class AsteriskSection
{
    public string $name;

    public string $type;

    public array $properties = [];

    public array $keys = [];

    public array $comments = [];
    
    /**
     * Comments that appear within the section body (between properties)
     * These are preserved when parsing and writing
     */
    public array $bodyComments = [];

    /**
     * Whether this section is commented out (disabled)
     * A commented section has ;[name] header and ;key=value properties
     */
    public bool $commented = false;

    public function __construct(string $name, string $type = '', bool $commented = false)
    {
        $this->name = $name;
        $this->type = $type;
        $this->commented = $commented;
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
     * Add a body comment (comment within the section)
     */
    public function addBodyComment(string $comment): void
    {
        $this->bodyComments[] = $comment;
    }

    /**
     * Render the section as a config string
     */
    public function toString(): string
    {
        $output = '';
        $prefix = $this->commented ? ';' : '';

        // Write comments (these appear before the section header)
        foreach ($this->comments as $comment) {
            $output .= $comment."\n";
        }

        // Write section header
        $output .= "{$prefix}[{$this->name}]\n";

        // Write properties in order
        foreach ($this->keys as $key) {
            if (isset($this->properties[$key])) {
                $output .= "{$prefix}{$key}={$this->properties[$key]}\n";
            }
        }
        
        // Write body comments at the end of the section
        foreach ($this->bodyComments as $comment) {
            $output .= $comment."\n";
        }

        return $output;
    }
    
    /**
     * Create a copy of this section with commented state toggled
     */
    public function withCommented(bool $commented): self
    {
        $section = new self($this->name, $this->type, $commented);
        $section->properties = $this->properties;
        $section->keys = $this->keys;
        $section->comments = $this->comments;
        $section->bodyComments = $this->bodyComments;
        return $section;
    }
}
