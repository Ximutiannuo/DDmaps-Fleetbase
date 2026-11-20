const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const source = readFileSync(resolve(__dirname, '../../packages/construction-site/addon/components/live-map-view.js'), 'utf8')
    .replace(/^import .*;\r?\n/gm, '').replace(/@tracked /g, '').replace(/@action /g, '')
    .replace('export default class LiveMapViewComponent', 'class LiveMapViewComponent');
function map(road) {
    const layers = []; const removed = [];
    const layer = (coords, options) => ({ coords, options, handlers: {}, addTo() { layers.push(this); return this; },
        bindTooltip(tooltip) { this.tooltip = tooltip.textContent; }, on(event, handler) { this.handlers[event] = handler; } });
    const L = { polyline: layer, circleMarker: layer, marker: layer, divIcon: (options) => options, DomEvent: { stopPropagation() {} } };
    const context = vm.createContext({ Component: class {}, L, document: { createElement: () => ({}) } });
    vm.runInContext(source + '\n globalThis.ImportMap = LiveMapViewComponent;', context);
    const component = new context.ImportMap(); let selected;
    component.mapInstance = { getPane: () => ({}), removeLayer(item) { removed.push(item); } };
    component.args = { importCandidates: [road], onSelectImportRoad(candidate) { selected = candidate; } };
    component.drawImportPreview();
    return { component, layers, removed, selected: () => selected };
}
function candidate(options = {}) {
    return { key: 'private', name: 'Private service road', length_meters: 100, availability: 'new',
        availability_label: '需核实通行权限', restricted: true, selected: false, routing: {direction:0},
        geometry: { type: 'LineString', coordinates: [[-135.73, 60.6], [-135.72, 60.6]] }, ...options };
}
test('restricted roads turn purple when selected and remain orange only while unselected', () => {
    assert.equal(map(candidate()).layers[1].options.color, '#ea580c');
    const selected = map(candidate({selected:true}));
    assert.equal(selected.layers[1].options.color, '#7c3aed');
    assert.match(selected.layers[0].tooltip, /已勾选，点击取消/);
});
test('the solid wide hit area handles clicks across visual dash gaps', () => {
    const road = candidate(); const result = map(road); const hit = result.layers[0];
    assert.equal(hit.options.dashArray, undefined);
    assert.equal(hit.options.opacity, 0); assert.ok(hit.options.weight >= 16);
    assert.equal(hit.options.bubblingMouseEvents, false);
    assert.equal(result.layers[1].options.interactive, false);
    hit.handlers.click({}); assert.equal(result.selected(), road);
});
test('overlapping candidates are visually distinct and can be clicked to explain the restriction', () => {
    const road = candidate({availability:'overlap',selected:true,availability_label:'与已有道路重叠'});
    const result = map(road);
    assert.equal(result.layers[1].options.color, '#9ca3af');
    assert.equal(result.layers[1].options.dashArray, '3 7');
    assert.match(result.layers[0].tooltip, /不可重复导入.*与已有道路重叠/);
    result.layers[0].handlers.click({}); assert.equal(result.selected(), road);
});
test('redrawing selection removes both old visual and hit layers', () => {
    const result = map(candidate());
    result.component.args.importCandidates = [candidate({selected:true,routing:{direction:1}})];
    result.component.drawImportPreview();
    assert.equal(result.removed.length, 2); assert.equal(result.component.importLayers.length, 3);
    assert.match(result.component.importLayers[2].options.icon.html, /color:#7c3aed/);
});

test('neighboring wide hit areas select the road closest to the cursor, not the last drawn path', () => {
    const road = candidate(); const result = map(road);
    const neighbor = candidate({key:'neighbor', availability:'overlap',geometry:{type:'LineString',coordinates:[[-135.73,60.6001],[-135.72,60.6001]]}});
    result.component.args.importCandidates = [road, neighbor]; result.component.drawImportPreview();
    result.component.importLayers[2].handlers.click({latlng:{lat:60.6,lng:-135.725}});
    assert.equal(result.selected(), road);
    result.component.importLayers[0].handlers.click({latlng:{lat:60.6001,lng:-135.725}});
    assert.equal(result.selected(), neighbor);
});

test('exactly overlapping previews prefer an eligible road over a disabled candidate', () => {
    const eligible = candidate(); const disabled = candidate({key:'disabled',availability:'overlap'});
    const result = map(eligible);
    result.component.args.importCandidates = [eligible, disabled]; result.component.drawImportPreview();
    result.component.importLayers[2].handlers.click({latlng:{lat:60.6,lng:-135.725}});
    assert.equal(result.selected(), eligible);
});


test('traffic roads have continuous hit areas and choose the nearest section under the cursor', () => {
    const result=map(candidate()); let selected;
    const sections=[0,1].map(i=>({...candidate({key:'section:'+i}),target_key:'section:'+i,
        geometry:{type:'LineString',coordinates:[[-135.73+i*.01,60.6],[-135.72+i*.01,60.6]]}}));
    result.component.mapInstance.fitBounds=()=>{}; result.component.hasFittedBounds=true;
    result.component.args={trafficRoads:sections,onSelectTraffic:road=>selected=road};
    result.component.drawSegments();
    const hits=result.component.segmentLayers.filter(l=>l.options.opacity===0);
    assert.equal(hits.length,2); assert.equal(hits[0].options.pane,'trafficRoads');
    assert.equal(hits[0].options.bubblingMouseEvents,false);
    hits[0].handlers.click({latlng:{lat:60.6,lng:-135.715}}); assert.equal(selected.target_key,'section:1');
    result.component.drawMode='LineString'; hits[0].handlers.click({latlng:{lat:60.6,lng:-135.725}});
    assert.equal(selected.target_key,'section:1');
});

test('junctions use a pane above previews and open their own traffic editor', () => {
    const result=map(candidate()); let selected; const panes={};
    result.component.mapInstance.getPane=name=>panes[name];
    result.component.mapInstance.createPane=name=>(panes[name]={style:{}});
    const junction={target_key:'junction:1',latitude:60.6,longitude:-135.725,name:'Intersection'};
    result.component.args={junctions:[junction],onSelectJunction:item=>selected=item}; result.component.drawJunctions();
    assert.ok(panes.trafficJunctions.style.zIndex>475);
    assert.equal(result.component.junctionLayers[0].options.bubblingMouseEvents,false);
    result.component.junctionLayers[0].handlers.click({}); assert.equal(selected,junction);
});
