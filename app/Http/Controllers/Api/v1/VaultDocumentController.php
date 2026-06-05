<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Document; // Ensure you import your actual Document model
use App\Http\Requests\Document\GenerateUploadUrlRequest;
use App\Services\Vault\SecureDocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VaultDocumentController extends Controller
{
    public function __construct(private SecureDocumentService $vaultService) {}

    /**
     * 1. GET /api/v1/vault/documents
     * Fetches all secure documents for the authenticated agency's vault.
     */
    public function index(Request $request): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;

        $documents = Document::where('agency_id', $agencyId)
            ->latest()
            ->get();

        return response()->json(['data' => $documents]);
    }

    /**
     * 2. POST /api/v1/vault/documents
     * Persists the document metadata to the database AFTER the frontend uploads to AWS/Supabase.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            's3_path' => 'required|string',
            'type'    => 'required|string',
            'user_id' => 'nullable|string', // The Client ID mapped from the React modal
            'notes'   => 'nullable|string',
            'status'  => 'required|string|in:pending_review,approved,rejected',
        ]);

        $document = Document::create([
            'agency_id'   => auth()->user()->agency_id,
            'uploaded_by' => auth()->id(),
            's3_path'     => $validated['s3_path'],
            'type'        => $validated['type'],
            'user_id'     => $validated['user_id'],
            'notes'       => $validated['notes'],
            'status'      => $validated['status'],
        ]);

        return response()->json(['data' => $document], 201);
    }

    /**
     * 3. PATCH /api/v1/vault/documents/{id}/status
     * Admin/ML integration endpoint to approve or reject KYC documents.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending_review,approved,rejected'
        ]);

        $document = Document::where('agency_id', auth()->user()->agency_id)->findOrFail($id);
        
        $document->update(['status' => $validated['status']]);

        return response()->json(['data' => $document]);
    }

    /**
     * 4. POST /api/v1/vault/presigned-url
     * Generates the temporary AWS/Supabase upload URL for the React frontend.
     */
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
            'expires_in' => 300 // 5 minutes
        ]);
    }
}