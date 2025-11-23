<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PollinationAIService;
use Illuminate\Http\Request;

class HelpController extends Controller
{
    private PollinationAIService $aiService;

    public function __construct(PollinationAIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Get explanation for a topic
     */
    public function explain(Request $request)
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:200',
            'context' => 'nullable|string|max:500',
            'language' => 'nullable|string|in:en,fa',
        ]);

        $result = $this->aiService->explain(
            $validated['topic'],
            $validated['context'] ?? '',
            $validated['language'] ?? 'en'
        );

        return response()->json($result);
    }

    /**
     * Get explanation for error
     */
    public function explainError(Request $request)
    {
        $validated = $request->validate([
            'error' => 'required|string|max:500',
            'context' => 'nullable|string|max:500',
        ]);

        $result = $this->aiService->explainError(
            $validated['error'],
            $validated['context'] ?? ''
        );

        return response()->json($result);
    }

    /**
     * Get codec explanation
     */
    public function explainCodec(Request $request)
    {
        $validated = $request->validate([
            'codec' => 'required|string|max:50',
        ]);

        $result = $this->aiService->explainCodec($validated['codec']);

        return response()->json($result);
    }

    /**
     * Get field help
     */
    public function getFieldHelp(Request $request)
    {
        $validated = $request->validate([
            'field' => 'required|string|max:100',
            'value' => 'nullable|string|max:200',
        ]);

        $result = $this->aiService->getFieldHelp(
            $validated['field'],
            $validated['value'] ?? ''
        );

        return response()->json($result);
    }

    /**
     * Get batch explanations
     */
    public function explainBatch(Request $request)
    {
        $validated = $request->validate([
            'topics' => 'required|array|max:10',
            'topics.*' => 'required|string|max:100',
            'language' => 'nullable|string|in:en,fa',
        ]);

        $result = $this->aiService->explainBatch(
            $validated['topics'],
            $validated['language'] ?? 'en'
        );

        return response()->json([
            'success' => true,
            'explanations' => $result,
        ]);
    }
}
