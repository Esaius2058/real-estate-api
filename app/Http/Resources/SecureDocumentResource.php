<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SecureDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'agency_id'           => $this->agency_id,
            'uploaded_by'         => $this->uploaded_by,
            
            'documentable_type'   => $this->documentable_type,
            'documentable_id'     => $this->documentable_id,
            
            'document_type'       => $this->document_type,
            's3_private_path'     => $this->s3_private_path,
            'notes'               => $this->notes,
            'verification_status' => $this->verification_status,
            
            // Laravel automatically casts JSON columns to arrays if configured in the model
            'extracted_text'      => $this->extracted_text,
            'ml_data'             => $this->ml_data,
            
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,

            // Inject the frontend-mapped properties right here so React doesn't have to
            'type'                => $this->document_type,
            'status'              => $this->verification_status,
            'userId'              => (string) $this->documentable_id,
        ];
    }
}