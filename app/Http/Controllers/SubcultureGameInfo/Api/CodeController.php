<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\RedeemCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodeController extends Controller
{
    /**
     * 사용 가능한 리딤코드 목록.
     * 쿼리: game(slug), community(0/1, 기본 1), expired(0/1, 기본 0)
     */
    public function index(Request $request): JsonResponse
    {
        $includeCommunity = $request->boolean('community', true);
        $includeExpired = $request->boolean('expired', false);
        $verifiedOnly = $request->boolean('verified', false);
        $slug = $request->query('game');

        $query = RedeemCode::query()->with('game');

        if (! $includeExpired) {
            $query->usable();
        }
        if (! $includeCommunity) {
            $query->main();
        }
        if ($verifiedOnly) {
            $query->verified();
        }
        if ($slug) {
            $query->whereHas('game', fn ($q) => $q->where('slug', $slug));
        }

        $codes = $query
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'unverified' THEN 1 ELSE 2 END")
            ->orderByDesc('corroboration_count')
            ->orderByDesc('found_at')
            ->get();

        $data = $codes->map(fn (RedeemCode $c) => [
            'game' => [
                'slug' => $c->game?->slug,
                'name' => $c->game?->name,
                'icon' => $c->game?->icon,
            ],
            'code' => $c->code,
            'region' => $c->region->value,
            'region_label' => $c->region->label(),
            'rewards' => $c->rewards,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'verified' => $c->is_verified,
            'corroboration_count' => $c->corroboration_count,
            'seen_sources' => $c->seen_sources,
            'source' => $c->source,
            'source_type' => $c->source_type->value,
            'source_url' => $c->source_url,
            'redeem_url' => $c->game?->redeemUrlFor($c->code),
            'redeem_note' => $c->game?->redeem_note,
            'expires_at' => $c->expires_at?->toIso8601String(),
            'days_left' => $c->expires_at ? $c->days_left : null,
            'found_at' => $c->found_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $data->count()],
        ]);
    }
}
