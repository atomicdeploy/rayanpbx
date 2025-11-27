<?php

namespace App\Helpers;

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
            if (! empty($codec)) {
                $endpoint->setProperty('allow', $codec);
            }
        }

        $endpoint->setProperty('transport', $transport);
        $endpoint->setProperty('auth', $extNumber);
        $endpoint->setProperty('aors', $extNumber);
        $endpoint->setProperty('direct_media', $directMedia);

        if (! empty($callerID)) {
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
        $aor->setProperty('max_contacts', (string) $maxContacts);
        $aor->setProperty('remove_existing', 'yes');
        $aor->setProperty('qualify_frequency', (string) $qualifyFrequency);
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
