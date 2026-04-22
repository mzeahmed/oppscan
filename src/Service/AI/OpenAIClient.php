<?php

declare(strict_types=1);

namespace App\Service\AI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiBase,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    public function analyze(string $text): array
    {
        $text = $this->cleanText($text);

        $systemPrompt = <<<'PROMPT'
            Tu es un extracteur de données d'offres d'emploi.

            Tu dois répondre STRICTEMENT avec un JSON valide.
            Aucun texte avant.
            Aucun texte après.
            Aucun markdown.
            Aucun bloc ```json.

            Format EXACT :

            {
              "stack": ["php", "symfony", "wordpress"],
              "contract_type": "freelance|cdi|unknown",
              "freelance": true,
              "remote": true,
              "budget": "500€/j ou 45k€/an ou non précisé",
              "recent": true,
              "seniority": "junior|mid|senior|unknown"
            }

            Règles strictes :

            - "freelance" = true UNIQUEMENT si freelance, mission, TJM, contract explicite
            - "contract_type" = "cdi" UNIQUEMENT si CDI explicitement mentionné
            - "remote" = true si remote ou télétravail mentionné
            - "budget" :
              - convertir $ en € approximatif
              - exemple: $80,000 → 80k€/an
              - plage: 80k-110k€/an
            - "seniority" :
              - senior si 3+ ans ou autonomie demandée
            - "stack" :
              - uniquement technologies explicitement présentes

            Si doute → "unknown"

            Réponds UNIQUEMENT avec le JSON.
            PROMPT;

        try {
            $response = $this->httpClient
                ->request('POST', rtrim($this->apiBase, '/') . '/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $this->model,
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $text],
                        ],
                        'temperature' => 0.1,
                        'max_tokens' => 512,
                    ],
                    'timeout' => 30,
                ]);

            $data = $response->toArray(false);
            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            $parsed = json_decode($content, true);
            if (\is_array($parsed)) {
                return $this->normalize($parsed);
            }

            if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
                $parsed = json_decode($matches[0], true);

                if (\is_array($parsed)) {
                    return $this->normalize($parsed);
                }
            }

            $this->logger->warning('OpenAIClient: réponse non parseable, fallback heuristique.', [
                'content' => $content,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenAIClient::analyze() a échoué, fallback heuristique.', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->heuristicFallback($text);
    }

    private function normalize(array $data): array
    {
        $contractType = strtolower((string) ($data['contract_type'] ?? 'unknown'));
        if (!\in_array($contractType, ['freelance', 'cdi', 'unknown'], true)) {
            $contractType = 'unknown';
        }

        $seniority = strtolower((string) ($data['seniority'] ?? 'unknown'));
        if (!\in_array($seniority, ['junior', 'mid', 'senior', 'unknown'], true)) {
            $seniority = 'unknown';
        }

        return [
            'stack' => array_values(array_unique(array_map(
                static fn($item) => strtolower(trim((string) $item)),
                (array) ($data['stack'] ?? [])
            ))),
            'contract_type' => $contractType,
            'freelance' => (bool) ($data['freelance'] ?? false),
            'remote' => (bool) ($data['remote'] ?? false),
            'budget' => (string) ($data['budget'] ?? 'non précisé'),
            'recent' => (bool) ($data['recent'] ?? true),
            'seniority' => $seniority,
        ];
    }

    private function heuristicFallback(string $text): array
    {
        $lower = strtolower($text);
        $freelance = str_contains($lower, 'freelance') || str_contains($lower, 'mission') || str_contains($lower, 'tjm');
        $cdi = str_contains($lower, 'cdi') || str_contains($lower, 'contrat à durée indéterminée');

        if ($freelance) {
            $contractType = 'freelance';
        } elseif ($cdi) {
            $contractType = 'cdi';
        } else {
            $contractType = 'unknown';
        }

        $seniority = 'unknown';
        if (str_contains($lower, 'senior') || str_contains($lower, 'confirmé') || str_contains($lower, 'confirme')) {
            $seniority = 'senior';
        } elseif (str_contains($lower, 'junior') || str_contains($lower, 'débutant') || str_contains($lower, 'debutant')) {
            $seniority = 'junior';
        } elseif (str_contains($lower, 'mid') || str_contains($lower, 'intermédiaire') || str_contains($lower, 'intermediaire')) {
            $seniority = 'mid';
        }

        return [
            'stack' => $this->extractStack($lower),
            'contract_type' => $contractType,
            'freelance' => $freelance,
            'remote' => str_contains($lower, 'remote')
                        || str_contains($lower, 'télétravail')
                        || str_contains($lower, 'teletravail'),
            'budget' => $this->extractBudget($lower),
            'recent' => true,
            'seniority' => $seniority,
        ];
    }

    private function extractStack(string $text): array
    {
        $known = [
            'php',
            'symfony',
            'wordpress',
            'mysql',
            'postgresql',
            'redis',
            'docker',
            'react',
            'vue',
            'api',
            'rabbitmq',
            'laravel',
            'typescript',
            'javascript',
        ];

        return array_values(array_filter(
            $known,
            static fn(string $tech) => str_contains($text, $tech)
        ));
    }

    private function extractBudget(string $text): string
    {
        if (preg_match('/(\d{3,4})\s*€?\s*\/?\s*j(our)?/i', $text, $matches)) {
            return $matches[1] . '€/j';
        }

        if (preg_match('/(\d{2,3})\s*[-–]\s*(\d{2,3})\s*k/i', $text, $matches)) {
            return $matches[1] . '-' . $matches[2] . 'k€/an';
        }

        if (preg_match('/(\d{2,3})\s*k\s*€?/i', $text, $matches)) {
            return $matches[1] . 'k€/an';
        }

        return 'non précisé';
    }

    private function cleanText(string $text): string
    {
        // supprime emojis et caractères bizarres
        $text = preg_replace('/[^\PC\s]/u', '', $text);

        // normalise espaces
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
