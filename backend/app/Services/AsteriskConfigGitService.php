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
     * Execute a callback in the asterisk directory context
     * Handles directory change and restoration automatically
     * 
     * @param callable $callback Function to execute in the asterisk directory
     * @param mixed $defaultValue Value to return if directory change fails
     * @return mixed Result of the callback or default value
     */
    private function inAsteriskDir(callable $callback, mixed $defaultValue = null): mixed
    {
        $originalDir = getcwd();
        if ($originalDir === false) {
            $originalDir = null;
        }

        try {
            if (!@chdir($this->asteriskDir)) {
                return $defaultValue;
            }

            return $callback();
        } finally {
            if ($originalDir !== null) {
                @chdir($originalDir);
            }
        }
    }

    /**
     * Check if the repository has uncommitted changes (is "dirty")
     * 
     * @return bool True if there are uncommitted changes
     */
    public function isDirty(): bool
    {
        if (!$this->isGitRepo()) {
            return false;
        }

        return $this->inAsteriskDir(function () {
            $status = shell_exec('git status --porcelain 2>&1');
            return !empty(trim($status ?? ''));
        }, false);
    }

    /**
     * Get detailed dirty state information
     * 
     * @return array{is_dirty: bool, change_count: int, message: string, changes: array}
     */
    public function getDirtyState(): array
    {
        if (!$this->isGitRepo()) {
            return [
                'is_dirty' => false,
                'change_count' => 0,
                'message' => 'Not a Git repository',
                'changes' => []
            ];
        }

        $defaultResult = [
            'is_dirty' => false,
            'change_count' => 0,
            'message' => 'Could not access repository',
            'changes' => []
        ];

        return $this->inAsteriskDir(function () {
            $status = shell_exec('git status --porcelain 2>&1');
            $status = trim($status ?? '');

            if (empty($status)) {
                return [
                    'is_dirty' => false,
                    'change_count' => 0,
                    'message' => 'Clean (all changes committed)',
                    'changes' => []
                ];
            }

            // Parse the status output
            $changes = array_filter(explode("\n", $status));
            $changeCount = count($changes);

            return [
                'is_dirty' => true,
                'change_count' => $changeCount,
                'message' => "Dirty ({$changeCount} uncommitted change(s))",
                'changes' => $changes
            ];
        }, $defaultResult);
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
        $result = $this->commitWithDetails($action, $description);
        return $result['success'];
    }

    /**
     * Commit changes with detailed result information
     *
     * @param string $action Type of change (e.g., "extension-create", "trunk-update")
     * @param string $description Brief description of the change
     * @return array{success: bool, message: string, still_dirty: bool}
     */
    public function commitWithDetails(string $action, string $description): array
    {
        if (!$this->isGitRepo()) {
            Log::debug('AsteriskConfigGitService: /etc/asterisk is not a Git repository, skipping commit');
            return [
                'success' => true,
                'message' => 'Not a Git repository - skipped',
                'still_dirty' => false
            ];
        }

        try {
            // Try using the helper script first
            if (file_exists($this->gitCommitScript) && is_executable($this->gitCommitScript)) {
                $result = $this->commitUsingScript($action, $description);
            } else {
                // Fallback to inline git commit
                $result = $this->commitInline($action, $description);
            }

            // Verify commit was successful by checking if repo is still dirty
            $stillDirty = $this->isDirty();
            if ($stillDirty) {
                Log::warning('AsteriskConfigGitService: Repository still has uncommitted changes after commit', [
                    'action' => $action,
                    'dirty_state' => $this->getDirtyState()
                ]);
            }

            return [
                'success' => $result,
                'message' => $result ? 'Changes committed successfully' : 'Commit failed',
                'still_dirty' => $stillDirty
            ];
        } catch (Exception $e) {
            Log::warning('AsteriskConfigGitService: Failed to commit changes', [
                'action' => $action,
                'description' => $description,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Commit failed: ' . $e->getMessage(),
                'still_dirty' => $this->isDirty()
            ];
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
        
        // Check if we could get the current directory
        if ($originalDir === false) {
            $originalDir = null;
        }
        
        try {
            if (!chdir($this->asteriskDir)) {
                return true; // Silently skip if we can't change dir
            }

            // Check if there are changes to commit
            $status = shell_exec('git status --porcelain 2>&1');
            if (empty(trim($status ?? ''))) {
                Log::debug('AsteriskConfigGitService: No changes to commit');
                if ($originalDir !== null) {
                    @chdir($originalDir);
                }
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
            
            // Verify commit succeeded by checking if there are still uncommitted changes
            $postCommitStatus = shell_exec('git status --porcelain 2>&1');
            if (!empty(trim($postCommitStatus ?? ''))) {
                Log::warning('AsteriskConfigGitService: Repository still dirty after commit', [
                    'remaining_changes' => trim($postCommitStatus)
                ]);
            }
            
            Log::info('AsteriskConfigGitService: Configuration snapshot saved', [
                'action' => $action,
                'description' => $description
            ]);

            return true;
        } finally {
            if ($originalDir !== null) {
                @chdir($originalDir);
            }
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

        return $this->inAsteriskDir(function () use ($count) {
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
        }, []);
    }

    /**
     * Get status of the repository
     *
     * @return array Status information including dirty state
     */
    public function getStatus(): array
    {
        $status = [
            'is_repo' => $this->isGitRepo(),
            'has_changes' => false,
            'is_dirty' => false,
            'change_count' => 0,
            'commit_count' => 0,
            'last_commit' => null,
            'uncommitted_changes' => []
        ];

        if (!$status['is_repo']) {
            return $status;
        }

        return $this->inAsteriskDir(function () use ($status) {
            // Check for uncommitted changes
            $pendingChanges = shell_exec('git status --porcelain 2>&1');
            $pendingChanges = trim($pendingChanges ?? '');
            $status['has_changes'] = !empty($pendingChanges);
            $status['is_dirty'] = !empty($pendingChanges);
            
            if (!empty($pendingChanges)) {
                $changes = array_filter(explode("\n", $pendingChanges));
                $status['change_count'] = count($changes);
                $status['uncommitted_changes'] = $changes;
            }

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
        }, $status);
    }
}
