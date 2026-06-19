<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Feedback extends Model
{
    public $timestamps = false;
    protected $table = 'feedbacks';

    protected $fillable = [
        'tracking_code',
        'anonymous_token_hash',
        'sender_role',
        'sender_department_id',
        'category_id',
        'routed_to',
        'recipient_department_id',
        'recipient_faculty_id',
        'encrypted_content',
        'encryption_iv',
        'has_attachment',
        'priority',
        'status',
        'is_escalated',
        'escalated_to',
        'escalated_at',
        'resolved_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'has_attachment' => 'boolean',
            'is_escalated'   => 'boolean',
            'submitted_at'   => 'datetime',
            'escalated_at'   => 'datetime',
            'resolved_at'    => 'datetime',
        ];
    }

    // Longer random suffix makes tracking-code-only private threads harder to guess.
    public static function generateTrackingCode(): string
    {
        do {
            $code = 'FB-' . date('Y') . '-' . strtoupper(Str::random(8));
        } while (self::where('tracking_code', $code)->exists());

        return $code;
    }

    public function category()
    {
        return $this->belongsTo(FeedbackCategory::class, 'category_id');
    }

    public function attachments()
    {
        return $this->hasMany(FeedbackAttachment::class, 'feedback_id');
    }

    public function responses()
    {
        return $this->hasMany(FeedbackResponse::class, 'feedback_id');
    }

    public function followups()
    {
        return $this->hasMany(FeedbackFollowup::class, 'feedback_id');
    }

    public function recipientDepartment()
    {
        // department_id stored cross-service — use raw query fallback
        // This is a cross-service reference so we query directly
        return $this->belongsTo(\App\Models\Feedback::class, 'recipient_department_id', 'id');
    }
}
