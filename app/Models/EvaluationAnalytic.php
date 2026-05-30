<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationAnalytic extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'window_id',
        'course_code',
        'department_id',
        'faculty_id',
        'total_responses',
        'avg_teaching_quality',
        'avg_course_content',
        'avg_assessment_fairness',
        'avg_resources',
        'avg_accessibility',
        'avg_overall',
        'dept_avg_overall',
        'faculty_avg_overall',
        'results_visible',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'results_visible' => 'boolean',
            'computed_at'     => 'datetime',
        ];
    }

    public function window()
    {
        return $this->belongsTo(EvaluationWindow::class, 'window_id');
    }
}