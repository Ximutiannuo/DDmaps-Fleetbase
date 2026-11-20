import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('dashboard', { path: '/' });
    this.route('live-map');
    this.route('roads', function () {
        this.route('index', { path: '/' });
        this.route('new');
        this.route('edit', { path: '/:public_id' });
    });
    this.route('road-conditions');
    this.route('road-events');
    this.route('construction');
    this.route('vehicles');
    this.route('notifications');
    this.route('mobile', function () {
        this.route('track', { path: '/track' });
    });
});
