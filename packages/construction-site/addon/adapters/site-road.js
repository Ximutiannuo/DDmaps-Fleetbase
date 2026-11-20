import ConstructionSiteAdapter from './construction-site';

export default class SiteRoadAdapter extends ConstructionSiteAdapter {
    pathForType() {
        return 'roads';
    }
}
