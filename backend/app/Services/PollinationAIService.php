<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PollinationAIService
{
    private string $apiUrl = 'https://text.pollinations.ai';
    private int $timeout = 30;

    /**
     * Get helpful explanation from Pollination AI
     */
    public function explain(string $topic, string $context = '', string $language = 'en'): array
    {
        $cacheKey = 'pollination:' . md5($topic . $context . $language);
        
        // Check cache first (24 hour expiry)
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $prompt = $this->buildPrompt($topic, $context, $language);
            
            $response = Http::timeout($this->timeout)
                ->get($this->apiUrl, [
                    'prompt' => $prompt,
                    'model' => 'openai',
                ]);

            if ($response->successful()) {
                $text = $response->body();
                $result = [
                    'success' => true,
                    'explanation' => $this->formatExplanation($text),
                    'raw' => $text,
                ];
                
                // Cache the result
                Cache::put($cacheKey, $result, now()->addHours(24));
                
                return $result;
            }

            return $this->fallbackExplanation($topic);
            
        } catch (\Exception $e) {
            Log::error('PollinationAI error: ' . $e->getMessage());
            return $this->fallbackExplanation($topic);
        }
    }

    /**
     * Build prompt for Pollination AI
     */
    private function buildPrompt(string $topic, string $context, string $language): string
    {
        $langInstruction = $language === 'fa' ? 'Explain in Persian (Farsi):' : 'Explain in simple English:';
        
        $prompt = "{$langInstruction}\n\n";
        $prompt .= "Topic: {$topic}\n";
        
        if ($context) {
            $prompt .= "Context: {$context}\n";
        }
        
        $prompt .= "\nProvide a clear, concise explanation (2-3 sentences) that a non-technical user can understand. ";
        $prompt .= "Focus on what it does and why it's useful for VoIP/SIP systems.";
        
        return $prompt;
    }

    /**
     * Format the AI explanation
     */
    private function formatExplanation(string $text): string
    {
        // Clean up the response
        $text = trim($text);
        
        // Remove any markdown formatting
        $text = preg_replace('/[*_#]/u', '', $text);
        
        // Limit to reasonable length
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 500) . '...';
        }
        
        return $text;
    }

    /**
     * Fallback explanations when AI is unavailable
     */
    private function fallbackExplanation(string $topic): array
    {
        $explanations = [
            'extension' => 'A SIP extension is like a phone number within your phone system. Each extension (e.g., 100, 101) represents a device or softphone that can make and receive calls.',
            'trunk' => 'A SIP trunk is the connection to your phone service provider. It allows you to make and receive calls from the outside world, similar to a phone line but over the internet.',
            'codec' => 'A codec determines how voice is compressed during a call. Higher quality codecs (like HD codecs) provide clearer audio but use more bandwidth.',
            'context' => 'A context is a set of rules that defines what an extension can do - like which numbers it can dial and what features it can use.',
            'transport' => 'Transport defines how SIP messages are sent: UDP (fast, connectionless), TCP (reliable, connection-based), or TLS (encrypted, secure).',
            'caller_id' => 'Caller ID is the name and number displayed when you make a call. It helps recipients identify who is calling them.',
            'nat' => 'NAT (Network Address Translation) settings help your phone system work correctly when devices are behind routers or firewalls.',
            'voicemail' => 'Voicemail allows callers to leave messages when you are unavailable. Messages are stored and can be retrieved later.',
            'dialplan' => 'A dialplan is a set of instructions that tells the system how to route calls. It determines what happens when someone dials a number.',
            'registration' => 'Registration is how a SIP device tells the server "I am here and ready to receive calls". Without registration, calls cannot reach the device.',
            'qualify' => 'Qualify checks if a device is reachable by sending periodic test messages. It helps detect if a phone has disconnected.',
            'dtmf' => 'DTMF (Dual-Tone Multi-Frequency) are the tones you hear when pressing phone keypad buttons. They are used for IVR menus and voicemail navigation.',
            'rtp' => 'RTP (Real-time Transport Protocol) carries the actual voice audio during a call. While SIP sets up the call, RTP transmits the conversation.',
            'sip_port' => 'SIP port (usually 5060) is where the server listens for incoming connections. Devices must connect to this port to communicate.',
            'rtp_port' => 'RTP ports (usually 10000-20000) are used to transmit voice audio during active calls. Firewalls need to allow these ports.',
        ];

        $key = strtolower(str_replace(' ', '_', $topic));
        $explanation = $explanations[$key] ?? "Configuration option for {$topic} in your VoIP system. This setting helps control how calls are processed and managed.";

        return [
            'success' => true,
            'explanation' => $explanation,
            'raw' => $explanation,
            'fallback' => true,
        ];
    }

    /**
     * Get explanation for error messages
     */
    public function explainError(string $error, string $context = ''): array
    {
        return $this->explain("SIP/VoIP error: {$error}", $context);
    }

    /**
     * Get explanation for codec
     */
    public function explainCodec(string $codec): array
    {
        $context = "This is a voice codec used in VoIP systems. Include: quality (narrowband/wideband/HD), typical bandwidth usage, and best use case.";
        return $this->explain($codec, $context);
    }

    /**
     * Get help for configuration field
     */
    public function getFieldHelp(string $field, string $currentValue = ''): array
    {
        $context = $currentValue ? "Current value: {$currentValue}" : '';
        return $this->explain($field, $context);
    }

    /**
     * Batch explanations for multiple fields
     */
    public function explainBatch(array $topics, string $language = 'en'): array
    {
        $results = [];
        
        foreach ($topics as $topic) {
            $results[$topic] = $this->explain($topic, '', $language);
        }
        
        return $results;
    }
}
