<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackFollowup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'feedback_id',
        'tracking_code',
        'encrypted_message',
        'encryption_iv',
        'direction',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function feedback()
    {
        return $this->belongsTo(Feedback::class);
    }
}