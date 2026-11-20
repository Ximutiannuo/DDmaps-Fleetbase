<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Support\SiteSocketToken;
use Illuminate\Http\Request;

final class SiteSocketController extends ConstructionSiteController
{
    public $resource = 'entry_registration';

    public function member(Request $request)
    {
        $company = (string) session('company');
        $drivers = \Fleetbase\FleetOps\Models\Driver::where('company_uuid', $company)->pluck('uuid')->map(fn ($uuid) => 'driver.' . $uuid)->all();
        return $this->token(['role' => 'member', 'company' => $company,
            'channels' => array_merge(['company.' . $company, 'company.' . $company . '.orders', 'road-network.' . $company, 'user.' . $request->user()->uuid], $drivers)]);
    }

    public function driver(Request $request)
    {
        $entry = $request->attributes->get('entryRegistration');
        return $this->token(['role' => 'driver', 'company' => $entry->company_uuid,
            'channels' => ['road-network.' . $entry->company_uuid]]);
    }

    private function token(array $claims)
    {
        return response()->json(['token' => SiteSocketToken::sign($claims), 'expires_in' => 300])->header('Cache-Control', 'no-store');
    }
}
