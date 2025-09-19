<?php
/**
 * Utilities for shipment movements with timezone support
 */

require_once __DIR__ . '/db.php';

/**
 * Get timezone ID by coordinates (simplified version using major cities)
 */
function get_timezone_by_coords(float $lat, float $lng): array {
    // Simplified timezone detection based on coordinates
    // In production, you'd use a proper timezone database or API
    $timezones = [
        // Europe
        ['lat' => 52.5, 'lng' => 13.4, 'tzid' => 'Europe/Berlin', 'countries' => ['DE']],
        ['lat' => 48.9, 'lng' => 2.3, 'tzid' => 'Europe/Paris', 'countries' => ['FR']],
        ['lat' => 51.5, 'lng' => -0.1, 'tzid' => 'Europe/London', 'countries' => ['GB']],
        ['lat' => 41.9, 'lng' => 12.5, 'tzid' => 'Europe/Rome', 'countries' => ['IT']],
        ['lat' => 40.4, 'lng' => -3.7, 'tzid' => 'Europe/Madrid', 'countries' => ['ES']],
        ['lat' => 50.1, 'lng' => 8.7, 'tzid' => 'Europe/Berlin', 'countries' => ['DE']],
        ['lat' => 50.4, 'lng' => 30.5, 'tzid' => 'Europe/Kiev', 'countries' => ['UA']],
        ['lat' => 55.8, 'lng' => 37.6, 'tzid' => 'Europe/Moscow', 'countries' => ['RU']],
        
        // North America
        ['lat' => 40.7, 'lng' => -74.0, 'tzid' => 'America/New_York', 'countries' => ['US']],
        ['lat' => 34.1, 'lng' => -118.2, 'tzid' => 'America/Los_Angeles', 'countries' => ['US']],
        ['lat' => 41.9, 'lng' => -87.6, 'tzid' => 'America/Chicago', 'countries' => ['US']],
        ['lat' => 43.7, 'lng' => -79.4, 'tzid' => 'America/Toronto', 'countries' => ['CA']],
        
        // Asia
        ['lat' => 35.7, 'lng' => 139.7, 'tzid' => 'Asia/Tokyo', 'countries' => ['JP']],
        ['lat' => 39.9, 'lng' => 116.4, 'tzid' => 'Asia/Shanghai', 'countries' => ['CN']],
        ['lat' => 37.6, 'lng' => 127.0, 'tzid' => 'Asia/Seoul', 'countries' => ['KR']],
        ['lat' => 28.6, 'lng' => 77.2, 'tzid' => 'Asia/Kolkata', 'countries' => ['IN']],
        ['lat' => 1.3, 'lng' => 103.8, 'tzid' => 'Asia/Singapore', 'countries' => ['SG']],
        
        // Australia
        ['lat' => -33.9, 'lng' => 151.2, 'tzid' => 'Australia/Sydney', 'countries' => ['AU']],
        ['lat' => -37.8, 'lng' => 144.9, 'tzid' => 'Australia/Melbourne', 'countries' => ['AU']],
    ];
    
    $bestMatch = null;
    $minDistance = PHP_FLOAT_MAX;
    
    foreach ($timezones as $tz) {
        $distance = sqrt(pow($lat - $tz['lat'], 2) + pow($lng - $tz['lng'], 2));
        if ($distance < $minDistance) {
            $minDistance = $distance;
            $bestMatch = $tz;
        }
    }
    
    if ($bestMatch) {
        return [
            'tzid' => $bestMatch['tzid'],
            'country_code' => $bestMatch['countries'][0] ?? 'US'
        ];
    }
    
    // Fallback
    return ['tzid' => 'UTC', 'country_code' => 'US'];
}

/**
 * Get timezone info by city name
 */
