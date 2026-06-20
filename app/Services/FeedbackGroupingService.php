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

        return array_slice(array_keys($counts), 0, 16);
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

        return [
            'group_key' => hash('sha256', $cluster['category_id'].'|'.implode('|', $topKeywords)),
            'title' => $this->makeTitle((string) $cluster['category'], $topKeywords),
            'category_id' => $cluster['category_id'],
            'category' => $cluster['category'],
            'keywords' => $topKeywords,
            'feedback_count' => $members->count(),
            'open_count' => $members->whereNotIn('status', ['resolved', 'closed'])->count(),
            'resolved_count' => $members->where('status', 'resolved')->count(),
            'urgent_count' => $members->where('priority', 'urgent')->count(),
            'departments_count' => $members->pluck('recipient_department_id')->filter()->unique()->count(),
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
}
