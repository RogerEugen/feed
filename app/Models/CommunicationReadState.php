<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationReadState extends Model
{
    protected $fillable = [
        'room',
        'actor_role',
        'actor_id',
        'last_read_message_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_message_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }
}
