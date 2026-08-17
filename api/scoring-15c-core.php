<?php
declare(strict_types=1);

/** Moteur PASS50 — 15 critères actifs (v1.1). */
const P50_15C_ALGORITHM_VERSION = '15C-v1.1';

function p50_15c_weights(): array {
    return [
        'c1' => .06, 'c2' => .09, 'c3' => .07, 'c4' => .07, 'c5' => .07,
        'c6' => .05, 'c7' => .11, 'c8' => .07, 'c9' => .06, 'c10' => .06,
        'c11' => .05, 'c12' => .06, 'c13' => .04, 'c14' => .07, 'c15' => .07,
    ];
}

/**
 * @param array<int,array{v:float,t:int}> $followerSamples
 * @param array<int,float> $velocities
 */
function p50_15c_build_raw(
    float $followers,
    float $views,
    float $likes,
    float $comments,
    float $shares,
    float $saves,
    ?float $platformSum,
    float $eventWeight,
    array $velocities,
    array $followerSamples,
    float $liveWeight
): array {
    $growth = null;
    if (count($followerSamples) >= 2) {
        usort($followerSamples, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
        $first = (float)$followerSamples[0]['v'];
        $last = (float)$followerSamples[count($followerSamples) - 1]['v'];
        if ($first > 0) {
            $growth = ($last - $first) / $first;
        }
    }

    $velocity = $velocities ? array_sum($velocities) / count($velocities) : null;
    $engagement = $views > 0 ? ($likes + 3 * $comments + 5 * $shares + 4 * $saves) / $views : null;
    $shareRate = $views > 0 ? ($shares + $saves) / $views : null;

    $acceleration = null;
    if (count($velocities) >= 2) {
        $mid = max(1, (int)floor(count($velocities) / 2));
        $firstHalf = array_slice($velocities, 0, $mid);
        $secondHalf = array_slice($velocities, $mid);
        $avgFirst = array_sum($firstHalf) / count($firstHalf);
        $avgSecond = $secondHalf ? array_sum($secondHalf) / count($secondHalf) : 0.0;
        if ($avgFirst > 0) {
            $acceleration = log10(1 + max(0, $avgSecond / $avgFirst));
        } elseif ($avgSecond > 0) {
            $acceleration = log10(2);
        }
    }

    $coherence = ($views > 0 && ($likes + $comments + $shares) > 0)
        ? max(0, min(100, 70 + min(25, log10(1 + $views) * 3) - min(20, abs(($likes + $comments + $shares) / max(1, $views) - 0.08) * 100)))
        : null;

    return [
        'c1' => $followers > 0 ? log10(1 + $followers) : null,
        'c2' => $views > 0 ? log10(1 + $views) : null,
        'c3' => $growth,
        'c4' => $engagement,
        'c5' => $shareRate,
        'c6' => $comments > 0 ? log10(1 + $comments) : null,
        'c7' => $velocity !== null ? log10(1 + $velocity) : null,
        'c8' => $platformSum,
        'c9' => $shares > 0 ? log10(1 + $shares) : null,
        'c10' => $likes > 0 ? log10(1 + $likes) : null,
        'c11' => $saves > 0 ? log10(1 + $saves) : null,
        'c12' => $liveWeight > 0 ? $liveWeight : null,
        'c13' => $eventWeight > 0 ? $eventWeight : null,
        'c14' => $coherence,
        'c15' => $acceleration,
    ];
}

function p50_15c_normalize_score(string $key, float $value): float {
    return match ($key) {
        'c2', 'c6', 'c7', 'c9', 'c10', 'c11', 'c15' => max(0, min(100, 20 + $value * 16)),
        'c3' => max(0, min(100, 50 + $value * 100)),
        'c4' => max(0, min(100, $value * 500)),
        'c5' => max(0, min(100, $value * 1000)),
        'c8' => max(0, min(100, $value * 20)),
        'c12' => max(0, min(100, $value * 25)),
        'c13' => max(0, min(100, $value * 8)),
        'c14' => max(0, min(100, $value)),
        default => max(0, min(100, 50 + $value * 10)),
    };
}

/** @return array{scores:array<string,float>,sum:float,available:float,coverage:float,base:float} */
function p50_15c_score_raw(array $raw): array {
    $weights = p50_15c_weights();
    $scores = [];
    $sum = 0.0;
    $available = 0.0;
    foreach ($raw as $key => $value) {
        if ($value === null || !is_finite((float)$value)) {
            continue;
        }
        $score = p50_15c_normalize_score((string)$key, (float)$value);
        $weight = (float)($weights[$key] ?? 0);
        $scores[$key] = round($score, 2);
        $sum += $score * $weight;
        $available += $weight;
    }
    $base = $available > 0 ? $sum / $available : 0.0;
    return [
        'scores' => $scores,
        'sum' => $sum,
        'available' => $available,
        'coverage' => $available * 100,
        'base' => $base,
    ];
}

function p50_15c_is_live_event_type(string $eventType): bool {
    $type = strtolower(trim($eventType));
    return $type === 'live' || str_contains($type, 'live');
}
