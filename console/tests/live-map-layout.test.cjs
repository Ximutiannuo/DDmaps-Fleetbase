const {readFileSync}=require('node:fs');
const {resolve}=require('node:path');
const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
function load(file,name,globals={}){
 const source=readFileSync(resolve(__dirname,'../../packages/construction-site/addon/'+file),'utf8').replace(/^import .*;\r?\n/gm,'').replace(/@service \w+;/g,'').replace(/@tracked /g,'').replace(/@action /g,'').replace('export default class '+name,'class '+name);
 const ctx=vm.createContext({Controller:class{},Component:class{},Date,Set,setTimeout,clearTimeout,setInterval,clearInterval,console,...globals});vm.runInContext(source+'\nthis.Result='+name,ctx);return new ctx.Result();
}
function controller(){const c=load('controllers/live-map.js','LiveMapController');c.notifications={info(){}};return c;}
test('search matches road names and source IDs and combines with congestion/status filters',()=>{
 const c=controller();c.trafficRoads=[{name:'进场主路',source_meta:{way_id:1181140403},status:'construction',congestion:'none'},{name:'出场路',status:'open',congestion:'heavy'}];
 c.trafficSearch='1181140403';assert.equal(c.filteredTrafficRoads.length,1);c.trafficStatusFilter='open';assert.equal(c.filteredTrafficRoads.length,0);
 c.trafficSearch='';c.trafficStatusFilter='congested';assert.equal(c.filteredTrafficRoads[0].name,'出场路');
});
test('tab switching and collapsing preserve an unsaved traffic form and import selection',()=>{
 const c=controller();c.selectTraffic({target_key:'road:1',status:'open'});c.changeTrafficField('status',{target:{value:'closed'}});const form=c.trafficForm;
 c.roadImportPreview={candidates:[]};c.roadImportSelectedKeys=['one'];c.setActivePanel('import');assert.equal(c.isImportSelectionMode,true);c.togglePanel();c.setActivePanel('traffic');assert.equal(c.panelCollapsed,false);assert.equal(c.isImportSelectionMode,false);assert.equal(c.trafficForm,form);assert.equal(c.roadImportSelectedKeys[0],'one');
});
test('map clicks open the panel without requesting viewport movement; list selection explicitly locates',()=>{
 const c=controller();c.panelCollapsed=true;c.activePanel='drivers';const road={target_key:'road:1',geometry:{type:'LineString',coordinates:[[28,59],[29,59]]}};
 c.selectTraffic(road);assert.equal(c.activePanel,'traffic');assert.equal(c.panelCollapsed,false);assert.equal(c.focusRequest,0);
 c.locateTraffic(road);assert.equal(c.focusRequest,1);assert.equal(c.focusTarget,road.geometry);
});
test('polling updates observations but retains the in-progress form and focus counter',async()=>{
 const c=controller();c.selectedServiceAreaUuid='gcc';c.selectTraffic({target_key:'road:1',status:'open'});c.changeTrafficField('note',{target:{value:'待核实'}});const form=c.trafficForm;
 c.fetch={async get(){return {road_segments:[{target_key:'road:1',status:'partial'}],generated_at:new Date().toISOString()};}};
 await c.loadTraffic();assert.equal(c.trafficForm,form);assert.equal(c.trafficForm.note,'待核实');assert.equal(c.selectedTrafficInfo.status,'partial');assert.equal(c.focusRequest,0);
});
test('driver selection joins navigation by entry UUID and uses GPS as the focus location',()=>{
 const c=controller();const route={coordinates:[[59,28],[59.1,28.1]]};c.driverLocations=[{entry_uuid:'driver-1',lat:59.2,lng:-135.8,plate_number:'123'}];c.navigatingEntries=[{uuid:'driver-1',active_route:route},{uuid:'driver-2',active_route:route}];
 assert.equal(c.driverItems.length,2);assert.equal(c.driverItems[1].hasLocation,false);c.selectDriver(c.driverItems[0]);assert.equal(c.selectedDriverRoute,route);assert.equal(c.focusTarget.coordinates[0],-135.8);c.clearDriverSelection();assert.equal(c.selectedDriverRoute,undefined);
});
function map(){const added=[],removed=[],panes={};const layer=(points,options)=>({points,options,addTo(){added.push(this);return this;},bindTooltip(){},setLatLng(p){this.points=p;}});
 const c=load('components/live-map-view.js','LiveMapViewComponent',{L:{polyline:layer,marker:layer,divIcon:o=>o},document:{createElement:()=>({})}});c.args={};c.mapInstance={getPane:n=>panes[n],createPane:n=>(panes[n]={style:{}}),removeLayer:l=>removed.push(l)};return {c,added,removed,panes};}
test('navigation stays above road status while hidden driver positions are actually removed',()=>{
 const {c,removed,panes}=map();c.args={driverLocations:[{entry_uuid:'one',lat:59,lng:28},{entry_uuid:'one',lat:59.1,lng:28.1}],selectedDriverRoute:{coordinates:[[59,28],[59.1,28.1]]}};c.drawNavigatingRoutes();assert.equal(c.routeLayers.length,3);assert.equal(panes.driverNavigation.style.zIndex,550);
 c.args.showDriverRoutes=false;c.drawNavigatingRoutes();assert.equal(removed.length,1);assert.equal(c.routeLayers.length,2);c.args.showNavigationLayer=false;c.drawNavigatingRoutes();assert.equal(c.routeLayers.length,0);
});
test('a navigation origin is never shown as a current driver position',()=>{
 const {c}=map();c.args={navigatingEntries:[{active_route:{coordinates:[[59,28],[59.1,28.1]]}}],driverLocations:[]};c.drawNavigatingRoutes();assert.equal(c.routeLayers.length,0);
});
test('hiding status layers clears existing paths without resetting the viewport',()=>{
 const {c,removed}=map();c.segmentLayers=[{road:true}];c.junctionLayers=[{junction:true}];c.avoidLayers=[{avoid:true}];c.args={showRoadLayer:false,showJunctionLayer:false,showAvoidLayer:false};c.mapInstance.setView=()=>{throw Error('unexpected viewport reset');};c.drawSegments();c.drawJunctions();c.drawAvoidAreas();assert.equal(removed.length,3);
});

test('GPS updates move existing markers and preserve unchanged navigation paths',()=>{
 const {c,added,removed}=map();c.args={driverLocations:[{entry_uuid:'one',lat:59,lng:28}],selectedDriverRoute:{coordinates:[[59,28],[59.1,28.1]]}};
 c.drawNavigatingRoutes();const marker=c.driverMarkers.get('one');const paths=[...c.navigationPaths];
 c.args.driverLocations=[{entry_uuid:'one',lat:59.01,lng:28.01}];c.drawNavigatingRoutes();
 assert.equal(c.driverMarkers.get('one'),marker);assert.equal(marker.points[0],59.01);assert.equal(c.navigationPaths[0],paths[0]);assert.equal(added.length,3);assert.equal(removed.length,0);
});
