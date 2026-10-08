<?php

namespace App\Services;

use App\Support\AddressFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class AddressSuggestionService
{
    public function suggest(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query));
        if (mb_strlen($query) < 3 || ! config('services.photon.enabled')) {
            return [];
        }
        $key = 'address-suggestions:v2:'.hash('sha256', config('services.photon.url').$query);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        // Share a conservative request budget across all users of the public service.
        if (RateLimiter::tooManyAttempts('address-provider', 30)) {
            return [];
        }
        RateLimiter::hit('address-provider', 60);
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(6)
                ->get(config('services.photon.url'), [
                    'q' => str_replace(' - ', ' ', $query),
                    'limit' => 6,
                    'lat' => 33.5138,
                    'lon' => 36.2765,
                    'countrycode' => 'SY',
                ]);
            if (! $response->successful()) {
                return [];
            }
            $suggestions = collect($response->json('features', []))
                ->map(fn ($feature) => $this->format($feature['properties'] ?? []))
                ->map(function ($suggestion) use ($query) {
                    // Complete the area being entered, not a business/street from
                    // the same result. Details remain the user's next step.
                    if ($suggestion && count(preg_split('/\s*[-–—]\s*/u', $query)) === 2) {
                        $suggestion['value'] = implode(' - ', array_slice(explode(' - ', $suggestion['value']), 0, 2));
                    }

                    return $suggestion;
                })
                ->filter()->unique('value')->values()->all();
            Cache::put($key, $suggestions, now()->addHours(6));

            return $suggestions;
        } catch (Throwable) {
            // Local suggestions and free-text entry remain available during outages.
            return [];
        }
    }

    private function format(array $place): ?array
    {
        $city = $place['city'] ?? '';
        if (($place['state'] ?? '') === 'محافظة دمشق') {
            $city = 'دمشق';
        }
        $area = $place['district'] ?? $place['locality'] ?? '';
        $area = preg_replace('/^حي\s+/u', '', $area);
        $name = $place['name'] ?? '';
        $type = $place['type'] ?? '';
        if ($type === 'city') {
            $city = $name ?: $city;
        } elseif ($type === 'district' || ($type === 'locality' && ($place['osm_key'] ?? '') === 'place' && ($place['osm_value'] ?? '') !== 'square')) {
            $area = preg_replace('/^حي\s+/u', '', $name ?: $area);
            $name = $area;
        }
        $parts = array_values(array_unique(array_filter([$city, $area, $place['street'] ?? '', $name])));
        if (! $parts) {
            return null;
        }
        $value = AddressFormatter::normalize(implode(' - ', $parts));

        return ['value' => $value, 'aliases' => [], 'source' => 'photon'];
    }
}
