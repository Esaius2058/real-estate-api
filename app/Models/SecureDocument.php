<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecureDocument extends Model
{
    use HasFactory;

    // Using $guarded is perfectly fine. It means 'extracted_text' and 'ml_data' 
    // are automatically fillable without needing to type them out.
    protected $guarded = ['id'];

    // Tells Laravel to parse the JSON database column into a usable PHP array
    protected $casts = [
        'ml_data' => 'array',
    ];

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}