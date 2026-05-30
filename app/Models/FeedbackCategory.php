<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedbackCategory extends Model
{
    protected $fillable = [
        'name', 'slug', 'routes_to',
        'sender_role', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function feedbacks()
    {
        return $this->hasMany(Feedback::class, 'category_id');
    }

    public function analytics()
    {
        return $this->hasMany(FeedbackAnalytic::class, 'category_id');
    }
}