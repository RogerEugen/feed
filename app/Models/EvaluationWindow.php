<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluationWindow extends Model
{
    protected $fillable = [
        'title',
        'academic_year',
        'semester',
        'opens_at',
        'closes_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opens_at'  => 'datetime',
            'closes_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    // ✅ Open means: is_active=true AND time is within range
    public function isOpen(): bool
    {
        if (!$this->is_active) return false;
        $now = now();
        return $now->gte($this->opens_at) && $now->lte($this->closes_at);
    }

    public function evaluations()
    {
        return $this->hasMany(CourseEvaluation::class, 'window_id');
    }

    public function analytics()
    {
        return $this->hasMany(EvaluationAnalytic::class, 'window_id');
    }
}
