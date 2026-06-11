<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\VaultDocument;
use App\Http\Resources\Vault\VaultDocumentResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class VaultController extends Controller
{
    // GET /api/v1/vault/documents
    public function index(Request $request)
    {
        $search    = $request->query('search');
        $category  = $request->query('category');
        $status    = $request->query('status');
        $dateRange = $request->query('date_range');

        $documents = VaultDocument::latest()
            ->when($search, function ($query, $search) {
                return $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('user_id', 'like', "%{$search}%")
                             ->orWhere('type', 'like', "%{$search}%")
                             ->orWhere('status', 'like', "%{$search}%");
                });
            })
            ->when($category && $category !== 'all', function ($query) use ($category) {
                return $query->where('type', $category);
            })
            ->when($status && $status !== 'all', function ($query) use ($status) {
                return $query->where('status', $status);
            })
            ->when($dateRange && $dateRange !== 'all', function ($query) use ($dateRange) {
                switch ($dateRange) {
                    case 'today':
                        return $query->whereDate('created_at', Carbon::today());
                    case 'week':
                        return $query->where('created_at', '>=', Carbon::now()->subDays(7));
                    case 'month':
                        return $query->where('created_at', '>=', Carbon::now()->subDays(30));
                    default:
                        return $query;
                }
            })
            ->get();

        return VaultDocumentResource::collection($documents);
    }

    // POST /api/v1/vault/documents
    public function store(Request $request)
    {
        $validated = $request->validate([
            'userId' => 'nullable|string',
            'type' => 'required|string',
            'filePath' => 'nullable|string',
        ]);

        $document = VaultDocument::create([
            'user_id'   => $validated['userId'] ?? null,
            'type'      => $validated['type'],
            'file_path' => $validated['filePath'] ?? null,
            'status'    => 'pending', 
        ]);

        return new VaultDocumentResource($document);
    }

    // PATCH /api/v1/vault/documents/{id}/status
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:pending,approved,rejected,verified', 
        ]);

        $document = VaultDocument::findOrFail($id);
        $document->update([
            'status' => $validated['status']
        ]);

        return new VaultDocumentResource($document);
    }

    /**
     * DELETE /api/v1/vault/documents/{id}
     * Purges file asset directly from MinIO cluster storage and deletes structural DB reference context.
     */
    public function destroy($id)
    {
        $document = VaultDocument::findOrFail($id);

        // Extract clean object reference key by stripping pre-signed query parameters if present
        if ($document->file_path) {
            $parsedUrl = parse_url($document->file_path, PHP_URL_PATH);
            
            // If absolute link format, strip bucket name prefix segment to get storage disk key path
            $bucketName = config('filesystems.disks.s3.bucket');
            $storagePath = ltrim($parsedUrl, '/');
            
            if (str_starts_with($storagePath, $bucketName . '/')) {
                $storagePath = substr($storagePath, strlen($bucketName . '/'));
            }

            // Purge matching asset blocks out of MinIO storage bucket tree
            if (Storage::disk('s3')->exists($storagePath)) {
                Storage::disk('s3')->delete($storagePath);
            }
        }

        // Drop relational DB model target row instance
        $document->delete();

        return response()->json([
            'message' => 'Document record tracking sequence successfully purged from storage nodes.'
        ], 200);
    }

    /**
     * POST /api/v1/vault/presigned-upload-url
     */
    public function presignedUploadUrl(Request $request)
    {
        $request->validate([
            'client_filename' => 'required|string',
            'file_category' => 'required|string|in:kyc,title_deed'
        ]);

        $filename = $request->input('client_filename');
        $category = $request->input('file_category');
        
        $safeName = Str::random(16) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename);
        $storagePath = "{$category}/{$safeName}";

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('s3');
        $client = $disk->getClient();
        $expiry = '+20 minutes'; 

        $command = $client->getCommand('PutObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key'    => $storagePath,
            'ACL'    => 'private', 
        ]);

        $presignedRequest = $client->createPresignedRequest($command, $expiry);
        $presignedUrl = (string) $presignedRequest->getUri();

        return response()->json([
            'upload_url' => $presignedUrl,
            'file_path'  => $storagePath,
        ]);
    }
}