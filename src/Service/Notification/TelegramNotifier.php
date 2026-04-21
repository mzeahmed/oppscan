<?php

declare(strict_types=1);

namespace App\Service\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TelegramNotifier
{
    private const API_URL = 'https://api.telegram.org';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $botToken,
        private readonly string $chatId,
    ) {
    }

    public function send(string $message): void
    {
        try {
            $this->httpClient->request('POST', sprintf(
                '%s/bot%s/sendMessage',
                self::API_URL,
                $this->botToken
            ), [
                'json' => [
                    'chat_id' => $this->chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur Telegram', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
