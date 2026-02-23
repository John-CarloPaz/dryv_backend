<?php

namespace App\Jobs;

use App\Models\CommunityFloodReport;
use App\Models\CommunityFloodRoadStat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ComputeCommunityFloodChiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $roadGid;
    public int $segmentKey;

    public function __construct(int $roadGid, int $segmentKey)
    {
        $this->roadGid = $roadGid;
        $this->segmentKey = $segmentKey;
    }

    public function handle(): void
    {
        $hours = (int) config('community_reporting.aggregation_window_hours', 24);
        $windowStart = now()->subHours(max(1, $hours));

        $agg = CommunityFloodReport::query()
            ->where('road_gid', $this->roadGid)
            ->where('segment_key', $this->segmentKey)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('COUNT(*) AS reports_count, COALESCE(SUM(hazard_weight), 0) AS hazard_sum, COALESCE(MAX(rainfall), 0) AS rainfall, AVG(estimated_depth) AS avg_estimated_depth, MAX(created_at) AS last_reported_at')
            ->first();

        $count = (int) ($agg->reports_count ?? 0);
        $hazardSum = (float) ($agg->hazard_sum ?? 0.0);
        $rainfall = (float) ($agg->rainfall ?? 0.0);
        $chi = max(0.0, $hazardSum) * max(0.0, $rainfall);
        $avgDepth = is_numeric($agg->avg_estimated_depth ?? null) ? (float) $agg->avg_estimated_depth : null;

        $thresholds = (array) config('community_reporting.chi_thresholds', []);
        $low = (float) ($thresholds['low'] ?? 201.0);
        $medium = (float) ($thresholds['medium'] ?? 351.0);
        $high = (float) ($thresholds['high'] ?? 551.0);

        $risk = 0;
        if ($chi >= $high) {
            $risk = 3;
        } elseif ($chi >= $medium) {
            $risk = 2;
        } elseif ($chi >= $low) {
            $risk = 1;
        }

        $center = CommunityFloodReport::query()
            ->where('road_gid', $this->roadGid)
            ->where('segment_key', $this->segmentKey)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('AVG(snapped_lat) AS center_lat, AVG(snapped_lng) AS center_lng')
            ->first();

        $centerLat = is_object($center) && is_numeric($center->center_lat ?? null) ? (float) $center->center_lat : null;
        $centerLng = is_object($center) && is_numeric($center->center_lng ?? null) ? (float) $center->center_lng : null;

        CommunityFloodRoadStat::updateOrCreate(
            ['road_gid' => $this->roadGid, 'segment_key' => $this->segmentKey],
            [
                'center_lat' => $centerLat,
                'center_lng' => $centerLng,
                'chi_score' => $chi,
                'risk_level' => $risk,
                'reports_count' => $count,
                'avg_estimated_depth' => $avgDepth,
                'last_reported_at' => $agg->last_reported_at ?? now(),
            ]
        );
    }
}
