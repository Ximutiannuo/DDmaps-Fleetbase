import { ExtensionComponent } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('universe/menu-service');

        menuService.registerHeaderMenuItem('Construction Site', 'console.construction-site', {
            icon: 'road',
            priority: 1,
            description: 'Construction site, road, and crew management.',
        });
    },
};
