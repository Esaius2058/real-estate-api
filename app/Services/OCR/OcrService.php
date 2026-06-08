<?php

namespace App\Services\OCR;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OcrService
{
    /**
     * Sends the image to OCR.Space and extracts the raw text.
     */
    public function extractText(string $signedImageUrl): ?string
    {
        $apiKey = config('services.ocrspace.key');

        $response = Http::timeout(30)->post('https://api.ocr.space/parse/image', [
            'apikey' => $apiKey,
            'url' => $signedImageUrl,
            'language' => 'eng',
            'isOverlayRequired' => false,
            'OCREngine' => 2, // Engine 2 is optimized for numbers/special characters on IDs
        ]);

        if ($response->successful()) {
            $data = $response->json();
            
            // Check if the API threw an internal error (e.g., file too large)
            if (isset($data['IsErroredOnProcessing']) && $data['IsErroredOnProcessing'] === true) {
                Log::error('OCR.Space Processing Error', ['error' => $data['ErrorMessage'] ?? 'Unknown']);
                return null;
            }

            if (!empty($data['ParsedResults'])) {
                return $data['ParsedResults'][0]['ParsedText'];
            }
        }

        Log::error('OCR API Connection Failed', ['response' => $response->body()]);
        return null;
    }

    /**
     * Analyzes the raw text to verify if it contains a valid Kenyan ID or KRA PIN.
     */
    public function analyzeKycData(string $rawText): array
    {
        $analysis = [
            'has_clear_text' => !empty(trim($rawText)),
            'extracted_id' => null,
            'extracted_kra_pin' => null,
            'confidence' => 'low',
            'requires_manual_review' => true
        ];

        // 1. Hunt for Kenyan National ID (Typically 7 or 8 straight digits)
        if (preg_match('/\b\d{7,8}\b/', $rawText, $idMatches)) {
            $analysis['extracted_id'] = $idMatches[0];
            $analysis['confidence'] = 'medium';
        }

        // 2. Hunt for KRA PIN (Format: A followed by 9 digits, followed by a letter)
        if (preg_match('/\bA\d{9}[A-Z]\b/i', $rawText, $kraMatches)) {
            $analysis['extracted_kra_pin'] = strtoupper($kraMatches[0]);
            $analysis['confidence'] = 'high';
            $analysis['requires_manual_review'] = false; // Auto-verify if KRA PIN is perfectly read
        }

        return $analysis;
    }
}