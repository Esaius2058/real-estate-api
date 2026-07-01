<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = [
        'session_id',
        'agency_id',
        'role',
        'content',
    ];

    protected $casts = [
        'content' => 'array', // Tells Laravel to json_encode on save, and json_decode on retrieve
    ];
}