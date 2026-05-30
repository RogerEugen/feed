<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseEvaluation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'anonymous_token_hash',
        'window_id',
        'course_code',
        'subject_name',
        'lecturer_id',
        'lecturer_name',
        'department_id',
        'faculty_id',
        'academic_year',
        'semester',
        'teaching_quality',
        'course_content',
        'assessment_fairness',
        'resources_available',
        'lecturer_accessibility',
        'overall_rating',
        'encrypted_comments',
        'encryption_iv',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function window()
    {
        return $this->belongsTo(EvaluationWindow::class, 'window_id');
    }

    public function averageRating(): float
    {
        return round(array_sum([
            $this->teaching_quality,
            $this->course_content,
            $this->assessment_fairness,
            $this->resources_available,
            $this->lecturer_accessibility,
            $this->overall_rating,
        ]) / 6, 2);
    }
}