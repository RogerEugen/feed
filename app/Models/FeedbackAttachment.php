<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'feedback_id', 'stored_filename',
        'mime_type', 'file_size_kb',
        'is_encrypted', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'uploaded_at'  => 'datetime',
        ];
    }

    public function feedback()
    {
        return $this->belongsTo(Feedback::class);
    }
}