<?php

namespace App\Services;

class GeoJsonValidator
{
    /**
     * Validates a GeoJSON structure ensuring it is a valid Polygon.
     * Supports either a raw Polygon geometry or a Feature containing a Polygon geometry.
     *
     * @param mixed $geoJson
     * @return array{valid: bool, error: ?string, normalized: ?array}
     */
    public function validatePolygon($geoJson): array
    {
        if (is_string($geoJson)) {
            $decoded = json_decode($geoJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'valid' => false,
                    'error' => 'Invalid JSON string provided: ' . json_last_error_msg(),
                    'normalized' => null,
                ];
            }
            $geoJson = $decoded;
        }

        if (!is_array($geoJson)) {
            return [
                'valid' => false,
                'error' => 'GeoJSON payload must be a JSON object.',
                'normalized' => null,
            ];
        }

        // Handle Feature wrapper
        if (isset($geoJson['type']) && $geoJson['type'] === 'Feature') {
            if (!isset($geoJson['geometry']) || !is_array($geoJson['geometry'])) {
                return [
                    'valid' => false,
                    'error' => 'GeoJSON Feature must contain a valid geometry object.',
                    'normalized' => null,
                ];
            }
            $geometry = $geoJson['geometry'];
        } else {
            $geometry = $geoJson;
        }

        if (!isset($geometry['type']) || $geometry['type'] !== 'Polygon') {
            return [
                'valid' => false,
                'error' => 'Geometry type must be "Polygon". Received: ' . ($geometry['type'] ?? 'none'),
                'normalized' => null,
            ];
        }

        if (!isset($geometry['coordinates']) || !is_array($geometry['coordinates']) || empty($geometry['coordinates'])) {
            return [
                'valid' => false,
                'error' => 'Polygon must have a non-empty "coordinates" array containing linear rings.',
                'normalized' => null,
            ];
        }

        // Validate each linear ring
        foreach ($geometry['coordinates'] as $ringIndex => $ring) {
            if (!is_array($ring)) {
                return [
                    'valid' => false,
                    'error' => "Linear ring at index {$ringIndex} must be an array of coordinates.",
                    'normalized' => null,
                ];
            }

            // Per RFC 7946, a linear ring must have at least 4 positions
            if (count($ring) < 4) {
                return [
                    'valid' => false,
                    'error' => "Linear ring at index {$ringIndex} must contain at least 4 positions (minimum 3 unique vertices + closing vertex). Found " . count($ring) . ".",
                    'normalized' => null,
                ];
            }

            foreach ($ring as $posIndex => $pos) {
                if (!is_array($pos) || count($pos) < 2) {
                    return [
                        'valid' => false,
                        'error' => "Position at ring {$ringIndex}, index {$posIndex} must be an array of [longitude, latitude].",
                        'normalized' => null,
                    ];
                }

                $lng = $pos[0];
                $lat = $pos[1];

                if (!is_numeric($lng) || !is_numeric($lat)) {
                    return [
                        'valid' => false,
                        'error' => "Coordinates at ring {$ringIndex}, index {$posIndex} must be numeric.",
                        'normalized' => null,
                    ];
                }

                // Range validation (RFC 7946)
                if ($lng < -180 || $lng > 180) {
                    return [
                        'valid' => false,
                        'error' => "Longitude ({$lng}) at ring {$ringIndex}, index {$posIndex} must be between -180 and 180 degrees.",
                        'normalized' => null,
                    ];
                }

                if ($lat < -90 || $lat > 90) {
                    return [
                        'valid' => false,
                        'error' => "Latitude ({$lat}) at ring {$ringIndex}, index {$posIndex} must be between -90 and 90 degrees.",
                        'normalized' => null,
                    ];
                }
            }

            // Check linear ring closure: first position must be identical to last position
            $first = $ring[0];
            $last = $ring[count($ring) - 1];

            $epsilon = 0.00000001;
            if (abs($first[0] - $last[0]) > $epsilon || abs($first[1] - $last[1]) > $epsilon) {
                return [
                    'valid' => false,
                    'error' => "Linear ring at index {$ringIndex} is not closed. The first position [{$first[0]}, {$first[1]}] must match the last position [{$last[0]}, {$last[1]}].",
                    'normalized' => null,
                ];
            }
        }

        $normalized = [
            'type' => 'Polygon',
            'coordinates' => $geometry['coordinates'],
        ];

        return [
            'valid' => true,
            'error' => null,
            'normalized' => $normalized,
        ];
    }
}
