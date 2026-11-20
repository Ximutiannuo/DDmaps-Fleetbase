export function initialize(application) {
    const leafletService = application.lookup('service:leaflet');
    if (leafletService) {
        leafletService.load({
            onReady: function (L) {
                // This will prevent the awkward scroll bug produced by Chrome browsers
                // https://github.com/Leaflet/Leaflet/issues/4125#issuecomment-356289643
                L.Control.include({
                    _refocusOnMap: L.Util.falseFn,
                });

                // Ensure zoom animation is enabled by default for all maps
                L.Map.mergeOptions({
                    zoomAnimation: true,
                    fadeAnimation: true,
                    markerZoomAnimation: true,
                });
            },
        });
    }
}

export default {
    name: 'load-leaflet',
    initialize,
};
