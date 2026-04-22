<?php

declare(strict_types=1);

namespace App\Service\Scoring;

use App\DTO\JobDTO;

final class ScoringService
{
    /**
     * Score rapide sans IA, utilisé pour décider si l'analyse IA vaut le coup.
     */
    public function preScore(JobDTO $job): int
    {
        $score = 0;
        $text = strtolower($job->title . ' ' . $job->description);

        if (str_contains($text, 'php')) {
            $score += 10;
        }

        if (str_contains($text, 'symfony')) {
            $score += 15;
        }

        if (str_contains($text, 'wordpress')) {
            $score += 10;
        }

        if (str_contains($text, 'remote') || str_contains($text, 'télétravail') || str_contains($text, 'teletravail')) {
            $score += 5;
        }

        if (str_contains($text, 'stage')) {
            $score -= 50;
        }

        if (str_contains($text, 'alternance')) {
            $score -= 50;
        }

        return $score;
    }

    /**
     * Score final sur 100, basé sur les données IA enrichies.
     *
     * Bonus :
     *   PHP dans le titre        → +20
     *   Symfony dans la stack    → +30
     *   WordPress dans la stack  → +20
     *   Freelance                → +15
     *   CDI                      → +10
     *   Remote                   → +10
     *   Offre récente            → +20
     *   Senior                   → +10
     *   Mission                  → +10
     *   Urgent / ASAP            → +15
     *
     * Malus :
     *   Stage                    → -50
     *   Alternance               → -50
     *   Junior                   → -20
     */
    public function compute(JobDTO $job, array $ai): int
    {
        $score = 0;
        $desc = strtolower($job->description);

        if (str_contains(strtolower($job->title), 'php')) {
            $score += 20;
        }

        $stack = array_map('strtolower', $ai['stack'] ?? []);

        if (\in_array('symfony', $stack, true)) {
            $score += 30;
        }

        if (\in_array('wordpress', $stack, true)) {
            $score += 20;
        }

        $contractType = $ai['contract_type'] ?? 'unknown';
        if ('freelance' === $contractType || ($ai['freelance'] ?? false)) {
            $score += 15;
        } elseif ('cdi' === $contractType) {
            $score += 10;
        }

        if ($ai['remote'] ?? false) {
            $score += 10;
        }

        if ($ai['recent'] ?? false) {
            $score += 20;
        }

        $seniority = $ai['seniority'] ?? 'unknown';
        if ('senior' === $seniority || str_contains($desc, 'senior') || str_contains($desc, 'confirmé')) {
            $score += 10;
        }

        if (str_contains($desc, 'mission')) {
            $score += 10;
        }

        if (str_contains($desc, 'urgent') || str_contains($desc, 'asap')) {
            $score += 15;
        }

        // Malus
        if (str_contains($desc, 'stage')) {
            $score -= 50;
        }

        if (str_contains($desc, 'alternance')) {
            $score -= 50;
        }

        if ('junior' === $seniority || str_contains($desc, 'junior')) {
            $score -= 20;
        }

        return max(0, min($score, 100));
    }
}
