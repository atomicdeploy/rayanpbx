<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Exception;

class EventBroadcastService
{
    /**
     * Broadcast an event to WebSocket clients via Redis
     */
    public function broadcast(string $channel, string $type, array $payload): void
    {
        try {
            $message = [
                'type' => $type,
                'payload' => $payload,
                'timestamp' => now()->toIso8601String(),
            ];

            Redis::publish("rayanpbx:{$channel}", json_encode($message));
        } catch (Exception $e) {
            // Log error but don't fail the request
            logger()->error('Failed to broadcast event', [
                'channel' => $channel,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast extension events
     */
    public function broadcastExtensionCreated(array $extension): void
    {
        $this->broadcast('extensions', 'extension.created', [
            'id' => $extension['id'],
            'extension_number' => $extension['extension_number'],
            'name' => $extension['name'],
            'enabled' => $extension['enabled'],
        ]);
    }

    public function broadcastExtensionUpdated(array $extension): void
    {
        $this->broadcast('extensions', 'extension.updated', [
            'id' => $extension['id'],
            'extension_number' => $extension['extension_number'],
            'name' => $extension['name'],
            'enabled' => $extension['enabled'],
        ]);
    }

    public function broadcastExtensionDeleted(int $id, string $extensionNumber): void
    {
        $this->broadcast('extensions', 'extension.deleted', [
            'id' => $id,
            'extension_number' => $extensionNumber,
        ]);
    }

    /**
     * Broadcast trunk events
     */
    public function broadcastTrunkCreated(array $trunk): void
    {
        $this->broadcast('trunks', 'trunk.created', [
            'id' => $trunk['id'],
            'name' => $trunk['name'],
            'host' => $trunk['host'],
            'enabled' => $trunk['enabled'],
        ]);
    }

    public function broadcastTrunkUpdated(array $trunk): void
    {
        $this->broadcast('trunks', 'trunk.updated', [
            'id' => $trunk['id'],
            'name' => $trunk['name'],
            'host' => $trunk['host'],
            'enabled' => $trunk['enabled'],
        ]);
    }

    public function broadcastTrunkDeleted(int $id, string $name): void
    {
        $this->broadcast('trunks', 'trunk.deleted', [
            'id' => $id,
            'name' => $name,
        ]);
    }

    /**
     * Broadcast status updates
     */
    public function broadcastStatusUpdate(array $status): void
    {
        $this->broadcast('status', 'status.update', $status);
    }

    /**
     * Broadcast call events
     */
    public function broadcastCallStarted(array $call): void
    {
        $this->broadcast('calls', 'call.started', $call);
    }

    public function broadcastCallEnded(array $call): void
    {
        $this->broadcast('calls', 'call.ended', $call);
    }
}
