<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Services\OsmRoadImportService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\Support\ApiModelCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoadImportController extends ConstructionSiteController
{
    public $resource = 'road_segment';

    private function border(ServiceArea $area): array
    {
        $border = $area->border;
        return is_array($border) ? $border : ($border ? json_decode($border->toJson(), true) : []);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['road_import' => $message]);
    }

    private function input(Request $request, array $rules): array
    {
        return Validator::make(json_decode($request->getContent(), true) ?? $request->all(), $rules)->validate();
    }

    public function preview(Request $request, OsmRoadImportService $importer)
    {
        $data = $this->input($request, ['service_area_uuid' => ['required', 'uuid']]);
        $company = (string) session('company');
        $area = ServiceArea::where('company_uuid', $company)->where('uuid', $data['service_area_uuid'])->firstOrFail();
        $border = $this->border($area);
        try { $bbox = $importer->bbox($border); } catch (\InvalidArgumentException $e) { $this->fail($e->getMessage()); }
        $bboxArea = ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]) * 111.32 ** 2 * cos(deg2rad(($bbox[0] + $bbox[2]) / 2));
        if ($bboxArea <= 0 || $bboxArea > config('construction-site.road_import.max_bbox_km2', 100)) $this->fail('GCC 范围过大或无效，请将区域划分为不超过 100 平方公里的范围后导入。');
        $borderHash = sha1(json_encode($border));
        $endpoint = config('construction-site.road_import.endpoint');
        $cacheKey = 'osm-roads:' . sha1($endpoint . ':' . $company . ':' . $borderHash);
        $osm = Cache::get($cacheKey);
        $cached = $osm !== null;
        if (!$osm) {
            // Public Overpass queries are cached; GPS and navigation never call this endpoint.
            $lock = Cache::lock($cacheKey . ':fetch', 60);
            if (!$lock->get()) $this->fail('当前 GCC 的道路正在获取，请稍后重试。');
            try {
                $response = Http::asForm()->connectTimeout(10)->timeout(45)
                    ->withHeaders(['User-Agent' => 'Fleetbase-ConstructionSite/1.0 (OSM road import)'])
                    ->post($endpoint, ['data' => $importer->query($bbox)]);
                if (!$response->successful() || strlen($response->body()) > 16_000_000) $this->fail('地图道路服务暂时繁忙或返回数据过大，请稍后重试。');
                $body = $response->json();
                if (!is_array($body) || !isset($body['elements']) || !empty($body['remark'])) $this->fail('地图道路查询未完整完成，请稍后重试。');
                $osm = ['elements' => $body['elements'], 'fetched_at' => now()->toIso8601String()];
                Cache::put($cacheKey, $osm, config('construction-site.road_import.cache_seconds', 3600));
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $this->fail('无法连接地图道路服务，或请求超时。现有道路仍可使用，请稍后重试。');
            } finally { $lock->release(); }
        }
        $candidates = $importer->candidates($osm, $border, $area->uuid);
        if (count($candidates) > config('construction-site.road_import.max_candidates', 1500)) $this->fail('区域内道路过多，请缩小 GCC 范围后导入。');
        $candidates = $importer->markExisting($candidates, RoadSegment::withTrashed()->where('company_uuid', $company)->get()->toArray());
        $token = (string) Str::uuid();
        $ttl = config('construction-site.road_import.preview_seconds', 900);
        $preview = ['company_uuid' => $company, 'service_area_uuid' => $area->uuid, 'border_hash' => $borderHash,
            'candidates' => $candidates, 'fetched_at' => $osm['fetched_at'], 'expires_at' => now()->addSeconds($ttl)->toIso8601String()];
        Cache::put('road-preview:' . $token, $preview, $ttl);
        return response()->json(['preview_token' => $token, 'service_area_uuid' => $area->uuid,
            'candidates' => $candidates, 'cached' => $cached, 'fetched_at' => $preview['fetched_at'], 'expires_at' => $preview['expires_at'],
            'attribution' => '© OpenStreetMap contributors · ODbL 1.0']);
    }

    public function commit(Request $request, OsmRoadImportService $importer)
    {
        $data = $this->input($request, [
            'service_area_uuid' => ['required', 'uuid'], 'preview_token' => ['required', 'uuid'],
            'candidate_keys' => ['required', 'array', 'min:1', 'max:1500'],
            'candidate_keys.*' => ['required', 'string', 'max:100', 'distinct'],
        ]);
        $company = (string) session('company');
        $preview = Cache::get('road-preview:' . $data['preview_token']);
        if (!$preview || $preview['company_uuid'] !== $company || $preview['service_area_uuid'] !== $data['service_area_uuid']) $this->fail('道路预览已过期或不属于当前 GCC，请重新获取道路。');
        $result = DB::transaction(function () use ($data, $company, $preview, $importer) {
            // Serialize imports and edits to the same area; recheck overlaps inside the transaction.
            DB::table('companies')->where('uuid', $company)->lockForUpdate()->first();
            $area = ServiceArea::where('company_uuid', $company)->where('uuid', $data['service_area_uuid'])->lockForUpdate()->firstOrFail();
            if (sha1(json_encode($this->border($area))) !== $preview['border_hash']) $this->fail('GCC 边界已经改变，请重新获取道路。');
            $existing = RoadSegment::withTrashed()->where('company_uuid', $company)->lockForUpdate()->get()->toArray();
            $graph = app(SiteRoadGraphService::class);
            $before = $graph->topology(array_values(array_filter($existing, fn ($road) => empty($road['deleted_at']))));
            $candidates = $importer->markExisting($preview['candidates'], $existing);
            $byKey = array_column($candidates, null, 'key');
            $batchCreated = [];
            $created = []; $skipped = 0;
            foreach ($data['candidate_keys'] as $key) {
                if (!isset($byKey[$key])) $this->fail('选择中包含未知道路，请重新获取预览。');
                $candidate = $byKey[$key];
                if ($candidate['availability'] !== 'new') { $skipped++; continue; }
                if ($batchCreated) $candidate = $importer->markExisting([$candidate], $batchCreated)[0];
                if ($candidate['availability'] !== 'new') { $skipped++; continue; }
                $segment = (new RoadSegment())->forceFill([
                    'company_uuid' => $company, 'name' => $candidate['name'], 'sequence' => count($created),
                    'status' => $candidate['restricted'] ? 'closed' : $candidate['status'],
                    'geometry' => $candidate['geometry'], 'source' => 'osm', 'source_key' => $key,
                    'source_meta' => array_merge($candidate['source_meta'], ['fetched_at' => $preview['fetched_at'], 'imported_at' => now()->toIso8601String()]),
                    'routing' => $candidate['routing'],
                ]);
                // Initialize identifiers explicitly and avoid one network broadcast per imported way.
                RoadSegment::withoutEvents(function () use ($segment) {
                    $segment->uuid = RoadSegment::generateUuid();
                    $segment->public_id = RoadSegment::generatePublicId();
                    $segment->save();
                });
                $created[] = $segment->uuid;
                $batchCreated[] = $segment->toArray();
            }
            if ($created) {
                $after = $graph->topology(RoadSegment::where('company_uuid', $company)->get()->toArray());
                $this->inheritControls($company, $before, $after, $graph);
                DB::afterCommit(function () use ($company, $created, $area) {
                    ApiModelCache::invalidateModelCache(new RoadSegment(), $company);
                    event(new RoadNetworkChanged($company, 'road_segment', 'imported', ['count' => count($created)], $area->uuid));
                });
            }
            return ['created_count' => count($created), 'skipped_count' => $skipped, 'road_segment_uuids' => $created];
        }, 3);
        // Retain the preview until expiry so a lost response can safely be retried.
        return response()->json($result, 201);
    }

    private function inheritControls(string $company, array $before, array $after, SiteRoadGraphService $graph): void
    {
        app(\Fleetbase\ConstructionSite\Services\RoadControlInheritanceService::class)->inheritControls($company, $before, $after, $graph);
    }
}
