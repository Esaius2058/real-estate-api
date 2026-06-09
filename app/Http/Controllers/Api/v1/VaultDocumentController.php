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

        $document = SecureDocument::create([
            'agency_id'           => auth()->user()->agency_id,
            'uploaded_by'         => auth()->id(),
            'documentable_type'   => 'App\Models\User',
            'documentable_id'     => $client->id,
            'document_type'       => $validated['type'],
            's3_private_path'     => $validated['s3_path'],
            'notes'               => $validated['notes'] ?? null,
            'verification_status' => 'pending_review',
        ]);

        // OCR runs synchronously — wrapped so a failure never blocks document creation
        try {
            $rawText = $this->ocrService->extractText($validated['temporary_url']);

            if ($rawText) {
                $analysis = $this->ocrService->analyzeKycData($rawText);
                $document->update([
                    'extracted_text'      => $rawText,
                    'ml_data'             => $analysis,
                    'verification_status' => $analysis['requires_manual_review']
                        ? 'pending_review'
                        : 'verified',
                ]);
            }
        } catch (\Throwable $e) {
            // Log but never let OCR failure kill the upload response
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
        $validated = $request->validate([
            'status' => ['required', 'in:pending_review,approved,rejected'],
        ]);

        $document = SecureDocument::where('agency_id', auth()->user()->agency_id)
            ->findOrFail($id);

        // Fixed: column is verification_status, not status
        $document->update(['verification_status' => $validated['status']]);

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
     * Prevents doc.type and doc.status from ever being undefined.
     */
    private function formatDocument(SecureDocument $doc): array
    {
        $mlData = is_string($doc->ml_data)
            ? json_decode($doc->ml_data, true)
            : $doc->ml_data;

        return [
            'id'                  => $doc->id,
            'type'                => $doc->document_type ?? 'unknown',
            'document_type'       => $doc->document_type ?? 'unknown',
            'status'              => $doc->verification_status ?? 'pending_review',
            'verification_status' => $doc->verification_status ?? 'pending_review',
            'userId'              => $doc->documentable_id,
            'documentable_id'     => $doc->documentable_id,
            's3_path'             => $doc->s3_private_path,
            's3_private_path'     => $doc->s3_private_path, // DocumentViewer reads this for the signed URL
            'notes'               => $doc->notes,

            // Flat fields DocumentViewer reads directly
            'extracted_text'      => $doc->extracted_text,
            'ml_data'             => $mlData,

            // Nested block for anything else consuming the API
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