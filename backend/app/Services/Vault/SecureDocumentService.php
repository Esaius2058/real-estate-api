<?php

declare(strict_types=1);

namespace App\Services\Vault;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SecureDocumentService
{
    /**
     * Generate an S3 presigned URL for direct frontend uploads.
     */
    public function generatePresignedUrl(string $fileName, string $mimeType, string $docType): array
    {
        // Organize into private folders: e.g., private/kyc/uuid-filename.pdf
        $path = 'private/' . $docType . '/' . Str::uuid() . '-' . $fileName;

        $disk = Storage::disk('s3');
        $client = $disk->getClient();
        
        $command = $client->getCommand('PutObject', [
            'Bucket'      => config('filesystems.disks.s3.bucket'),
            'Key'         => $path,
            'ContentType' => $mimeType,
            'ACL'         => 'private', 
        ]);

        // URL expires in 5 minutes
        $request = $client->createPresignedRequest($command, '+5 minutes');

        return [
            'url'  => (string) $request->getUri(),
            'path' => $path,
        ];
    }
}