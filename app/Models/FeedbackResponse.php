<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackResponse extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'feedback_id',
        'responder_role',
        'responder_department_id',
        'encrypted_response',
        'encryption_iv',
        'is_escalation_note',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_escalation_note' => 'boolean',
            'responded_at'       => 'datetime',
        ];
    }

    public function feedback()
    {
        return $this->belongsTo(Feedback::class);
    }
}