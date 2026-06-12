<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VaultDocument extends Model
{
    use HasFactory;

    protected $table = 'vault_documents';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'file_path',
    ];
}