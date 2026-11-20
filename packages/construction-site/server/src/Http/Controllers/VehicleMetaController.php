<?php

namespace Fleetbase\ConstructionSite\Http\Controllers;

use Fleetbase\ConstructionSite\Models\VehicleConstructionMeta;
use Fleetbase\Support\Resolve;

class VehicleMetaController extends ConstructionSiteController
{
    /**
     * The resource to query.
     *
     * Note: `vehicle_construction_meta` cannot be auto-resolved from the
     * resource string because Doctrine's inflector singularizes "meta" to
     * "metum". The full model class is used instead so the model resolves
     * correctly, and the HTTP resource is resolved explicitly afterwards.
     *
     * @var string
     */
    public $resource = '\\Fleetbase\\ConstructionSite\\Models\\VehicleConstructionMeta';

    public function __construct()
    {
        parent::__construct();

        $this->resource              = Resolve::httpResourceForModel($this->model, $this->namespace);
        $this->resourceSingularlName = 'vehicle_construction_meta';
        $this->resourcePluralName    = 'vehicle_construction_metas';
    }
}
