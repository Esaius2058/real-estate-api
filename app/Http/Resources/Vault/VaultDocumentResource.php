<?php

namespace App\Http\Resources\Vault;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class VaultDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('s3');

        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'type' => $this->type,
            'status' => $this->status,
           
            'filePath' => $this->file_path 
                ? $disk->temporaryUrl($this->file_path, now()->addMinutes(20)) 
                : null,
                
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at, 
        ];
    }
}