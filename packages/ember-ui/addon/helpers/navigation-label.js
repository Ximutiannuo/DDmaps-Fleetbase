import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';
import translateNavigationLabel from '../utils/translate-navigation-label';

export default class NavigationLabelHelper extends Helper {
    @service intl;

    compute([label]) {
        return translateNavigationLabel(this.intl, label);
    }
}
