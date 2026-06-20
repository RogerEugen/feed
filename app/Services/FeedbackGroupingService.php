<?php

namespace App\Services;

use Illuminate\Support\Str;

class FeedbackGroupingService
{
    public function group(array $feedbacks, float $threshold = 0.28): array
    {
        $clusters = [];

        foreach ($feedbacks as $feedback) {
            $feedback['keywords'] = $this->extractKeywords((string) ($feedback['content'] ?? ''));
            $bestIndex = null;
            $bestScore = 0.0;

            foreach ($clusters as $index => $cluster) {
                if ((int) $cluster['category_id'] !== (int) $feedback['category_id']) {
                    continue;
                }
                if ((int) ($cluster['department_id'] ?? 0) !== (int) ($feedback['recipient_department_id'] ?? 0)) {
                    continue;
                }

                $score = $this->similarity($feedback['keywords'], $cluster['keywords']);
                if ($score >= $threshold && $score > $bestScore) {
                    $bestIndex = $index;
                    $bestScore = $score;
                }
            }

            if ($bestIndex === null) {
                $clusters[] = [
                    'category_id' => $feedback['category_id'],
                    'category' => $feedback['category'],
                    'department_id' => $feedback['recipient_department_id'] ?? null,
                    'faculty_id' => $feedback['recipient_faculty_id'] ?? null,
                    'keywords' => $feedback['keywords'],
                    'members' => [$feedback],
                ];
                continue;
            }

            $clusters[$bestIndex]['members'][] = $feedback;
            $clusters[$bestIndex]['keywords'] = $this->mergeKeywords(
                $clusters[$bestIndex]['members']
            );
        }

        return collect($clusters)
            ->map(fn (array $cluster) => $this->formatCluster($cluster))
            ->sortBy([
                ['feedback_count', 'desc'],
                ['latest_at', 'desc'],
            ])
            ->values()
            ->all();
    }

    public function extractKeywords(string $text): array
    {
        $normalized = Str::lower(preg_replace('/[^\pL\pN\s]/u', ' ', $text) ?? '');
        $stopWords = [
            'that', 'this', 'with', 'have', 'from', 'your', 'please', 'about', 'there',
            'where', 'when', 'which', 'would', 'could', 'should', 'their', 'they', 'them',
            'kwenye', 'kuna', 'hii', 'hiyo', 'sana', 'kama', 'kwa', 'lakini', 'ambayo',
            'kuwa', 'yetu', 'wetu', 'mimi', 'sisi', 'naomba', 'tafadhali',
        ];

        $tokens = array_filter(
            preg_split('/\s+/u', $normalized) ?: [],
            fn (string $token) => mb_strlen($token) > 3 && !in_array($token, $stopWords, true)
        );
        $tokens = array_map(fn (string $token) => $this->canonicalKeyword($token), $tokens);

        $counts = array_count_values($tokens);
        arsort($counts);

        return array_slice(array_keys($counts), 0, 16);
    }

    public function similarity(array $first, array $second): float
    {
        if ($first === [] || $second === []) {
            return 0.0;
        }

        $intersection = array_intersect($first, $second);
        $union = array_unique(array_merge($first, $second));

        return count($union) === 0 ? 0.0 : count($intersection) / count($union);
    }

    private function mergeKeywords(array $members): array
    {
        $counts = [];
        foreach ($members as $member) {
            foreach ($member['keywords'] ?? [] as $keyword) {
                $counts[$keyword] = ($counts[$keyword] ?? 0) + 1;
            }
        }
        arsort($counts);

        $repeated = array_keys(array_filter($counts, fn (int $count) => $count >= 2));

        return array_slice($repeated !== [] ? $repeated : array_keys($counts), 0, 16);
    }

    private function formatCluster(array $cluster): array
    {
        $members = collect($cluster['members']);
        $resolved = $members
            ->filter(fn (array $item) => $item['status'] === 'resolved' && !empty($item['resolution']))
            ->sortByDesc('resolved_at')
            ->first();
        $latest = $members->sortByDesc('submitted_at')->first();
        $topKeywords = array_slice($cluster['keywords'], 0, 4);
        $openCount = $members->whereNotIn('status', ['resolved', 'closed'])->count();
        $urgentCount = $members->where('priority', 'urgent')->count();
        $escalatedCount = $members->where('is_escalated', true)->count();
        $investigationScore = min(
            100,
            ($members->count() * 12)
            + ($openCount * 6)
            + ($urgentCount * 15)
            + ($escalatedCount * 10)
        );
        $investigationLevel = match (true) {
            $investigationScore >= 70 => 'critical',
            $investigationScore >= 45 => 'high',
            $investigationScore >= 25 => 'moderate',
            default => 'watch',
        };

        return [
            'group_key' => hash('sha256', implode('|', [
                $cluster['department_id'] ?? 0,
                $cluster['category_id'],
                ...$topKeywords,
            ])),
            'title' => $this->makeTitle((string) $cluster['category'], $topKeywords),
            'category_id' => $cluster['category_id'],
            'category' => $cluster['category'],
            'department_id' => $cluster['department_id'] ?? null,
            'faculty_id' => $cluster['faculty_id'] ?? null,
            'keywords' => $topKeywords,
            'feedback_count' => $members->count(),
            'open_count' => $openCount,
            'resolved_count' => $members->where('status', 'resolved')->count(),
            'urgent_count' => $urgentCount,
            'escalated_count' => $escalatedCount,
            'investigation_score' => $investigationScore,
            'investigation_level' => $investigationLevel,
            'latest_at' => $latest['submitted_at'] ?? null,
            'suggested_solution' => $resolved['resolution'] ?? null,
            'solution_source_id' => $resolved['id'] ?? null,
            'solution_source_date' => $resolved['resolved_at'] ?? null,
            'members' => $members
                ->sortByDesc('submitted_at')
                ->take(8)
                ->map(fn (array $item) => [
                    'id' => $item['id'],
                    'tracking_code' => $item['tracking_code'],
                    'preview' => Str::limit($item['content'], 180),
                    'status' => $item['status'],
                    'priority' => $item['priority'],
                    'sender_role' => $item['sender_role'],
                    'department_id' => $item['recipient_department_id'],
                    'faculty_id' => $item['recipient_faculty_id'],
                    'submitted_at' => $item['submitted_at'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function makeTitle(string $category, array $keywords): string
    {
        if ($keywords === []) {
            return $category;
        }

        return $category.': '.Str::headline(implode(' ', array_slice($keywords, 0, 3)));
    }

    private function canonicalKeyword(string $token): string
    {
        return [
            'teacher' => 'lecturer',
            'teachers' => 'lecturer',
            'lecturers' => 'lecturer',
            'mwalimu' => 'lecturer',
            'walimu' => 'lecturer',
            'darasa' => 'class',
            'darasani' => 'class',
            'classes' => 'class',
            'lecture' => 'class',
            'lectures' => 'class',
            'attend' => 'attendance',
            'attends' => 'attendance',
            'attending' => 'attendance',
            'attendance' => 'attendance',
            'absent' => 'attendance',
            'absence' => 'attendance',
            'haingii' => 'attendance',
            'hajaingia' => 'attendance',
            'hudhuria' => 'attendance',
            'hahudhurii' => 'attendance',
            'fundisha' => 'teaching',
            'kufundisha' => 'teaching',
            'teaches' => 'teaching',
            'teaching' => 'teaching',
            'late' => 'delay',
            'delayed' => 'delay',
            'delay' => 'delay',
            'chelewa' => 'delay',
            'amechelewa' => 'delay',
        ][$token] ?? $token;
    }
}
