<?php

namespace App\Actions;

class VerifyGlobalPassword
{
    public function handle(string $candidate): bool
    {
        $configuredPassword = (string) config('app.access_password', '');

        return $configuredPassword !== '' && hash_equals($configuredPassword, $candidate);
    }
}
