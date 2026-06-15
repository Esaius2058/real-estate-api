<?php

namespace App\Http\Controllers\Api\v1;

use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Controller;
use App\Models\SecureDocument;
use App\Http\Requests\Document\GenerateUploadUrlRequest;
use App\Services\Vault\SecureDocumentService;
use App\Services\OCR\OcrService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class VaultDocumentController extends Controller
{
    public function __construct(
        private SecureDocumentService $vaultService,
        private OcrService $ocrService
    ) {}

    public function index(): JsonResponse
    {
        $documents = SecureDocument::where('agency_id', auth()->user()->agency_id)
            ->latest()
            ->get()
            ->map(fn($doc) => $this->formatDocument($doc));

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_name'   => ['required', 'string', 'max:255'],
            'client_email'  => ['required', 'email'],
            'client_phone'  => ['nullable', 'string'],
            's3_path'       => ['required', 'string'],
            'type'          => ['required', 'string'],
            'temporary_url' => ['required', 'url'],
            'notes'         => ['nullable', 'string'],
        ]);

        $client = \App\Models\User::firstOrCreate(
            ['email' => $validated['client_email']],
            [
                'name'      => $validated['client_name'],
                'phone'     => $validated['client_phone'],
                'agency_id' => auth()->user()->agency_id,
                'password'  => bcrypt(str()->random(16)),
            ]
        );

        $document = SecureDocument::create([
            'agency_id'         => auth()->user()->agency_id,
            'uploaded_by'       => auth()->id(),
            'documentable_type' => 'App\Models\User',
            'documentable_id'   => $client->id,
            'type'              => $validated['type'],
            's3_path'           => $validated['s3_path'],
            'notes'             => $validated['notes'] ?? null,
            'status'            => 'pending_review',
        ]);

        try {
            $rawText = $this->ocrService->extractText($validated['temporary_url']);

            if ($rawText) {
                $analysis = $this->ocrService->analyzeKycData($rawText);
                
                // Save basic OCR data first
                $document->update([
                    'extracted_text' => $rawText,
                    'ml_data'        => $analysis,
                ]);

                // --- CALL THE PYTHON AGENT SERVICE ---
                $payload = [
                    'expected_name' => $client->name,
                    'expected_type' => $validated['type'],
                    'ocr_text'      => $rawText
                ];

                $response = Http::withToken($request->bearerToken())
                    ->timeout(15)
                    ->post(config('services.agent.url', 'http://127.0.0.1:8001') . '/agents/verify', $payload);

                if ($response->successful()) {
                    $result = $response->json();
                    
                    $isVerified = $result['name_match'] && $result['document_type_confirmed'] && $result['confidence_score'] >= 80;
                    
                    $document->update([
                        'ai_verification_status' => $isVerified ? 'ai_verified' : 'ai_flagged',
                        'ai_confidence_score'    => $result['confidence_score'],
                        'ai_reasoning'           => $result['reasoning']
                    ]);
                } else {
                    Log::error('Agent Service Verification Failed', [
                        'status' => $response->status(),
                        'body'   => $response->body()
                    ]);
                    
                    $document->update([
                        'ai_verification_status' => 'ai_flagged',
                        'ai_reasoning'           => 'System failed to reach AI verification service.'
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('OCR or Agent processing failed for document ' . $document->id, [
                'error'         => $e->getMessage(),
                'temporary_url' => $validated['temporary_url'],
            ]);
            
            $document->update([
                'ai_verification_status' => 'ai_flagged',
                'ai_reasoning'           => 'Processing exception: ' . $e->getMessage()
            ]);
        }

        return response()->json([
            'message' => 'Document uploaded and queued for processing.',
            'data'    => $this->formatDocument($document->fresh()),
        ], 201);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        // FIX 3: Update enum validation to accept 'verified' instead of 'approved' to match React and database
        $validated = $request->validate([
            'status' => ['required', 'in:pending_review,verified,rejected'],
        ]);

        $document = SecureDocument::where('agency_id', auth()->user()->agency_id)
            ->findOrFail($id);

        // FIX 4: Update 'status' column
        $document->update(['status' => $validated['status']]);

        return response()->json(['data' => $this->formatDocument($document->fresh())]);
    }

    public function generateUploadUrl(GenerateUploadUrlRequest $request): JsonResponse
    {
        $uploadData = $this->vaultService->generatePresignedUrl(
            $request->file_name,
            $request->mime_type,
            $request->document_type
        );

        return response()->json([
            'upload_url' => $uploadData['url'],
            'file_path'  => $uploadData['path'],
            'expires_in' => 300,
        ]);
    }

    /**
     * Normalizes DB column names to the shape the frontend expects.
     * Maps real DB properties to both column formats seamlessly.
     */
    private function formatDocument(SecureDocument $doc): array
    {
        $mlData = is_string($doc->ml_data)
            ? json_decode($doc->ml_data, true)
            : $doc->ml_data;

        return [
            'id'                  => $doc->id,
            'type'                => $doc->type ?? 'unknown',
            'document_type'       => $doc->type ?? 'unknown',
            'status'              => $doc->status ?? 'pending_review',
            'verification_status' => $doc->status ?? 'pending_review',
            'userId'              => $doc->documentable_id,
            'documentable_id'     => $doc->documentable_id,
            's3_path'             => $doc->s3_path,
            's3_private_path'     => $doc->s3_path, 
            'notes'               => $doc->notes,

            'extracted_text'      => $doc->extracted_text,
            'ml_data'             => $mlData,

            'extracted' => [
                'text'       => $doc->extracted_text,
                'id'         => data_get($mlData, 'extracted_id'),
                'kra_pin'    => data_get($mlData, 'extracted_kra_pin'),
                'confidence' => data_get($mlData, 'confidence'),
            ],

            'ai_verification_status' => $doc->ai_verification_status ?? 'pending',
            'ai_confidence_score'    => $doc->ai_confidence_score,
            'ai_reasoning'           => $doc->ai_reasoning,

            'updatedAt'  => $doc->updated_at,
            'createdAt'  => $doc->created_at,
            'created_at' => $doc->created_at,
            'updated_at' => $doc->updated_at,
        ];
    }
}