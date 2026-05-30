<?php
namespace Database\Seeders;

use App\Models\FeedbackCategory;
use Illuminate\Database\Seeder;

class FeedbackCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            // Student categories — go to HOD
            [
                'name'        => 'Academic Issues',
                'slug'        => 'academic-issues',
                'routes_to'   => 'hod',
                'sender_role' => 'student',
                'description' => 'Issues related to teaching, curriculum, or academic performance',
            ],
            [
                'name'        => 'Examination Concerns',
                'slug'        => 'examination-concerns',
                'routes_to'   => 'hod',
                'sender_role' => 'student',
                'description' => 'Concerns about exams, grading, or assessment',
            ],
            // Student categories — go to Dean of Students
            [
                'name'        => 'Student Affairs',
                'slug'        => 'student-affairs',
                'routes_to'   => 'dean',
                'sender_role' => 'student',
                'description' => 'Issues related to student welfare, accommodation, or services',
            ],
            [
                'name'        => 'Harassment or Misconduct',
                'slug'        => 'harassment-misconduct',
                'routes_to'   => 'dean',
                'sender_role' => 'student',
                'description' => 'Reports of harassment, bullying or misconduct',
            ],
            // Lecturer categories — go to Rector
            [
                'name'        => 'Department Management',
                'slug'        => 'department-management',
                'routes_to'   => 'rector',
                'sender_role' => 'lecturer',
                'description' => 'Feedback about department leadership or management issues',
            ],
            [
                'name'        => 'Resources and Facilities',
                'slug'        => 'resources-facilities',
                'routes_to'   => 'rector',
                'sender_role' => 'lecturer',
                'description' => 'Issues regarding teaching resources, labs, or facilities',
            ],
            // Any role — goes to admin
            [
                'name'        => 'Infrastructure',
                'slug'        => 'infrastructure',
                'routes_to'   => 'admin',
                'sender_role' => 'any',
                'description' => 'Campus infrastructure, buildings, or utilities issues',
            ],
            [
                'name'        => 'General Suggestion',
                'slug'        => 'general-suggestion',
                'routes_to'   => 'admin',
                'sender_role' => 'any',
                'description' => 'General improvement suggestions for the institution',
            ],
        ];

        foreach ($categories as $category) {
            FeedbackCategory::firstOrCreate(
                ['slug' => $category['slug']],
                $category
            );
        }

        echo "✅ Feedback categories seeded!\n";
    }
}