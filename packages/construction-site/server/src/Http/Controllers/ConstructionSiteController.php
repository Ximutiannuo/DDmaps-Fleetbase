<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\ConstructionSite\Support\PublicErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ConstructionSiteController extends FleetbaseController
{
    use PublicErrors;
    /**
     * The package namespace used to resolve models and resources from.
     */
    public string $namespace = '\\Fleetbase\\ConstructionSite';

    public function queryRecord(Request $request)
    {
        // The platform's generic query does not guarantee company filtering for
        // extension models. Bypass old cached unscoped results as well.
        $this->model->disableApiCache = true;
        return parent::queryRecord($request);
    }

    public function onQueryRecord($query, Request $request): void
    {
        abort_unless(session('company'), 401);
        // Group client filters so an OR expression cannot escape the boundary.
        $raw = $query->getQuery();
        if ($raw->wheres) {
            $nested = $raw->newQuery();
            $nested->wheres = $raw->wheres;
            $nested->setBindings($raw->getRawBindings()['where'], 'where');
            $raw->wheres = [];
            $raw->setBindings([], 'where');
            $raw->addNestedWhereQuery($nested);
        }
        $query->where($this->model->qualifyColumn('company_uuid'), session('company'));
    }

    protected function ownedRecord(string $id)
    {
        abort_unless(session('company'), 401);
        return $this->model->newQuery()->where('company_uuid', session('company'))
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
    }

    public function findRecord(Request $request, $id)
    {
        return [$this->resourceSingularlName => new $this->resource($this->ownedRecord($id))];
    }

    protected function siteInput(Request $request): array
    {
        $this->validateRequest($request);
        $input = (array) $this->model->getApiPayloadFromRequest($request);
        $input['company_uuid'] = session('company');
        abort_unless($input['company_uuid'], 401);
        foreach (['road_segment_uuid' => 'road_segments', 'site_road_uuid' => 'site_roads',
            'vehicle_uuid' => 'vehicles', 'driver_uuid' => 'drivers', 'gate_uuid' => 'gates'] as $field => $table) {
            if (!empty($input[$field])) {
                Validator::make($input, [$field => ['string',
                    \Illuminate\Validation\Rule::exists($table, 'uuid')->where('company_uuid', session('company'))->whereNull('deleted_at')]])->validate();
            }
        }
        return $input;
    }

    public function createRecord(Request $request)
    {
        try {
            $record = DB::transaction(function () use ($request) {
                $input = $this->siteInput($request);
                $record = $this->model->newInstance($input);
                $record->save();
                if (method_exists($this, 'onAfterCreate')) $this->onAfterCreate($request, $record, $input);
                return $record->refresh();
            });
            return [$this->resourceSingularlName => new $this->resource($record)];
        } catch (\Throwable $error) { return $this->publicError($error); }
    }

    public function updateRecord(Request $request, $id)
    {
        // Resolve before parsing the payload; foreign records always return 404.
        $record = $this->ownedRecord($id);
        try {
            $record->fill($this->siteInput($request))->save();
            return [$this->resourceSingularlName => new $this->resource($record->refresh())];
        } catch (\Throwable $error) { return $this->publicError($error); }
    }

    public function deleteRecord($id, Request $request)
    {
        $record = $this->ownedRecord($id);
        $record->delete();
        return [$this->resourceSingularlName => new $this->resource($record)];
    }

    public function bulkDelete(\Fleetbase\Http\Requests\Internal\BulkDeleteRequest $request)
    {
        $ids = $request->input('ids', []);
        Validator::make(['ids' => $ids], ['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'string', 'distinct']])->validate();
        return DB::transaction(function () use ($ids) {
            \Fleetbase\Models\Company::where('uuid', session('company'))->lockForUpdate()->firstOrFail();
            // Resolve every record before deleting any: a mixed-company batch
            // must fail atomically rather than partially deleting owned data.
            $records = array_map(fn ($id) => $this->ownedRecord($id), $ids);
            foreach ($records as $record) $record->delete();
            return response()->json(['status' => 'success', 'count' => count($records)]);
        });
    }
}
