<?php

namespace App\Services;

class ProviderErrorMessage
{
    public function forStatus(string $provider, int $status): string
    {
        $providerName = config("transcription.providers.{$provider}.label", 'provider');

        return match (true) {
            in_array($status, [401, 403], true) => "A API key da {$providerName} é inválida ou não tem permissão.",
            $status === 429 => "A {$providerName} limitou as requisições. Tente novamente mais tarde.",
            in_array($status, [408, 504], true) => "A {$providerName} demorou demais para responder.",
            in_array($status, [413, 422], true) => "A {$providerName} rejeitou o arquivo enviado.",
            $status >= 500 => "A {$providerName} está indisponível no momento.",
            default => "A {$providerName} não conseguiu transcrever o arquivo.",
        };
    }

    public function forConnection(string $provider): string
    {
        $providerName = config("transcription.providers.{$provider}.label", 'provider');

        return "Não foi possível conectar à {$providerName} dentro do tempo limite.";
    }
}
