<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\SecureDocument; 
use App\Http\Requests\Document\GenerateUploadUrlRequest;
use App\Services\Vault\SecureDocumentService;
use App\Services\OCR\OcrService; 
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VaultDocumentController extends Controller
{
    // Injected the OCR Service into the constructor
    public function __construct(
        private SecureDocumentService $vaultService,
        private OcrService $ocrService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;

        $documents = SecureDocument::where('agency_id', $agencyId)
            ->latest()
            ->get();

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request): JsonResponse
    {
        // Validate the incoming React payload
        $validated = $request->validate([
            'client_name'   => 'required|string|max:255',
            'client_email'  => 'required|email', // Used to check for duplicates
            'client_phone'  => 'nullable|string',
            's3_path'       => 'required|string',
            'type'          => 'required|string',
            'temporary_url' => 'required|url',
            'notes'         => 'nullable|string',
        ]);

        // Create the Client Profile on the fly (or fetch if email exists)
        $client = \App\Models\User::firstOrCreate(
            ['email' => $validated['client_email']],
            [
                'name'      => $validated['client_name'],
                'phone'     => $validated['client_phone'],
                'agency_id' => auth()->user()->agency_id,
                'password'  => bcrypt(str()->random(16)), 
            ]
        );

        // Insert using the EXACT column names from your database schema
        $document = SecureDocument::create([
            'agency_id'           => auth()->user()->agency_id,
            'uploaded_by'         => auth()->id(),
            'documentable_type'   => 'App\Models\User', 
            'documentable_id'     => $client->id, // <--- This is the critical fix
            'document_type'       => $validated['type'], 
            's3_private_path'     => $validated['s3_path'],
            'notes'               => $validated['notes'] ?? null,
            'verification_status' => 'pending_review',
        ]);

        // Process the OCR Image
        $rawText = $this->ocrService->extractText($validated['temporary_url']);

        if ($rawText) {
            $analysis = $this->ocrService->analyzeKycData($rawText);

            // Update the document with OCR results
            $document->update([
                'extracted_text'      => $rawText,
                'ml_data'             => $analysis,
                'verification_status' => $analysis['requires_manual_review'] ? 'pending_review' : 'verified' 
            ]);
        }

        return response()->json([
            'message' => 'Document uploaded and queued for processing.',
            'data'    => $document
        ], 201);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending_review,approved,rejected'
        ]);

        $document = SecureDocument::where('agency_id', auth()->user()->agency_id)->findOrFail($id);
        
        $document->update(['status' => $validated['status']]);

        return response()->json(['data' => $document]);
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
            'expires_in' => 300
        ]);
    }
}