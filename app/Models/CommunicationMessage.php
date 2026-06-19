<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'room',
        'sender_role',
        'encrypted_message',
        'encryption_iv',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
