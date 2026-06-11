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
        // ✨ FIXED: Saved directly to base folder mappings inside your MinIO bucket (removes 'private/')
        $path = $docType . '/' . Str::uuid() . '-' . $fileName;

        // 🌟 ADDED PHPDOC TYPE-HINT TO SILENCE INTELEPHENSE (P1013) ERRORS
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
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