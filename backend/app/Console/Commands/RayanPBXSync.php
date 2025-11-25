<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Extension;
use App\Adapters\AsteriskAdapter;
use Exception;

class RayanPBXSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rayanpbx:sync {action} {extension?} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync extensions between database and Asterisk (status|db-to-asterisk|asterisk-to-db|all-db|all-asterisk)';

    private $asterisk;
    private $pjsipConfigPath;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->asterisk = app(AsteriskAdapter::class);
        $this->pjsipConfigPath = config('rayanpbx.asterisk.pjsip_config', '/etc/asterisk/pjsip.conf');
        
        $action = $this->argument('action');
        $extensionNumber = $this->argument('extension');

        $validActions = ['status', 'db-to-asterisk', 'asterisk-to-db', 'all-db', 'all-asterisk'];

        if (!in_array($action, $validActions)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: " . implode(', ', $validActions));
            return 1;
        }

        try {
            switch ($action) {
                case 'status':
                    return $this->showSyncStatus();
                    
                case 'db-to-asterisk':
                    return $this->syncDbToAsterisk($extensionNumber);
                    
                case 'asterisk-to-db':
                    return $this->syncAsteriskToDb($extensionNumber);
                    
                case 'all-db':
                    return $this->syncAllDbToAsterisk();
                    
                case 'all-asterisk':
                    return $this->syncAllAsteriskToDb();
            }
        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Show sync status
     */
    private function showSyncStatus(): int
    {
        $dbExtensions = Extension::orderBy('extension_number')->get();
        $asteriskExtensions = $this->parsePjsipConfig();
        $liveStatus = $this->getLiveStatus();

        // Build comparison
        $allExtensions = [];
        foreach ($dbExtensions as $ext) {
            $allExtensions[$ext->extension_number] = true;
        }
        foreach ($asteriskExtensions as $ext) {
            $allExtensions[$ext['extension_number']] = true;
        }

        $data = [];
        $matched = 0;
        $dbOnly = 0;
        $astOnly = 0;
        $mismatched = 0;

        foreach (array_keys($allExtensions) as $extNum) {
            $dbExt = $dbExtensions->firstWhere('extension_number', $extNum);
            $astExt = collect($asteriskExtensions)->firstWhere('extension_number', $extNum);
            $registered = $liveStatus[$extNum] ?? false;

            $source = 'Both';
            $status = '✅ Synced';
            $differences = [];

            if ($dbExt && $astExt) {
                $differences = $this->findDifferences($dbExt, $astExt);
                if (count($differences) > 0) {
                    $status = '⚠️  Mismatch';
                    $mismatched++;
                } else {
                    $matched++;
                }
            } elseif ($dbExt) {
                $source = 'DB Only';
                $status = '📦 DB Only';
                $dbOnly++;
            } else {
                $source = 'Asterisk';
                $status = '⚡ Asterisk Only';
                $astOnly++;
            }

            $data[] = [
                'extension' => $extNum,
                'name' => $dbExt?->name ?? "Extension {$extNum}",
                'source' => $source,
                'status' => $status,
                'registered' => $registered ? '📞 Yes' : 'No',
                'differences' => implode(', ', $differences) ?: '-',
            ];
        }

        // Show summary
        $this->newLine();
        $this->info('📊 Extension Sync Status');
        $this->newLine();

        $this->table(
            ['Extension', 'Name', 'Source', 'Status', 'Registered', 'Differences'],
            $data
        );

        $this->newLine();
        $this->info('Summary:');
        $this->line("  Total: " . count($allExtensions));
        $this->line("  ✅ Synced: {$matched}");
        $this->line("  📦 DB Only: {$dbOnly}");
        $this->line("  ⚡ Asterisk Only: {$astOnly}");
        $this->line("  ⚠️  Mismatched: {$mismatched}");

        return 0;
    }

    /**
     * Sync single extension from DB to Asterisk
     */
    private function syncDbToAsterisk(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number to sync');
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        if (!$extension) {
            $this->error("Extension {$extensionNumber} not found in database");
            return 1;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("Sync extension {$extensionNumber} from database to Asterisk?")) {
                $this->info('Cancelled');
                return 0;
            }
        }

        $config = $this->asterisk->generatePjsipEndpoint($extension);
        $success = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");

        if (!$success) {
            $this->error('Failed to write PJSIP configuration');
            return 1;
        }

        $this->asterisk->reload();
        $this->info("✓ Extension {$extensionNumber} synced to Asterisk");

        return 0;
    }

    /**
     * Sync single extension from Asterisk to DB
     */
    private function syncAsteriskToDb(?string $extensionNumber): int
    {
        if (!$extensionNumber) {
            $extensionNumber = $this->ask('Extension number to sync');
        }

        $asteriskExtensions = $this->parsePjsipConfig();
        $astExt = collect($asteriskExtensions)->firstWhere('extension_number', $extensionNumber);

        if (!$astExt) {
            $this->error("Extension {$extensionNumber} not found in Asterisk config");
            return 1;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("Sync extension {$extensionNumber} from Asterisk to database?")) {
                $this->info('Cancelled');
                return 0;
            }
        }

        $extension = Extension::where('extension_number', $extensionNumber)->first();

        $data = [
            'extension_number' => $extensionNumber,
            'name' => $extension?->name ?? "Extension {$extensionNumber}",
            'context' => $astExt['context'] ?? 'from-internal',
            'transport' => $astExt['transport'] ?? 'transport-udp',
            'max_contacts' => $astExt['max_contacts'] ?? 1,
            'qualify_frequency' => $astExt['qualify_frequency'] ?? 60,
            'direct_media' => $astExt['direct_media'] ?? 'no',
            'codecs' => $astExt['codecs'] ?? ['ulaw', 'alaw', 'g722'],
            'enabled' => true,
        ];

        if (!empty($astExt['secret'])) {
            $data['secret'] = $astExt['secret'];
        }

        Extension::updateOrCreate(
            ['extension_number' => $extensionNumber],
            $data
        );

        $this->info("✓ Extension {$extensionNumber} synced to database");

        return 0;
    }

    /**
     * Sync all DB extensions to Asterisk
     */
    private function syncAllDbToAsterisk(): int
    {
        $extensions = Extension::where('enabled', true)->get();

        if ($extensions->isEmpty()) {
            $this->warn('No enabled extensions found in database');
            return 0;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("Sync all {$extensions->count()} enabled extensions from database to Asterisk?")) {
                $this->info('Cancelled');
                return 0;
            }
        }

        $synced = 0;
        $errors = [];

        foreach ($extensions as $extension) {
            try {
                $config = $this->asterisk->generatePjsipEndpoint($extension);
                $success = $this->asterisk->writePjsipConfig($config, "Extension {$extension->extension_number}");

                if ($success) {
                    $synced++;
                    $this->line("  ✓ {$extension->extension_number}");
                } else {
                    $errors[] = $extension->extension_number;
                    $this->line("  ✗ {$extension->extension_number}: Failed to write config");
                }
            } catch (Exception $e) {
                $errors[] = $extension->extension_number;
                $this->line("  ✗ {$extension->extension_number}: {$e->getMessage()}");
            }
        }

        $this->asterisk->reload();
        
        $this->newLine();
        $this->info("Synced {$synced} extensions to Asterisk");
        if (count($errors) > 0) {
            $this->warn(count($errors) . " extensions failed");
        }

        return count($errors) > 0 ? 1 : 0;
    }

    /**
     * Sync all Asterisk extensions to DB
     */
    private function syncAllAsteriskToDb(): int
    {
        $asteriskExtensions = $this->parsePjsipConfig();

        if (empty($asteriskExtensions)) {
            $this->warn('No extensions found in Asterisk config');
            return 0;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("Sync all " . count($asteriskExtensions) . " extensions from Asterisk to database?")) {
                $this->info('Cancelled');
                return 0;
            }
        }

        $synced = 0;
        $errors = [];

        foreach ($asteriskExtensions as $astExt) {
            try {
                $extension = Extension::where('extension_number', $astExt['extension_number'])->first();

                $data = [
                    'extension_number' => $astExt['extension_number'],
                    'name' => $extension?->name ?? "Extension {$astExt['extension_number']}",
                    'context' => $astExt['context'] ?? 'from-internal',
                    'transport' => $astExt['transport'] ?? 'transport-udp',
                    'max_contacts' => $astExt['max_contacts'] ?? 1,
                    'qualify_frequency' => $astExt['qualify_frequency'] ?? 60,
                    'direct_media' => $astExt['direct_media'] ?? 'no',
                    'codecs' => $astExt['codecs'] ?? ['ulaw', 'alaw', 'g722'],
                    'enabled' => true,
                ];

                if (!empty($astExt['secret'])) {
                    $data['secret'] = $astExt['secret'];
                }

                Extension::updateOrCreate(
                    ['extension_number' => $astExt['extension_number']],
                    $data
                );

                $synced++;
                $this->line("  ✓ {$astExt['extension_number']}");
            } catch (Exception $e) {
                $errors[] = $astExt['extension_number'];
                $this->line("  ✗ {$astExt['extension_number']}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Synced {$synced} extensions to database");
        if (count($errors) > 0) {
            $this->warn(count($errors) . " extensions failed");
        }

        return count($errors) > 0 ? 1 : 0;
    }

    /**
     * Parse pjsip.conf and extract extensions
     */
    private function parsePjsipConfig(): array
    {
        if (!file_exists($this->pjsipConfigPath)) {
            return [];
        }

        $content = file_get_contents($this->pjsipConfigPath);
        if (!$content) {
            return [];
        }

        $extensions = [];
        $lines = explode("\n", $content);
        $currentSection = null;
        $currentType = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, ';')) {
                continue;
            }

            if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                $currentSection = substr($line, 1, -1);
                $currentType = null;
                continue;
            }

            if (!$currentSection || $currentSection === 'global' || str_starts_with($currentSection, 'transport-')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === 'type') {
                $currentType = $value;
                continue;
            }

            if ($currentType === 'identify') {
                continue;
            }

            if (!preg_match('/^\d+$/', $currentSection)) {
                continue;
            }

            if (!isset($extensions[$currentSection])) {
                $extensions[$currentSection] = [
                    'extension_number' => $currentSection,
                    'max_contacts' => 1,
                    'qualify_frequency' => 60,
                    'direct_media' => 'no',
                    'codecs' => [],
                ];
            }

            switch ($currentType) {
                case 'endpoint':
                    switch ($key) {
                        case 'context':
                            $extensions[$currentSection]['context'] = $value;
                            break;
                        case 'transport':
                            $extensions[$currentSection]['transport'] = $value;
                            break;
                        case 'allow':
                            $extensions[$currentSection]['codecs'][] = $value;
                            break;
                        case 'callerid':
                            $extensions[$currentSection]['caller_id'] = $value;
                            break;
                        case 'direct_media':
                            $extensions[$currentSection]['direct_media'] = $value;
                            break;
                    }
                    break;
                case 'auth':
                    if ($key === 'password') {
                        $extensions[$currentSection]['secret'] = $value;
                    }
                    break;
                case 'aor':
                    switch ($key) {
                        case 'max_contacts':
                            $extensions[$currentSection]['max_contacts'] = (int) $value;
                            break;
                        case 'qualify_frequency':
                            $extensions[$currentSection]['qualify_frequency'] = (int) $value;
                            break;
                    }
                    break;
            }
        }

        return array_values($extensions);
    }

    /**
     * Get live registration status from Asterisk
     */
    private function getLiveStatus(): array
    {
        $endpoints = $this->asterisk->getAllPjsipEndpoints();
        $status = [];

        foreach ($endpoints as $endpoint) {
            $name = $endpoint['name'] ?? '';
            if (preg_match('/^\d+$/', $name)) {
                $status[$name] = ($endpoint['state'] ?? '') !== 'Unavailable';
            }
        }

        return $status;
    }

    /**
     * Find differences between DB and Asterisk extension
     */
    private function findDifferences($dbExt, $astExt): array
    {
        $diffs = [];

        $dbContext = $dbExt->context ?? 'from-internal';
        $astContext = $astExt['context'] ?? 'from-internal';
        if ($dbContext !== $astContext) {
            $diffs[] = "Context";
        }

        $dbTransport = $dbExt->transport ?? 'transport-udp';
        $astTransport = $astExt['transport'] ?? 'transport-udp';
        if ($dbTransport !== $astTransport) {
            $diffs[] = "Transport";
        }

        $dbMaxContacts = $dbExt->max_contacts ?? 1;
        $astMaxContacts = $astExt['max_contacts'] ?? 1;
        if ($dbMaxContacts !== $astMaxContacts) {
            $diffs[] = "MaxContacts";
        }

        $dbDirectMedia = $dbExt->direct_media ?? 'no';
        $astDirectMedia = $astExt['direct_media'] ?? 'no';
        if ($dbDirectMedia !== $astDirectMedia) {
            $diffs[] = "DirectMedia";
        }

        return $diffs;
    }
}
