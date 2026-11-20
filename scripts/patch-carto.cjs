const fs = require('node:fs');
const path = require('node:path');
const secret = '/run/secrets/carto_api_key';
const key = fs.existsSync(secret) ? fs.readFileSync(secret, 'utf8').trim() : '';
const osmTileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const osmAttribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>';
const cartoTileUrl = /https:\/\/[^\s'"`]*basemaps\.cartocdn\.com[^\s'"`]*\.png(?!\?)/g;
const roots = new Set();
let changed = 0;
function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(file);
        else if (entry.isFile() && /\.(js|mjs|hbs)$/.test(file)) {
            const source = fs.readFileSync(file, 'utf8');
            const next = source.replace(cartoTileUrl, (url) => key ? `${url}?key=${encodeURIComponent(key)}` : osmTileUrl);
            if (next !== source) { fs.writeFileSync(file, next); changed++; }
        }
    }
}
for (const packageDir of fs.readdirSync('node_modules/.pnpm')) {
    const namespace = path.join('node_modules/.pnpm', packageDir, 'node_modules/@fleetbase');
    if (!fs.existsSync(namespace)) continue;
    for (const name of fs.readdirSync(namespace)) {
        const addon = path.join(namespace, name, 'addon');
        if (!fs.existsSync(addon)) continue;
        const real = fs.realpathSync(addon);
        if (!roots.has(real)) { roots.add(real); walk(real); }
    }
}
if (!changed) throw new Error('Basemap patch matched no upstream CARTO tile URL; inspect upstream changes');

if (!key) {
    const tileLayer = 'node_modules/ember-leaflet/addon/components/tile-layer.js';
    const source = fs.readFileSync(tileLayer, 'utf8');
    const createTileLayer = 'return this.L.tileLayer(...this.requiredOptions, this.options);';
    if (!source.includes(createTileLayer)) throw new Error('Inspect the installed Ember Leaflet tile layer before adding OpenStreetMap attribution');
    const createAttributedTileLayer = [
        'const [url] = this.requiredOptions;',
        '    const options = this.options;',
        `    if (url === ${JSON.stringify(osmTileUrl)}) {`,
        `      options.attribution = ${JSON.stringify(osmAttribution)};`,
        '      options.maxNativeZoom = 19;',
        '      options.maxZoom ??= 19;',
        '    }',
        '    return this.L.tileLayer(url, options);',
    ].join('\n');
    fs.writeFileSync(tileLayer, source.replace(createTileLayer, createAttributedTileLayer));
}

console.log(`${key ? 'CARTO' : 'OpenStreetMap'} basemap configured in ${changed} files`);
