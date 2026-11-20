const {readFileSync}=require('node:fs');
const {resolve}=require('node:path');
const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const source=readFileSync(resolve(__dirname,'../../packages/construction-site/addon/components/live-map-view.js'),'utf8').replace(/^import .*;\r?\n/gm,'').replace(/@tracked /g,'').replace(/@action /g,'').replace('export default class LiveMapViewComponent','class LiveMapViewComponent');
function component({reducedMotion=false}={}) {
 const frames=new Map();let id=0;
 const ctx=vm.createContext({Component:class{willDestroy(){}},clearTimeout,window:{matchMedia:()=>({matches:reducedMotion})},
  requestAnimationFrame:fn=>{frames.set(++id,fn);return id;},cancelAnimationFrame:n=>frames.delete(n)});
 vm.runInContext(source+'\nthis.Result=LiveMapViewComponent;',ctx);
 const c=new ctx.Result();c.frame=now=>{const callbacks=[...frames.values()];frames.clear();callbacks.forEach(fn=>fn(now));};c.frames=frames;
 return c;
}
function map(c,initial=0) {
 let bearing=initial;const updates=[];
 c.mapInstance={getBearing:()=>bearing,setBearing:value=>{bearing=value;updates.push(value);c.mapBearing=value;},
 setView(){throw Error('rotation must not recenter');},setZoom(){throw Error('rotation must not zoom');}};
 return {updates,get bearing(){return bearing;}};
}
function pointerElement(){let captured=null;return {setPointerCapture:id=>captured=id,hasPointerCapture:id=>captured===id,releasePointerCapture:()=>captured=null,get captured(){return captured;}};}
function pointer(element,type,x,id=1){return {currentTarget:element,type,clientX:x,button:0,pointerId:id,preventDefault(){}};}

