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

        if (!$apiKey) {
            Log::error('OCR.Space API key is not configured.');
            return null;
        }
        
        Log::info('OCR.Space request firing', ['url' => $signedImageUrl]);

        $response = Http::timeout(30)
            ->asMultipart()  // ← OCR.Space requires multipart, not form params
            ->post('https://api.ocr.space/parse/image', [
                [
                    'name'     => 'apikey',
                    'contents' => $apiKey,
                ],
                [
                    'name'     => 'url',
                    'contents' => $signedImageUrl,
                ],
                [
                    'name'     => 'language',
                    'contents' => 'eng',
                ],
                [
                    'name'     => 'isOverlayRequired',
                    'contents' => 'false',
                ],
                [
                    'name'     => 'OCREngine',
                    'contents' => '2',
                ],
            ]);

        Log::info('OCR.Space raw response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->successful()) {
            $data = $response->json();

            if (!empty($data['IsErroredOnProcessing'])) {
                Log::error('OCR.Space processing error', [
                    'error' => $data['ErrorMessage'] ?? 'Unknown',
                ]);
                return null;
            }

            if (!empty($data['ParsedResults'][0]['ParsedText'])) {
                return $data['ParsedResults'][0]['ParsedText'];
            }

            Log::warning('OCR.Space returned no parsed text', ['data' => $data]);
            return null;
        }

        Log::error('OCR.Space HTTP error', [
            'status'   => $response->status(),
            'response' => $response->body(),
        ]);
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