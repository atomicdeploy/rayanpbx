<?php

namespace App\Helpers;

/**
 * AsteriskSection represents a section in an Asterisk configuration file
 * In Asterisk configs, multiple sections can have the same name but different types
 * (e.g., [101] for endpoint, auth, and aor)
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
        if (!isset($this->properties[$key])) {
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
            $output .= $comment . "\n";
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
        if (!file_exists($filePath)) {
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
        return array_filter($this->sections, fn($s) => $s->name === $name);
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
        $this->sections = array_values(array_filter($this->sections, fn($s) => $s->name !== $name));
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
            $output .= $line . "\n";
        }

        // Add extra newline after header if there are sections
        if (!empty($this->headerLines) && !empty($this->sections)) {
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

/**
 * Helper class for creating common Asterisk config sections
 */
class AsteriskConfigHelper
{
    /**
     * Create the three sections needed for a PJSIP endpoint (endpoint, auth, aor)
     */
    public static function createPjsipEndpointSections(
        string $extNumber,
        string $secret,
        string $context,
        string $transport,
        array $codecs,
        string $directMedia = 'no',
        string $callerID = '',
        int $maxContacts = 1,
        int $qualifyFrequency = 60,
        bool $voicemailEnabled = false
    ): array {
        $sections = [];

        // Endpoint section
        $endpoint = new AsteriskSection($extNumber, 'endpoint');
        $endpoint->setProperty('type', 'endpoint');
        $endpoint->setProperty('context', $context);
        $endpoint->setProperty('disallow', 'all');
        
        foreach ($codecs as $codec) {
            $codec = trim($codec);
            if (!empty($codec)) {
                $endpoint->setProperty('allow', $codec);
            }
        }
        
        $endpoint->setProperty('transport', $transport);
        $endpoint->setProperty('auth', $extNumber);
        $endpoint->setProperty('aors', $extNumber);
        $endpoint->setProperty('direct_media', $directMedia);
        
        if (!empty($callerID)) {
            $endpoint->setProperty('callerid', $callerID);
        }
        
        if ($voicemailEnabled) {
            $endpoint->setProperty('mailboxes', "{$extNumber}@default");
        }
        
        // SIP Presence and Device State support
        $endpoint->setProperty('subscribe_context', $context);
        $endpoint->setProperty('device_state_busy_at', '1');
        
        $sections[] = $endpoint;

        // Auth section
        $auth = new AsteriskSection($extNumber, 'auth');
        $auth->setProperty('type', 'auth');
        $auth->setProperty('auth_type', 'userpass');
        $auth->setProperty('username', $extNumber);
        $auth->setProperty('password', $secret);
        
        $sections[] = $auth;

        // AOR section
        $aor = new AsteriskSection($extNumber, 'aor');
        $aor->setProperty('type', 'aor');
        $aor->setProperty('max_contacts', (string)$maxContacts);
        $aor->setProperty('remove_existing', 'yes');
        $aor->setProperty('qualify_frequency', (string)$qualifyFrequency);
        $aor->setProperty('support_outbound', 'yes');
        
        $sections[] = $aor;

        return $sections;
    }

    /**
     * Create transport sections for UDP and TCP
     */
    public static function createTransportSections(): array
    {
        $sections = [];

        // UDP Transport
        $udp = new AsteriskSection('transport-udp', 'transport');
        $udp->comments = ['; RayanPBX SIP Transports Configuration'];
        $udp->setProperty('type', 'transport');
        $udp->setProperty('protocol', 'udp');
        $udp->setProperty('bind', '0.0.0.0:5060');
        $udp->setProperty('allow_reload', 'yes');
        
        $sections[] = $udp;

        // TCP Transport
        $tcp = new AsteriskSection('transport-tcp', 'transport');
        $tcp->setProperty('type', 'transport');
        $tcp->setProperty('protocol', 'tcp');
        $tcp->setProperty('bind', '0.0.0.0:5060');
        $tcp->setProperty('allow_reload', 'yes');
        
        $sections[] = $tcp;

        return $sections;
    }
}
