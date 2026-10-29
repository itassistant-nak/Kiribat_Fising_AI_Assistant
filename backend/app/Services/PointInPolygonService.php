<?php

namespace App\Services;

class PointInPolygonService
{
    /**
     * Determine if a GPS coordinate (latitude, longitude) is inside a GeoJSON Polygon.
     * Uses the ray-casting algorithm (crossing number).
     *
     * Note: In GeoJSON, coordinates are formatted as [longitude, latitude].
     *
     * @param float $latitude Target point latitude (-90 to 90)
     * @param float $longitude Target point longitude (-180 to 180)
     * @param array $polygonGeoJson Decoded GeoJSON Polygon geometry or feature
     * @return bool
     */
    public function containsPoint(float $latitude, float $longitude, array $polygonGeoJson): bool
    {
        $coordinates = $polygonGeoJson['coordinates'] ?? null;
        if (isset($polygonGeoJson['geometry']['coordinates'])) {
            $coordinates = $polygonGeoJson['geometry']['coordinates'];
        }

        if (!is_array($coordinates) || empty($coordinates)) {
            return false;
        }

        // The outer ring (boundary) is at index 0
        $outerRing = $coordinates[0];
        if (!$this->isPointInLinearRing($latitude, $longitude, $outerRing)) {
            return false;
        }

        // If there are interior rings (holes), the point must NOT be inside any hole
        $ringCount = count($coordinates);
        for ($i = 1; $i < $ringCount; $i++) {
            $holeRing = $coordinates[$i];
            if ($this->isPointInLinearRing($latitude, $longitude, $holeRing)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ray-casting algorithm to test whether a point (lat, lng) is inside a single closed ring.
     * Ring positions are [lng, lat].
     *
     * @param float $lat
     * @param float $lng
     * @param array $ring
     * @return bool
     */
    private function isPointInLinearRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        if ($count < 4) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float)$ring[$i][0]; // longitude i
            $yi = (float)$ring[$i][1]; // latitude i
            $xj = (float)$ring[$j][0]; // longitude j
            $yj = (float)$ring[$j][1]; // latitude j

            // Vertex match check (within small epsilon)
            if (abs($xi - $lng) < 0.0000001 && abs($yi - $lat) < 0.0000001) {
                return true;
            }

            // Ray-casting intersection formula
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
