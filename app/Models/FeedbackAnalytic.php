<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackAnalytic extends Model
{
    protected $fillable = [
        'department_id',
        'faculty_id',
        'category_id',
        'period_month',
        'total_submitted',
        'total_resolved',
        'total_escalated',
        'avg_resolution_days',
        'high_priority_count',
    ];

    protected function casts(): array
    {
        return ['period_month' => 'date'];
    }

    public function category()
    {
        return $this->belongsTo(FeedbackCategory::class, 'category_id');
    }
}