test('continuous slider keeps fractional precision and commits the latest input once per frame',()=>{
 const c=component(),m=map(c);
 c.changeMapBearing({target:{value:'17.125'}});c.changeMapBearing({target:{value:'28.378'}});
 assert.equal(m.updates.length,0);assert.equal(c.frames.size,1);c.frame(0);
 assert.ok(Math.abs(m.bearing-28.378)<1e-10);assert.equal(m.updates.length,1);assert.equal(c.bearingLabel,'28.4');
 c.queueBearing(-0.375);c.frame(16);assert.equal(m.bearing,359.625);
 c.queueBearing(720.0625);c.frame(32);assert.equal(m.bearing,0.0625);
});
test('captured drag is continuous across north, accepts subpixel motion, and commits the release position',()=>{
 const c=component(),m=map(c,359.5),element=pointerElement();
 c.startRotationDrag(pointer(element,'pointerdown',100));assert.equal(element.captured,1);assert.equal(c.isRotating,true);
 c.moveRotationDrag(pointer(element,'pointermove',101.25));c.moveRotationDrag(pointer(element,'pointermove',200,2));c.frame(0);
 assert.equal(m.bearing,0.25);
 c.endRotationDrag(pointer(element,'pointerup',102.5));assert.equal(element.captured,null);assert.equal(c.isRotating,false);c.frame(16);assert.equal(m.bearing,1);
 c.moveRotationDrag(pointer(element,'pointermove',300));assert.equal(c.frames.size,0);
});
test('cancelled drag releases capture and further moves are ignored',()=>{
 const c=component(),m=map(c,30),element=pointerElement();c.startRotationDrag(pointer(element,'pointerdown',50));
 c.moveRotationDrag(pointer(element,'pointermove',80));c.endRotationDrag(pointer(element,'pointercancel',80));c.frame(0);
 assert.equal(m.bearing,48);assert.equal(element.captured,null);assert.equal(c.isRotating,false);
 c.moveRotationDrag(pointer(element,'pointermove',100));assert.equal(c.frames.size,0);
});
test('north reset animates along the shortest direction and can be interrupted by slider input',()=>{
 const c=component(),m=map(c,350);c.resetMapBearing();c.frame(0);c.frame(160);
 assert.ok(m.bearing>350 && m.bearing<360);c.frame(320);assert.equal(m.bearing,0);assert.equal(c.frames.size,0);
 c.queueBearing(80);c.frame(400);c.resetMapBearing();c.frame(416);c.frame(480);assert.ok(m.bearing>0 && m.bearing<80);
 c.changeMapBearing({target:{value:'123.456'}});c.frame(500);c.frame(800);assert.ok(Math.abs(m.bearing-123.456)<1e-10);assert.equal(c.frames.size,0);
});
test('reduced motion resets immediately and invalid input cannot rotate the map',()=>{
 const c=component({reducedMotion:true}),m=map(c,95.25);c.resetMapBearing();assert.equal(m.bearing,0);assert.equal(c.frames.size,0);
 c.changeMapBearing({target:{value:'invalid'}});assert.equal(c.frames.size,0);assert.equal(m.bearing,0);
});
test('keyboard supports adjusting and resetting bearing without requiring a mouse',()=>{
 const c=component(),m=map(c,359.5);c.rotateWithKeyboard({key:'ArrowRight',preventDefault(){}});c.frame(0);assert.equal(m.bearing,0.5);
 c.rotateWithKeyboard({key:'ArrowLeft',shiftKey:true,preventDefault(){}});c.frame(16);assert.equal(m.bearing,350.5);
 c.rotateWithKeyboard({key:'Home',preventDefault(){}});c.frame(32);c.frame(352);assert.equal(m.bearing,0);
});
test('component destruction cancels outstanding animation frames and releases the active pointer',()=>{
 const c=component(),element=pointerElement();map(c);let removed=false;
 Object.assign(c.mapInstance,{off(){},remove(){removed=true;}});
 for(const method of ['teardownDrawHandler','clearSegmentLayers','clearJunctionLayers','clearAvoidLayers','clearRouteLayers','clearImportLayers','clearDraftLayer','clearEditLayer'])c[method]=()=>{};
 c.startRotationDrag(pointer(element,'pointerdown',50));c.moveRotationDrag(pointer(element,'pointermove',70));c.willDestroy();
 assert.equal(element.captured,null);assert.equal(c.frames.size,0);assert.equal(c.mapInstance,null);assert.equal(removed,true);c.frame(16);
});
test('custom vector panes rotate together; direction markers remain in the marker coordinate system',()=>{
 const c=component(),panes={rotatePane:{name:'rotating'},norotatePane:{name:'markers'}},created=[];
 c.mapInstance={getPane:n=>panes[n],createPane:(name,parent)=>{created.push({name,parent});return panes[name]={style:{}};}};
 c.ensureMapPane('trafficRoads',450);c.ensureMapPane('trafficJunctions',490);c.ensureMapPane('driverNavigation',550);c.ensureMapPane('importDirection',475,false);
 assert.equal(created[0].parent,panes.rotatePane);assert.equal(created[1].parent,panes.rotatePane);assert.equal(created[2].parent,panes.rotatePane);assert.equal(created[3].parent,panes.norotatePane);assert.ok(panes.driverNavigation.style.zIndex>panes.trafficRoads.style.zIndex);
 c.ensureMapPane('trafficRoads',450);assert.equal(created.length,4);
});
test('routine refreshes and selection do not discard the user bearing or mutate road geometry',()=>{
 const c=component();let bearing=75;c.args={focusTarget:{type:'Point',coordinates:[28.27,59.6]}};c.mapInstance={getBearing:()=>bearing,setBearing:b=>bearing=b,getZoom:()=>14,setView(){}};
 for(const method of ['drawSegments','drawJunctions','drawAvoidAreas','drawNavigatingRoutes','drawImportPreview'])c[method]=()=>{};
 c.redrawAll();c.focusSelection();assert.equal(bearing,75);
 const road={geometry:{type:'LineString',coordinates:[[28.27,59.6],[28.28,59.61]]}},original=JSON.stringify(road);c.segmentLatLngs(road);c.queueBearing(83.625);c.frame(0);assert.equal(JSON.stringify(road),original);
});

test('loaded Ember road collections cannot interrupt roads, junctions or import preview rendering',()=>{
 const c=component(),store={};store.notifications={store};
 const record={id:'saved-road',geometry:{type:'LineString',coordinates:[[28.27,59.6],[28.28,59.61]]},store};
 const records={store,find:predicate=>[record].find(predicate),toJSON(){throw Error('Ember collection must not be serialized');}};
 c.args={segments:records,trafficRoads:[{target_key:'section:saved',geometry:record.geometry}],importCandidates:[]};
 const calls={};
 for(const method of ['drawSegments','drawJunctions','drawAvoidAreas','drawNavigatingRoutes','drawImportPreview'])c[method]=()=>{calls[method]=(calls[method]||0)+1;};
 assert.doesNotThrow(()=>c.redrawAll());
 assert.equal(calls.drawSegments,1);assert.equal(calls.drawJunctions,1);assert.equal(calls.drawImportPreview,1);
 c.redrawAll();assert.equal(calls.drawSegments,1);
 c.args.importPreview={preview_token:'preview-1'};
 c.args.importCandidates=[{key:'osm:new',geometry:record.geometry,selected:true}];
 c.redrawAll();assert.equal(calls.drawImportPreview,2);assert.equal(calls.drawSegments,1);
 c.args.selectedSegmentUuid=record.id;c.redrawAll();assert.equal(calls.drawSegments,2);
 record.geometry.coordinates[1]=[28.29,59.62];c.redrawAll();assert.equal(calls.drawSegments,3);
});
