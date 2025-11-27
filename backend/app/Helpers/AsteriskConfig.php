<?php

namespace App\Helpers;

/**
 * AsteriskConfig represents an Asterisk configuration file
 */
class AsteriskConfig
{
    public array $sections = [];

    public array $headerLines = [];

    public string $filePath;

    public function __construct(string $filePath = '')
    {
        $this->filePath = $filePath;
    }

    /**
     * Parse an Asterisk configuration file
     */
    public static function parseFile(string $filePath): ?self
    {
        if (! file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        return self::parseContent($content, $filePath);
    }

    /**
     * Parse Asterisk config from string content
     */
    public static function parseContent(string $content, string $filePath = ''): self
    {
        $config = new self($filePath);
        $lines = explode("\n", $content);

        $currentSection = null;
        $pendingComments = [];
        $inHeader = true;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Check for section header
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $matches)) {
                // Save current section if any
                if ($currentSection !== null) {
                    $config->sections[] = $currentSection;
                }

                // Start new section
                $sectionName = $matches[1];
                $currentSection = new AsteriskSection($sectionName);
                $currentSection->comments = $pendingComments;
                $pendingComments = [];
                $inHeader = false;

                continue;
            }

            // Check for key=value
            if (preg_match('/^\s*([^=;\s]+)\s*=\s*(.*)$/', $line, $matches) && $currentSection !== null) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);

                // If it's a type key, set the section type
                if ($key === 'type') {
                    $currentSection->type = $value;
                }

                $currentSection->setProperty($key, $value);

                continue;
            }

            // Handle comments and blank lines
            if (str_starts_with($trimmedLine, ';') || $trimmedLine === '') {
                if ($inHeader) {
                    $config->headerLines[] = $line;
                } elseif ($currentSection === null) {
                    // Comments before any section after header
                    $pendingComments[] = $line;
                }
                // Comments within a section are ignored for simplicity
            }
        }

        // Don't forget the last section
        if ($currentSection !== null) {
            $config->sections[] = $currentSection;
        }

        return $config;
    }

    /**
     * Find all sections with a given name
     */
    public function findSectionsByName(string $name): array
    {
        return array_filter($this->sections, fn ($s) => $s->name === $name);
    }

    /**
     * Find a section with a specific name and type
     */
    public function findSectionByNameAndType(string $name, string $type): ?AsteriskSection
    {
        foreach ($this->sections as $section) {
            if ($section->name === $name && $section->type === $type) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Remove all sections with a given name
     */
    public function removeSectionsByName(string $name): int
    {
        $original = count($this->sections);
        $this->sections = array_values(array_filter($this->sections, fn ($s) => $s->name !== $name));

        return $original - count($this->sections);
    }

    /**
     * Remove a specific section by name and type
     */
    public function removeSectionByNameAndType(string $name, string $type): bool
    {
        foreach ($this->sections as $i => $section) {
            if ($section->name === $name && $section->type === $type) {
                array_splice($this->sections, $i, 1);

                return true;
            }
        }

        return false;
    }

    /**
     * Add a section to the configuration
     */
    public function addSection(AsteriskSection $section): void
    {
        $this->sections[] = $section;
    }

    /**
     * Add or replace a section with the same name and type
     */
    public function addOrReplaceSection(AsteriskSection $section): void
    {
        foreach ($this->sections as $i => $s) {
            if ($s->name === $section->name && $s->type === $section->type) {
                $this->sections[$i] = $section;

                return;
            }
        }
        $this->sections[] = $section;
    }

    /**
     * Check if a section with the given name exists
     */
    public function hasSection(string $name): bool
    {
        foreach ($this->sections as $section) {
            if ($section->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a section with the given name and type exists
     */
    public function hasSectionWithType(string $name, string $type): bool
    {
        foreach ($this->sections as $section) {
            if ($section->name === $name && $section->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the entire configuration as a string
     */
    public function toString(): string
    {
        $output = '';

        // Write header lines
        foreach ($this->headerLines as $line) {
            $output .= $line."\n";
        }

        // Add extra newline after header if there are sections
        if (! empty($this->headerLines) && ! empty($this->sections)) {
            $output .= "\n";
        }

        // Write sections
        foreach ($this->sections as $i => $section) {
            $output .= $section->toString();
            // Add blank line between sections
            if ($i < count($this->sections) - 1) {
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Save the configuration to the file
     */
    public function save(): bool
    {
        if (empty($this->filePath)) {
            return false;
        }

        return file_put_contents($this->filePath, $this->toString()) !== false;
    }

    /**
     * Save the configuration to a specific file
     */
    public function saveTo(string $filePath): bool
    {
        return file_put_contents($filePath, $this->toString()) !== false;
    }
}
