<?php

namespace App\Services;

use App\Models\FinanceCurrency;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SpTodayExchangeRateService
{
    public const SOURCE_URL = 'https://sp-today.com/en/currency/us-dollar';

    public function refreshUsdSypRate(): ?int
    {
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $response = Http::accept('text/html')
                ->timeout(5)
                ->retry(1, 150)
                ->get(self::SOURCE_URL);

            if (! $response->successful()) {
                return null;
            }

            $average = $this->averageRateFromHtml($response->body());
            if ($average === null) {
                return null;
            }

            $usd = FinanceCurrency::query()->where('code', 'USD')->where('is_active', true)->first();
            $syp = FinanceCurrency::query()->where('code', 'SYP')->where('is_active', true)->first();
            if (! $usd || ! $syp || (float) $usd->rate_to_base <= 0) {
                return null;
            }

            app(FinanceService::class)->updateCurrencyRate(
                $syp,
                (float) $usd->rate_to_base / $average,
                null,
                $usd,
            );

            return $average;
        } catch (Throwable $exception) {
            Log::warning('Unable to refresh the USD/SYP rate from SP Today.', [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function averageRateFromHtml(string $html): ?int
    {
        $html = str_replace(['\\"', '&quot;'], '"', $html);
        $patterns = [
            '/"(?:general|damascus)"\s*:\s*\{[^{}]*?"buy"\s*:\s*([0-9.]+)[^{}]*?"sell"\s*:\s*([0-9.]+)/i',
            '/"buy"\s*:\s*([0-9]{4,}(?:\.[0-9]+)?)[^{}]{0,80}"sell"\s*:\s*([0-9]{4,}(?:\.[0-9]+)?)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $buy = (float) $matches[1];
                $sell = (float) $matches[2];

                if ($buy > 0 && $sell > 0) {
                    $average = ($buy + $sell) / 2;

                    // SP Today still exposes the pre-redenomination SYP quote in
                    // some payloads (for example 13,146 instead of 131.46).
                    if ($average >= 1000) {
                        $average /= 100;
                    }

                    return (int) ceil($average);
                }
            }
        }

        return null;
    }
}
