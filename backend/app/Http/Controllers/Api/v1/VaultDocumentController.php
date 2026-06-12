<?php

namespace App\Http\Controllers\Api\v1;

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

        // Resolve or create the client profile
        $client = \App\Models\User::firstOrCreate(
            ['email' => $validated['client_email']],
            [
                'name'      => $validated['client_name'],
                'phone'     => $validated['client_phone'],
                'agency_id' => auth()->user()->agency_id,
                'password'  => bcrypt(str()->random(16)),
            ]
        );

        // FIX 1: Map array keys to the actual MySQL table columns
        $document = SecureDocument::create([
            'agency_id'         => auth()->user()->agency_id,
            'uploaded_by'       => auth()->id(),
            'documentable_type' => 'App\Models\User',
            'documentable_id'   => $client->id,
            'type'              => $validated['type'],    // Real Column: type
            's3_path'           => $validated['s3_path'], // Real Column: s3_path
            'notes'             => $validated['notes'] ?? null,
            'status'            => 'pending_review',      // Real Column: status
        ]);

        // OCR runs synchronously
        try {
            $rawText = $this->ocrService->extractText($validated['temporary_url']);

            if ($rawText) {
                $analysis = $this->ocrService->analyzeKycData($rawText);
                
                // FIX 2: Update actual column name 'status' and use 'verified' matching React state
                $document->update([
                    'extracted_text' => $rawText,
                    'ml_data'        => $analysis,
                    'status'         => $analysis['requires_manual_review']
                        ? 'pending_review'
                        : 'verified',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('OCR extraction failed for document ' . $document->id, [
                'error'         => $e->getMessage(),
                'temporary_url' => $validated['temporary_url'],
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
            // FIX 5: Extract properties from real DB columns ($doc->type, $doc->status, $doc->s3_path)
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

            'updatedAt'  => $doc->updated_at,
            'createdAt'  => $doc->created_at,
            'created_at' => $doc->created_at,
            'updated_at' => $doc->updated_at,
        ];
    }
}