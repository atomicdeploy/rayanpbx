<?php

namespace App\Helpers;

/**
 * AsteriskConfig represents an Asterisk configuration file
 * Supports both active and commented sections for extension toggling
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
     * Supports both active sections [name] and commented sections ;[name]
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

            // Check for commented section header: ;[name]
            if (preg_match('/^\s*;\s*\[([^\]]+)\]/', $line, $matches)) {
                // Save current section if any
                if ($currentSection !== null) {
                    $config->sections[] = $currentSection;
                }

                // Start new commented section
                $sectionName = $matches[1];
                $currentSection = new AsteriskSection($sectionName, '', true);
                $currentSection->comments = $pendingComments;
                $pendingComments = [];
                $inHeader = false;

                continue;
            }

            // Check for active section header: [name]
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $matches)) {
                // Save current section if any
                if ($currentSection !== null) {
                    $config->sections[] = $currentSection;
                }

                // Start new active section
                $sectionName = $matches[1];
                $currentSection = new AsteriskSection($sectionName, '', false);
                $currentSection->comments = $pendingComments;
                $pendingComments = [];
                $inHeader = false;

                continue;
            }

            // Check for commented key=value in a commented section: ;key=value
            if ($currentSection !== null && $currentSection->commented) {
                if (preg_match('/^\s*;\s*([^=;\s]+)\s*=\s*(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);

                    // If it's a type key, set the section type
                    if ($key === 'type') {
                        $currentSection->type = $value;
                    }

                    $currentSection->setProperty($key, $value);
                    continue;
                }
                
                // Preserve other comment lines as body comments
                if (str_starts_with($trimmedLine, ';') && $trimmedLine !== ';') {
                    $currentSection->addBodyComment($line);
                    continue;
                }
            }

            // Check for active key=value in an active section
            if (preg_match('/^\s*([^=;\s]+)\s*=\s*(.*)$/', $line, $matches) && $currentSection !== null && !$currentSection->commented) {
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
                } elseif ($currentSection !== null && !$currentSection->commented) {
                    // Comments within an active section - preserve as body comments
                    $currentSection->addBodyComment($line);
                }
            }
        }

        // Don't forget the last section
        if ($currentSection !== null) {
            $config->sections[] = $currentSection;
        }

        return $config;
    }

    /**
     * Find all sections with a given name (both active and commented)
     */
    public function findSectionsByName(string $name): array
    {
        return array_filter($this->sections, fn ($s) => $s->name === $name);
    }
    
    /**
     * Find all active (not commented) sections with a given name
     */
    public function findActiveSectionsByName(string $name): array
    {
        return array_values(array_filter($this->sections, fn ($s) => $s->name === $name && !$s->commented));
    }
    
    /**
     * Find all commented sections with a given name
     */
    public function findCommentedSectionsByName(string $name): array
    {
        return array_values(array_filter($this->sections, fn ($s) => $s->name === $name && $s->commented));
    }
    
    /**
     * Check if there's an active section with the given name
     */
    public function hasActiveSection(string $name): bool
    {
        foreach ($this->sections as $section) {
            if ($section->name === $name && !$section->commented) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if there's a commented section with the given name
     */
    public function hasCommentedSection(string $name): bool
    {
        foreach ($this->sections as $section) {
            if ($section->name === $name && $section->commented) {
                return true;
            }
        }
        return false;
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
     * Remove all sections with a given name (both active and commented)
     */
    public function removeSectionsByName(string $name): int
    {
        $original = count($this->sections);
        $this->sections = array_values(array_filter($this->sections, fn ($s) => $s->name !== $name));

        return $original - count($this->sections);
    }
    
    /**
     * Remove only active (not commented) sections with a given name
     */
    public function removeActiveSectionsByName(string $name): int
    {
        $original = count($this->sections);
        $this->sections = array_values(array_filter($this->sections, fn ($s) => !($s->name === $name && !$s->commented)));

        return $original - count($this->sections);
    }
    
    /**
     * Remove only commented sections with a given name
     */
    public function removeCommentedSectionsByName(string $name): int
    {
        $original = count($this->sections);
        $this->sections = array_values(array_filter($this->sections, fn ($s) => !($s->name === $name && $s->commented)));

        return $original - count($this->sections);
    }
    
    /**
     * Comment out all active sections with a given name
     * This effectively disables the extension without removing it
     */
    public function commentOutSectionsByName(string $name): int
    {
        $count = 0;
        foreach ($this->sections as $i => $section) {
            if ($section->name === $name && !$section->commented) {
                $this->sections[$i] = $section->withCommented(true);
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * Uncomment all commented sections with a given name
     * This effectively re-enables a disabled extension
     */
    public function uncommentSectionsByName(string $name): int
    {
        $count = 0;
        foreach ($this->sections as $i => $section) {
            if ($section->name === $name && $section->commented) {
                $this->sections[$i] = $section->withCommented(false);
                $count++;
            }
        }
        return $count;
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
     * Check if a section with the given name exists (active or commented)
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
