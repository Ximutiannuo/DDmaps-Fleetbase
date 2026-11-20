import { helper } from '@ember/component/helper';
const isFastBoot = typeof FastBoot !== 'undefined';
/* global L */

const DEFAULT_ICON_URL = '/engines-dist/images/map-marker-bfdcdde0240ca99b120a679438399c69.png';

export const icon = isFastBoot
  ? function () {}
  : function icon(_, hash) {
      // https://github.com/emberjs/ember.js/issues/14668
      let options = Object.assign({}, hash);
      if (!options.iconUrl) {
          options.iconUrl = DEFAULT_ICON_URL;
      }
      return L.icon(options);
    };

export default helper(icon);