function get_timezone_by_city(string $city, string $country_code = ''): array {
    $city = strtolower(trim($city));
    $country_code = strtoupper(trim($country_code));
    
    $cityTimezones = [
        // Major cities with their timezones
        'berlin' => ['tzid' => 'Europe/Berlin', 'country_code' => 'DE'],
        'paris' => ['tzid' => 'Europe/Paris', 'country_code' => 'FR'],
        'london' => ['tzid' => 'Europe/London', 'country_code' => 'GB'],
        'rome' => ['tzid' => 'Europe/Rome', 'country_code' => 'IT'],
        'madrid' => ['tzid' => 'Europe/Madrid', 'country_code' => 'ES'],
        'kiev' => ['tzid' => 'Europe/Kiev', 'country_code' => 'UA'],
        'kyiv' => ['tzid' => 'Europe/Kiev', 'country_code' => 'UA'],
        'moscow' => ['tzid' => 'Europe/Moscow', 'country_code' => 'RU'],
        'new york' => ['tzid' => 'America/New_York', 'country_code' => 'US'],
        'los angeles' => ['tzid' => 'America/Los_Angeles', 'country_code' => 'US'],
        'chicago' => ['tzid' => 'America/Chicago', 'country_code' => 'US'],
        'toronto' => ['tzid' => 'America/Toronto', 'country_code' => 'CA'],
        'tokyo' => ['tzid' => 'Asia/Tokyo', 'country_code' => 'JP'],
        'beijing' => ['tzid' => 'Asia/Shanghai', 'country_code' => 'CN'],
        'shanghai' => ['tzid' => 'Asia/Shanghai', 'country_code' => 'CN'],
        'seoul' => ['tzid' => 'Asia/Seoul', 'country_code' => 'KR'],
        'mumbai' => ['tzid' => 'Asia/Kolkata', 'country_code' => 'IN'],
        'delhi' => ['tzid' => 'Asia/Kolkata', 'country_code' => 'IN'],
        'singapore' => ['tzid' => 'Asia/Singapore', 'country_code' => 'SG'],
        'sydney' => ['tzid' => 'Australia/Sydney', 'country_code' => 'AU'],
        'melbourne' => ['tzid' => 'Australia/Melbourne', 'country_code' => 'AU'],
    ];
    
    if (isset($cityTimezones[$city])) {
        return $cityTimezones[$city];
    }
    
    // Country-based fallback
    $countryTimezones = [
        'DE' => 'Europe/Berlin',
        'FR' => 'Europe/Paris', 
        'GB' => 'Europe/London',
        'IT' => 'Europe/Rome',
        'ES' => 'Europe/Madrid',
        'UA' => 'Europe/Kiev',
        'RU' => 'Europe/Moscow',
        'US' => 'America/New_York',
        'CA' => 'America/Toronto',
        'JP' => 'Asia/Tokyo',
        'CN' => 'Asia/Shanghai',
        'KR' => 'Asia/Seoul',
        'IN' => 'Asia/Kolkata',
        'SG' => 'Asia/Singapore',
        'AU' => 'Australia/Sydney',
    ];
    
    if ($country_code && isset($countryTimezones[$country_code])) {
        return ['tzid' => $countryTimezones[$country_code], 'country_code' => $country_code];
    }
    
    return ['tzid' => 'UTC', 'country_code' => 'US'];
}

/**
 * Convert UTC datetime to local time with timezone
 */
function convert_utc_to_local(string $utc_datetime, string $tzid): array {
    try {
        $utc = new DateTime($utc_datetime, new DateTimeZone('UTC'));
        $local_tz = new DateTimeZone($tzid);
        $local = $utc->setTimezone($local_tz);
        
        $offset_seconds = $local->getOffset();
        $offset_minutes = $offset_seconds / 60;
        
        return [
            'local_datetime' => $local->format('Y-m-d H:i:s'),
            'utc_offset' => $offset_minutes,
            'formatted_offset' => sprintf('%s%02d:%02d', 
                $offset_seconds >= 0 ? '+' : '-',
                abs($offset_minutes) / 60,
                abs($offset_minutes) % 60
            )
        ];
    } catch (Exception $e) {
        // Fallback to UTC
        return [
            'local_datetime' => $utc_datetime,
            'utc_offset' => 0,
            'formatted_offset' => '+00:00'
        ];
    }
}

