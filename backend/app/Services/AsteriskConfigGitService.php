<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk Configuration Git Service
 * Manages version control for Asterisk configuration files
 * 
 * This service commits changes to /etc/asterisk Git repository
 * for version control, backup, and rollback capabilities.
 */
class AsteriskConfigGitService
{
    private string $asteriskDir;
    private string $gitCommitScript;

    public function __construct()
    {
        $this->asteriskDir = config('rayanpbx.asterisk.config_path', '/etc/asterisk');
        $this->gitCommitScript = '/opt/rayanpbx/scripts/asterisk-git-commit.sh';
    }

    /**
     * Check if /etc/asterisk is a Git repository
     */
    public function isGitRepo(): bool
    {
        return is_dir($this->asteriskDir . '/.git');
    }

    /**
     * Commit changes to the Asterisk configuration Git repository
     *
     * @param string $action Type of change (e.g., "extension-create", "trunk-update")
     * @param string $description Brief description of the change
     * @return bool True if commit succeeded or was skipped (no changes), false on error
     */
    public function commitChange(string $action, string $description): bool
    {
        if (!$this->isGitRepo()) {
            Log::debug('AsteriskConfigGitService: /etc/asterisk is not a Git repository, skipping commit');
            return true; // Not an error - just skip if not a git repo
        }

        try {
            // Try using the helper script first
            if (file_exists($this->gitCommitScript) && is_executable($this->gitCommitScript)) {
                return $this->commitUsingScript($action, $description);
            }

            // Fallback to inline git commit
            return $this->commitInline($action, $description);
        } catch (Exception $e) {
            Log::warning('AsteriskConfigGitService: Failed to commit changes', [
                'action' => $action,
                'description' => $description,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Commit using the helper script
     */
    private function commitUsingScript(string $action, string $description): bool
    {
        $escapedAction = escapeshellarg($action);
        $escapedDescription = escapeshellarg($description);
        
        $command = sprintf(
            'SOURCE="Web API" RAYANPBX_API=1 %s commit %s %s 2>&1',
            escapeshellcmd($this->gitCommitScript),
            $escapedAction,
            $escapedDescription
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::debug('AsteriskConfigGitService: Script commit returned non-zero', [
                'return_code' => $returnCode,
                'output' => implode("\n", $output)
            ]);
        }

        return $returnCode === 0;
    }

    /**
     * Commit inline using git commands directly
     */
    private function commitInline(string $action, string $description): bool
    {
        $originalDir = getcwd();
        
        try {
            if (!chdir($this->asteriskDir)) {
                return true; // Silently skip if we can't change dir
            }

            // Check if there are changes to commit
            $status = shell_exec('git status --porcelain 2>&1');
            if (empty(trim($status ?? ''))) {
                Log::debug('AsteriskConfigGitService: No changes to commit');
                chdir($originalDir);
                return true;
            }

            // Stage all changes
            $addOutput = shell_exec('git add -A 2>&1');
            
            // Build commit message
            $timestamp = date('Y-m-d H:i:s T');
            $user = $_SERVER['REMOTE_USER'] ?? 'api';
            $commitMessage = sprintf(
                "[%s] %s\n\nTimestamp: %s\nSource: Web API\nUser: %s\nWhy: %s",
                $action,
                $description,
                $timestamp,
                $user,
                $description
            );

            $escapedMessage = escapeshellarg($commitMessage);
            $commitOutput = shell_exec("git commit -m {$escapedMessage} 2>&1");
            
            Log::info('AsteriskConfigGitService: Configuration snapshot saved', [
                'action' => $action,
                'description' => $description
            ]);

            return true;
        } finally {
            chdir($originalDir);
        }
    }

    /**
     * Get recent commit history
     *
     * @param int $count Number of commits to return
     * @return array Array of commit information
     */
    public function getHistory(int $count = 10): array
    {
        if (!$this->isGitRepo()) {
            return [];
        }

        $originalDir = getcwd();
        try {
            chdir($this->asteriskDir);
            
            $output = shell_exec(sprintf(
                'git log --oneline -n %d --format="%%H|%%ad|%%s" --date=short 2>&1',
                $count
            ));
            
            if (empty($output)) {
                return [];
            }

            $commits = [];
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) === 3) {
                    $commits[] = [
                        'hash' => $parts[0],
                        'date' => $parts[1],
                        'message' => $parts[2]
                    ];
                }
            }

            return $commits;
        } finally {
            chdir($originalDir);
        }
    }

    /**
     * Get status of the repository
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        $status = [
            'is_repo' => $this->isGitRepo(),
            'has_changes' => false,
            'commit_count' => 0,
            'last_commit' => null
        ];

        if (!$status['is_repo']) {
            return $status;
        }

        $originalDir = getcwd();
        try {
            chdir($this->asteriskDir);

            // Check for uncommitted changes
            $pendingChanges = shell_exec('git status --porcelain 2>&1');
            $status['has_changes'] = !empty(trim($pendingChanges ?? ''));

            // Get commit count
            $commitCount = shell_exec('git rev-list --count HEAD 2>&1');
            $status['commit_count'] = intval(trim($commitCount ?? '0'));

            // Get last commit info
            $lastCommit = shell_exec('git log -1 --format="%H|%ad|%s" --date=short 2>&1');
            if (!empty($lastCommit)) {
                $parts = explode('|', trim($lastCommit), 3);
                if (count($parts) === 3) {
                    $status['last_commit'] = [
                        'hash' => $parts[0],
                        'date' => $parts[1],
                        'message' => $parts[2]
                    ];
                }
            }

            return $status;
        } finally {
            chdir($originalDir);
        }
    }
}
