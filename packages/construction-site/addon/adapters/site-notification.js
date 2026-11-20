import ConstructionSiteAdapter from './construction-site';

export default class SiteNotificationAdapter extends ConstructionSiteAdapter {
    pathForType() {
        return 'notifications';
    }
}