/**
 * Parse address to extract city, state, country
 */
function parse_address(string $address): array {
    $parts = array_map('trim', explode(',', $address));
    $parts = array_filter($parts, fn($p) => $p !== '');
    
    $result = [
        'city' => '',
        'state' => '',
        'country_code' => '',
    ];
    
    if (count($parts) >= 1) {
        $result['city'] = $parts[0];
    }
    
    if (count($parts) >= 2) {
        $last = end($parts);
        // Try to detect country code (2 letters) or country name
        if (strlen($last) === 2 && ctype_alpha($last)) {
            $result['country_code'] = strtoupper($last);
            if (count($parts) >= 3) {
                $result['state'] = $parts[count($parts) - 2];
            }
        } else {
            // Map common country names to codes
            $countryMap = [
                'germany' => 'DE', 'deutschland' => 'DE',
                'france' => 'FR',
                'united kingdom' => 'GB', 'uk' => 'GB', 'england' => 'GB',
                'italy' => 'IT', 'italia' => 'IT',
                'spain' => 'ES', 'españa' => 'ES',
                'ukraine' => 'UA', 'україна' => 'UA',
                'russia' => 'RU', 'россия' => 'RU',
                'united states' => 'US', 'usa' => 'US', 'america' => 'US',
                'canada' => 'CA',
                'japan' => 'JP',
                'china' => 'CN',
                'south korea' => 'KR', 'korea' => 'KR',
                'india' => 'IN',
                'singapore' => 'SG',
                'australia' => 'AU',
            ];
            
            $lastLower = strtolower($last);
            if (isset($countryMap[$lastLower])) {
                $result['country_code'] = $countryMap[$lastLower];
                if (count($parts) >= 3) {
                    $result['state'] = $parts[count($parts) - 2];
                }
            } else {
                $result['state'] = $last;
            }
        }
    }
    
    return $result;
}

/**
 * Create a new shipment movement event
 */
