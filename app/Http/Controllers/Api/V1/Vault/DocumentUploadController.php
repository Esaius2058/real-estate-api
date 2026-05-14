<?php

namespace App\Http\Controllers\Api\V1\Vault;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\GenerateUploadUrlRequest;
use App\Services\Vault\SecureDocumentService;
use Illuminate\Http\JsonResponse;

class DocumentUploadController extends Controller
{
    public function __construct(private SecureDocumentService $vaultService) {}

    public function store(GenerateUploadUrlRequest $request): JsonResponse
    {
        // 1. Generate the presigned URL for the React frontend
        $uploadData = $this->vaultService->generatePresignedUrl(
            $request->file_name,
            $request->mime_type,
            $request->document_type // e.g., 'kyc', 'title_deed'
        );

        // 2. Return the URL so the frontend can execute the PUT request to AWS
        return response()->json([
            'upload_url' => $uploadData['url'],
            'file_path'  => $uploadData['path'],
            'expires_in' => 300 // 5 minutes
        ]);
    }
}