function create_movement_event(int $package_id, array $data): bool {
    $pdo = pdo();
    
    // Set defaults
    $data = array_merge([
        'source' => 'map',
        'is_manual' => 0,
        'lat' => null,
        'lng' => null,
        'title' => 'Status Update',
        'message' => '',
        'from_city' => '',
        'from_state' => '',
        'from_country_code' => '',
        'to_city' => '',
        'to_state' => '',
        'to_country_code' => '',
        'facility_name' => '',
        'gateway' => '',
        'postal_code' => '',
        'event_code' => '',
        'status_code' => '',
        'carrier' => '',
        'piece_id' => '',
        'sequence' => 0,
        'created_by' => 'system',
        'address' => ''
    ], $data);
    
    // Auto-detect timezone and convert time
    $utc_now = new DateTime('now', new DateTimeZone('UTC'));
    $event_dt_utc = $data['event_dt_utc'] ?? $utc_now->format('Y-m-d H:i:s');
    
    $tzid = 'UTC';
    $utc_offset = 0;
    $event_dt_local = $event_dt_utc;
    
    // Try to determine timezone
    if (!empty($data['lat']) && !empty($data['lng'])) {
        $tz_info = get_timezone_by_coords((float)$data['lat'], (float)$data['lng']);
        $tzid = $tz_info['tzid'];
        if (empty($data['to_country_code'])) {
            $data['to_country_code'] = $tz_info['country_code'];
        }
    } elseif (!empty($data['address'])) {
        $parsed = parse_address($data['address']);
        if (!empty($parsed['city'])) {
            $tz_info = get_timezone_by_city($parsed['city'], $parsed['country_code']);
            $tzid = $tz_info['tzid'];
            if (empty($data['to_city'])) $data['to_city'] = $parsed['city'];
            if (empty($data['to_state'])) $data['to_state'] = $parsed['state'];
            if (empty($data['to_country_code'])) $data['to_country_code'] = $tz_info['country_code'];
        }
    } elseif (!empty($data['to_city']) || !empty($data['to_country_code'])) {
        $tz_info = get_timezone_by_city($data['to_city'], $data['to_country_code']);
        $tzid = $tz_info['tzid'];
    }
    
    // Convert UTC to local time
    $time_info = convert_utc_to_local($event_dt_utc, $tzid);
    $event_dt_local = $time_info['local_datetime'];
    $utc_offset = $time_info['utc_offset'];
    
    try {
        $sql = "INSERT INTO shipment_movements (
            package_id, source, is_manual, event_dt_utc, event_dt_local, tzid, utc_offset,
            title, message, from_city, from_state, from_country_code,
            to_city, to_state, to_country_code, facility_name, gateway, postal_code,
            lat, lng, event_code, status_code, carrier, piece_id, sequence, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $package_id,
            $data['source'],
            $data['is_manual'] ? 1 : 0,
            $event_dt_utc,
            $event_dt_local,
            $tzid,
            $utc_offset,
            $data['title'],
            $data['message'],
            $data['from_city'],
            $data['from_state'], 
            $data['from_country_code'],
            $data['to_city'],
            $data['to_state'],
            $data['to_country_code'],
            $data['facility_name'],
            $data['gateway'],
            $data['postal_code'],
            $data['lat'],
            $data['lng'],
            $data['event_code'],
            $data['status_code'],
            $data['carrier'],
            $data['piece_id'],
            $data['sequence'],
            $data['created_by'],
            $utc_now->format('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Failed to create movement event: " . $e->getMessage());
        return false;
    }
}

/**
 * Get shipment movements with grouping
 */
function get_shipment_movements(int $package_id, string $group_by = 'country,date'): array {
    $pdo = pdo();
    
    $sql = "SELECT * FROM shipment_movements 
            WHERE package_id = ? 
            ORDER BY event_dt_local DESC, sequence DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$package_id]);
    $movements = $stmt->fetchAll();
    
    if (empty($movements)) {
        return [];
    }
    
    // Group movements
    $groups = [];
    
    foreach ($movements as $movement) {
        $country = $movement['to_country_code'] ?: 'Unknown';
        $date = substr($movement['event_dt_local'] ?: $movement['event_dt_utc'], 0, 10);
        
        if ($group_by === 'country,date') {
            $groups[$country][$date][] = $movement;
        } else { // date,country
            $groups[$date][$country][] = $movement;
        }
    }
    
    return $groups;
}

/**
 * Format country name from country code
 */
function get_country_name(string $country_code): string {
    $countries = [
        'DE' => 'Germany',
        'FR' => 'France', 
        'GB' => 'United Kingdom',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'UA' => 'Ukraine',
        'RU' => 'Russia',
        'US' => 'United States',
        'CA' => 'Canada',
        'JP' => 'Japan',
        'CN' => 'China',
        'KR' => 'South Korea',
        'IN' => 'India',
        'SG' => 'Singapore',
        'AU' => 'Australia',
    ];
    
    return $countries[$country_code] ?? $country_code;
}

/**
 * Format date for display
 */
function format_movement_date(string $date, string $locale = 'en_US'): string {
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $date;
    }
    
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE);
        $formatter->setPattern('EEEE, d MMMM y');
        return $formatter->format($timestamp);
    }
    
    // Fallback
    return date('l, j F Y', $timestamp);
}

/**
 * Format time with UTC offset
 */
function format_movement_time(string $datetime, int $utc_offset): string {
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return $datetime;
    }
    
    $time = date('H:i', $timestamp);
    $offset_hours = abs($utc_offset) / 60;
    $offset_str = sprintf('%s%02d:%02d', 
        $utc_offset >= 0 ? '+' : '−',
        $offset_hours,
        abs($utc_offset) % 60
    );
    
    return "{$time} (UTC{$offset_str})";
}